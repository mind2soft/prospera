<?php

namespace App\Http\Controllers;

use App\Mail\ProfileInvite;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinancialProfileInviteController extends Controller
{
    /**
     * Invite landing page: renders the registration page with the invite
     * context resolved from the path key. Invalid or expired keys render the
     * registration page without invite context and flash an explicit error.
     */
    public function landing(Request $request, string $inviteKey): Response
    {
        $invite = FinancialProfileInvite::findByKey($inviteKey);

        $context = null;

        if ($invite === null || $invite->isReserved()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This invite is invalid.')]);
        } elseif ($invite->isExpired()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This invite has expired.')]);
        } else {
            $context = $invite->context();
        }

        return Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'invite' => $context,
        ]);
    }

    /**
     * List the profile's pending (non-expired) invites (owner only).
     */
    public function index(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile, ownerOnly: true);

        $invites = FinancialProfileInvite::query()
            ->where('profile_id', $profile->id)
            ->notExpired()
            ->orderBy('created_at')
            ->get()
            ->map(fn (FinancialProfileInvite $invite) => $this->serialize($invite))
            ->values();

        return response()->json(['invites' => $invites]);
    }

    /**
     * Create an invite (owner only) — returns the invite link. Invites expire
     * after 48 hours. Re-inviting an email replaces its previous unreserved
     * invite (the raw link key is never stored, so this is how a lost link
     * is re-issued; each share event sends a fresh email); existing members
     * cannot be invited.
     */
    public function store(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile, ownerOnly: true);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in(FinancialProfileInvite::GRANTABLE_ROLES)],
        ]);

        $alreadyMember = $profile->members()
            ->whereRaw('lower(users.email) = ?', [mb_strtolower($validated['email'])])
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => __('This user is already a member of the profile.'),
            ]);
        }

        $rawKey = FinancialProfileInvite::generateKey();

        $invite = DB::transaction(function () use ($profile, $validated, $rawKey, $request): FinancialProfileInvite {
            FinancialProfileInvite::query()
                ->where('profile_id', $profile->id)
                ->whereRaw('lower(email) = ?', [mb_strtolower($validated['email'])])
                ->whereNull('pending_user_id')
                ->delete();

            return FinancialProfileInvite::create([
                'profile_id' => $profile->id,
                'invite_key' => FinancialProfileInvite::keyHash($rawKey),
                'email' => $validated['email'],
                'role' => $validated['role'] ?? FinancialProfileInvite::ROLE_COLLABORATOR,
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addHours(48),
            ]);
        });

        // One email per share event, queued after the transaction commits.
        $this->notifyInvitee($invite, $rawKey);

        return response()->json([
            'invite' => $this->serialize($invite),
            'url' => '/invites/'.$rawKey,
        ], 201);
    }

    /**
     * Revoke a pending invite (owner only).
     */
    public function destroy(Request $request, FinancialProfile $profile, FinancialProfileInvite $invite): JsonResponse
    {
        $this->authorizeProfile($request, $profile, ownerOnly: true);
        abort_unless($invite->profile_id === $profile->id, 404);

        $invite->delete();

        return response()->json(status: 204);
    }

    /**
     * Validate an invite key (public — used by the invite landing page and
     * the registration flow). Returns the profile and inviter names.
     */
    public function show(string $inviteKey): JsonResponse
    {
        $invite = FinancialProfileInvite::findByKey($inviteKey);

        abort_if($invite === null || $invite->isReserved(), 404);
        abort_if($invite->isExpired(), 410);

        return response()->json(['invite' => $invite->context()]);
    }

    /**
     * Accept an invite (authenticated user; the invite email must match the
     * account email; expired invites and existing members are rejected; the
     * invite is consumed on success).
     *
     * The invite and membership are re-verified under a lock inside the
     * transaction so two concurrent accepts cannot both succeed; the unique
     * constraint on financial_profile_user maps any remaining race to the doc's
     * validation error.
     */
    public function accept(Request $request, string $inviteKey): JsonResponse
    {
        abort_if(FinancialProfileInvite::findByKey($inviteKey) === null, 404);

        $profile = $this->grantMembership($inviteKey, $request->user());

        return response()->json([
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_owner' => false,
            ],
        ]);
    }

    /**
     * One-click accept from the invitation email (issue #39): the signed-in
     * user whose account email matches the invite is accepted straight
     * through and lands on the dashboard. Guests are sent to the sign-in
     * page with the accept URL as the intended destination (they complete
     * the accept after signing in), unverified users are redirected to the
     * verification notice by the `verified` middleware, and every other
     * case (unknown, reserved, expired, email mismatch, already a member)
     * falls back to the invite landing page, behaving exactly as opening
     * the plain invite link today.
     */
    public function acceptViaLink(Request $request, string $inviteKey): RedirectResponse
    {
        $invite = FinancialProfileInvite::findByKey($inviteKey);

        if ($invite === null || $invite->isReserved() || $invite->isExpired()) {
            // The landing page re-resolves the key and flashes the
            // appropriate error for each of these states.
            return redirect()->route('invites.landing', ['inviteKey' => $inviteKey]);
        }

        try {
            $profile = $this->grantMembership($inviteKey, $request->user());
        } catch (ValidationException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->errors()['invite_key'][0] ?? __('This invite is invalid.'),
            ]);

            return redirect()->route('invites.landing', ['inviteKey' => $inviteKey]);
        } catch (HttpException $exception) {
            // The invite vanished between the pre-check and the locked
            // re-verification (a concurrent accept won) — same fallback.
            return redirect()->route('invites.landing', ['inviteKey' => $inviteKey]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You joined the profile ":profile."', ['profile' => $profile->name]),
        ]);

        return redirect()->route('dashboard');
    }

    /**
     * Grant the membership carried by the invite to the given user inside a
     * locked transaction, consuming the invite. Shared by the JSON accept
     * endpoint and the one-click accept link.
     */
    private function grantMembership(string $inviteKey, User $user): FinancialProfile
    {
        try {
            return DB::transaction(function () use ($inviteKey, $user): FinancialProfile {
                /** @var FinancialProfileInvite|null $invite */
                $invite = FinancialProfileInvite::query()
                    ->where('invite_key', FinancialProfileInvite::keyHash($inviteKey))
                    ->lockForUpdate()
                    ->first();

                abort_if($invite === null, 404);

                $error = $invite->usageError($user->email);

                if ($error !== null) {
                    throw ValidationException::withMessages(['invite_key' => __($error)]);
                }

                $profile = $invite->profile;

                if ($profile->isMember($user)) {
                    throw ValidationException::withMessages([
                        'invite_key' => __('You are already a member of this profile.'),
                    ]);
                }

                $profile->members()->attach($user->id, $invite->membershipPivotAttributes());
                $invite->consume();

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'invite_key' => __('You are already a member of this profile.'),
            ]);
        }
    }

    /**
     * The stored invite_key (a hash) is deliberately not exposed.
     *
     * @return array<string, string|int|bool>
     */
    private function serialize(FinancialProfileInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'email' => $invite->email,
            'role' => $invite->role,
            'awaiting_verification' => $invite->isReserved(),
            'expires_at' => $invite->expires_at->toISOString(),
        ];
    }

    /**
     * Queue the invitation email for the invitee (one per share event,
     * docs/notifications.md conventions: queued, localized per recipient
     * with an `en` fallback). A registered account gets the informational
     * variant with the one-click accept link; any other address gets the
     * account-creation invite. Registered recipients are gated by their
     * `profile_invite` notification toggle (re-checked at send time inside
     * the mailable).
     */
    private function notifyInvitee(FinancialProfileInvite $invite, string $rawKey): void
    {
        $recipient = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($invite->email)])
            ->first();

        if ($recipient !== null
            && ! $recipient->wantsNotification(NotificationSettings::PROFILE_INVITE)) {
            return;
        }

        Mail::to($invite->email)->queue(new ProfileInvite(
            inviterName: $invite->invitedBy->name,
            inviterEmail: $invite->invitedBy->email,
            profileName: $invite->profile->name,
            role: $invite->role,
            inviteUrl: route('invites.landing', ['inviteKey' => $rawKey]),
            acceptUrl: $recipient === null
                ? null
                : route('invites.accept-link', ['inviteKey' => $rawKey]),
            expiresAt: $invite->expires_at,
            recipientUserId: $recipient === null ? null : $recipient->id,
            locale: $recipient === null ? 'en' : ($recipient->locale ?? 'en'),
        ));
    }
}
