<?php

namespace App\Http\Controllers;

use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
     * is re-issued); existing members cannot be invited.
     */
    public function store(Request $request, FinancialProfile $profile): JsonResponse
    {
        $this->authorizeProfile($request, $profile, ownerOnly: true);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
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
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addHours(48),
            ]);
        });

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
        $user = $request->user();

        abort_if(FinancialProfileInvite::findByKey($inviteKey) === null, 404);

        try {
            $profile = DB::transaction(function () use ($inviteKey, $user): FinancialProfile {
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

                $profile->members()->attach($user->id, ['is_owner' => false]);
                $invite->consume();

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'invite_key' => __('You are already a member of this profile.'),
            ]);
        }

        return response()->json([
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_owner' => false,
            ],
        ]);
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
            'awaiting_verification' => $invite->isReserved(),
            'expires_at' => $invite->expires_at->toISOString(),
        ];
    }
}
