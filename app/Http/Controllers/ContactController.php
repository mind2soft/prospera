<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessage;
use App\Models\ContactSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Maximum lengths, mirrored client-side (issue #17).
     */
    private const SUBJECT_MAX = 100;

    private const MESSAGE_MAX = 4000;

    /**
     * Render the contact form (guests and authenticated users).
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Contact', [
            'email' => $request->user()?->email,
        ]);
    }

    /**
     * Acknowledgement page shown after a submission was queued.
     */
    public function sent(): Response
    {
        return Inertia::render('ContactSent');
    }

    /**
     * Handle a submission. Works for guests (session + CSRF) and signed-in
     * users; the signed-in account email always wins over the (read-only)
     * form field.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->user()) {
            $request->merge(['email' => $request->user()->email]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'subject' => ['required', 'string', 'max:'.self::SUBJECT_MAX],
            'message' => ['required', 'string', 'max:'.self::MESSAGE_MAX],
        ], [
            'email.required' => __('Please enter your email address.'),
            'email.email' => __('Please enter a valid email address.'),
            'subject.required' => __('Please enter a subject.'),
            'subject.max' => __('The subject must not exceed :max characters.'),
            'message.required' => __('Please enter a message.'),
            'message.max' => __('The message must not exceed :max characters.'),
        ]);

        $subject = self::sanitize($validated['subject']);
        $message = self::sanitize($validated['message']);

        if (blank($subject)) {
            throw ValidationException::withMessages([
                'subject' => __('Please enter a subject.'),
            ]);
        }

        if (blank($message)) {
            throw ValidationException::withMessages([
                'message' => __('Please enter a message.'),
            ]);
        }

        // The client IP is required for the cooldown and the submission log.
        // It is resolved by Laravel (honoring trusted proxies only — see
        // TrustProxies); a blank address means the request carried none.
        $ip = (string) $request->ip();

        if (blank($ip)) {
            throw ValidationException::withMessages([
                'error' => __('Your request could not be processed. Please try again later.'),
            ]);
        }

        if (ContactSubmission::onCooldownFor($ip)) {
            throw ValidationException::withMessages([
                'error' => __('You already contacted us recently. Please wait before submitting again.'),
            ]);
        }

        $user = $request->user();

        // The log entry drives the cooldown: it must only exist once the mail
        // is queued, or a failed dispatch would block the resend. With the
        // database queue, both inserts commit (or roll back) together.
        DB::transaction(function () use ($validated, $subject, $message, $ip, $user): void {
            ContactSubmission::create([
                'email' => $validated['email'],
                'subject' => $subject,
                'message' => $message,
                'ip_address' => $ip,
                'user_id' => $user?->id,
            ]);

            Mail::to(config('mail.contact_address'))
                ->queue(new ContactMessage(
                    contactEmail: $validated['email'],
                    contactSubject: $subject,
                    contactMessage: $message,
                    accountName: $user?->name,
                    accountId: $user?->id,
                    senderLocale: $user->locale ?? app()->getLocale(),
                ));
        });

        return response()->json(['redirect' => route('contact.sent')]);
    }

    /**
     * Strip all HTML/XML, trim and normalize whitespace — plain text only,
     * rendered as plain text in the mail body (issue #17).
     */
    private static function sanitize(string $value): string
    {
        // Decode entities so `&lt;script&gt;` does not resurrect as markup
        // downstream, then drop any tags and collapse whitespace.
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded);

        return trim(preg_replace('/[[:space:]]+/u', ' ', $stripped) ?? '');
    }
}
