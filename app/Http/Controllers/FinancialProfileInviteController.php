<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\StoreInviteRequest;
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
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

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
    public function index(FinancialProfile $profile): JsonResponse
    {
        $this->authorize('manage', $profile);

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
    public function store(StoreInviteRequest $request, FinancialProfile $profile): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->currentUser($request);

        $rawKey = FinancialProfileInvite::generateKey();

        $invite = DB::transaction(function () use ($user, $profile, $validated, $rawKey): FinancialProfileInvite {
            $profile = FinancialProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $this->authorize('manage', $profile);

            if ($profile->members()->forEmail($validated['email'], 'users.email')->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('This user is already a member of the profile.'),
                ]);
            }

            // The profile's invites for the email, locked: a registration
            // reserving one of them (CreateNewUser locks the invite too)
            // cannot slip in between the check and the replacement.
            $previous = FinancialProfileInvite::query()
                ->where('profile_id', $profile->id)
                ->forEmail($validated['email'])
                ->lockForUpdate()
                ->get();

            // A live reservation means the invitee already registered
            // through an invite and joins on verification: a second invite
            // would outlive the grant in the pending list.
            if ($previous->contains(fn (FinancialProfileInvite $invite): bool => $invite->isReserved() && ! $invite->isExpired())) {
                throw ValidationException::withMessages([
                    'email' => __('This person already registered through an invite and joins the profile once they verify their email.'),
                ]);
            }

            FinancialProfileInvite::query()
                ->whereKey($previous->reject(fn (FinancialProfileInvite $invite): bool => $invite->isReserved())->modelKeys())
                ->delete();

            return FinancialProfileInvite::create([
                'profile_id' => $profile->id,
                'invite_key' => FinancialProfileInvite::keyHash($rawKey),
                'email' => $validated['email'],
                'role' => $validated['role'] ?? FinancialProfileInvite::ROLE_COLLABORATOR,
                'invited_by' => $user->id,
                'expires_at' => now()->addHours(48),
            ]);
        }, attempts: 3);

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
    public function destroy(FinancialProfile $profile, FinancialProfileInvite $invite): JsonResponse
    {
        $this->authorize('delete', [$invite, $profile]);

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

        $profile = $this->grantMembership($inviteKey, $this->currentUser($request));

        return response()->json([
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_owner' => false,
            ],
        ]);
    }

    /**
     * Decline an invite (authenticated, verified user whose account email
     * matches the invite): the invite is deleted, so it leaves the owner's
     * pending list and can no longer be accepted. Reserved invites belong to
     * another (new) account and are treated as unknown.
     */
    public function decline(Request $request, string $inviteKey): JsonResponse
    {
        $user = $this->currentUser($request);

        DB::transaction(function () use ($user, $inviteKey): void {
            $invite = FinancialProfileInvite::query()
                ->where('invite_key', FinancialProfileInvite::keyHash($inviteKey))
                ->lockForUpdate()
                ->first();

            abort_if($invite === null || $invite->isReserved(), 404);

            if (! FinancialProfileInvite::sameEmail($invite->email, $user->email)) {
                throw ValidationException::withMessages([
                    'invite_key' => __('This invite was issued to another email address.'),
                ]);
            }

            $invite->delete();
        });

        return response()->json(status: 204);
    }

    /**
     * Accept link from the invitation email (issue #39). A GET never changes
     * state (a cross-site link must not make a signed-in user join a
     * profile): the link lands on the invite confirmation page, whose
     * "Accept invite" button sends the CSRF-protected
     * `POST /api/invites/{inviteKey}/accept`. Guests are sent to the sign-in
     * page with the accept URL as the intended destination (they land on
     * the confirmation after signing in), unverified users are redirected to
     * the verification notice by the `verified` middleware, and the cases
     * the confirmation cannot complete (email mismatch, already a member)
     * flash their error up front. Unknown, reserved and expired keys get the
     * landing page's own error.
     */
    public function acceptViaLink(Request $request, string $inviteKey): RedirectResponse
    {
        $invite = FinancialProfileInvite::findByKey($inviteKey);

        if ($invite !== null && ! $invite->isReserved() && ! $invite->isExpired()) {
            $user = $this->currentUser($request);

            // A missing profile relation is impossible while the invite
            // exists (profile FK cascade) — a null one simply has no
            // membership to report.
            $error = $invite->usageError($user->email)
                ?? ($invite->profile?->isMember($user) ? 'You are already a member of this profile.' : null);

            if ($error !== null) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __($error)]);
            }
        }

        return redirect()->route('invites.landing', ['inviteKey' => $inviteKey]);
    }

    /**
     * Grant the membership carried by the invite to the given user inside a
     * locked transaction, consuming the invite. Shared by the JSON accept
     * accept endpoint (the email's accept link only leads to the
     * confirmation page that calls it).
     */
    private function grantMembership(string $inviteKey, User $user): FinancialProfile
    {
        try {
            return DB::transaction(function () use ($inviteKey, $user): FinancialProfile {
                $candidate = FinancialProfileInvite::query()
                    ->where('invite_key', FinancialProfileInvite::keyHash($inviteKey))->first(['id', 'profile_id']);
                abort_if($candidate === null, 404);
                $profile = FinancialProfile::query()->whereKey($candidate->profile_id)->lockForUpdate()->first();
                abort_if($profile === null, 404);
                $user = User::query()->whereKey($user->id)->lockForUpdate()->first();
                abort_if($user === null, 401);
                abort_unless($user->hasVerifiedEmail(), 403);
                $invite = FinancialProfileInvite::query()
                    ->whereKey($candidate->id)->where('profile_id', $profile->id)
                    ->where('invite_key', FinancialProfileInvite::keyHash($inviteKey))
                    ->lockForUpdate()
                    ->first();

                abort_if($invite === null, 404);

                $error = $invite->usageError($user->email);

                if ($error !== null) {
                    throw ValidationException::withMessages(['invite_key' => __($error)]);
                }

                if ($profile->isMember($user)) {
                    throw ValidationException::withMessages([
                        'invite_key' => __('You are already a member of this profile.'),
                    ]);
                }

                $profile->members()->attach($user->id, $invite->membershipPivotAttributes());
                $invite->consume();
                FinancialProfileInvite::deleteRedundantFor($profile, $user);

                return $profile;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'invite_key' => __('You are already a member of this profile.'),
            ]);
        }
    }

    /**
     * The stored invite_key (a hash) is deliberately not exposed.
     * toISOString() reports null for an invalid date — the framework's
     * own JSON encoding of an invalid Carbon does the same.
     *
     * @return array<string, bool|int|string|null>
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
     * variant with the accept link (it opens the invite confirmation page,
     * where the user confirms); any other address gets the
     * account-creation invite. Registered recipients are gated by their
     * `profile_invite` notification toggle (re-checked at send time inside
     * the mailable).
     */
    private function notifyInvitee(FinancialProfileInvite $invite, string $rawKey): void
    {
        $recipient = User::query()->forEmail($invite->email)->first();

        if ($recipient !== null
            && ! $recipient->wantsNotification(NotificationSettings::PROFILE_INVITE)) {
            return;
        }

        // The invited_by and profile_id FKs cascade: while the invite
        // exists, its issuer and profile do too.
        $invitedBy = $invite->invitedBy;
        $profile = $invite->profile;

        if ($invitedBy === null || $profile === null) {
            throw new LogicException('The invite has no issuer or profile.');
        }

        Mail::to($invite->email)->queue(new ProfileInvite(
            inviterName: $invitedBy->name,
            inviterEmail: $invitedBy->email,
            profileName: $profile->name,
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
