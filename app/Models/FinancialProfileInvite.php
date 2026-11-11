<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $invite_key
 * @property string $email
 * @property string $role
 * @property int $invited_by
 * @property int|null $pending_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialProfileInvite extends Model
{
    /** A member who can contribute but not manage the profile. */
    public const ROLE_COLLABORATOR = 'collaborator';

    /**
     * The profile owner role — never grantable through invites (a profile
     * has exactly one owner; ownership transfer stays in
     * FinancialProfileController::transferOwnership). Defined for the
     * role→membership mapping below.
     */
    public const ROLE_OWNER = 'owner';

    /**
     * The roles an invite may carry — validated on creation; anything else
     * (`owner` included) is rejected.
     *
     * @var list<string>
     */
    public const GRANTABLE_ROLES = [self::ROLE_COLLABORATOR];

    /**
     * The role→membership mapping (`is_owner` pivot attribute per role key).
     * Unknown role keys fall back to a non-owner membership.
     *
     * @var array<string, bool>
     */
    private const IS_OWNER_BY_ROLE = [
        self::ROLE_COLLABORATOR => false,
        self::ROLE_OWNER => true,
    ];

    protected $fillable = ['profile_id', 'invite_key', 'email', 'role', 'invited_by', 'pending_user_id', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * The profile this invite belongs to.
     *
     * @return BelongsTo<FinancialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class);
    }

    /**
     * The owner who issued the invite.
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Scope invites that have not expired yet.
     *
     * @param  Builder<FinancialProfileInvite>  $query
     */
    #[Scope]
    protected function notExpired(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /**
     * Whether the invite has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Consume the invite (accepted invites are deleted).
     */
    public function consume(): void
    {
        $this->delete();
    }

    /**
     * The financial_profile_user pivot attributes granting this invite's
     * role on membership grant. The role→membership mapping stays isolated
     * here so future roles map cleanly; today every invited member joins as
     * a collaborator (non-owner) — only grantable roles can reach an invite
     * (creation validates against GRANTABLE_ROLES), so an invited membership
     * can never make a profile's second owner.
     *
     * @return array<string, mixed>
     */
    public function membershipPivotAttributes(): array
    {
        return ['is_owner' => self::IS_OWNER_BY_ROLE[$this->role] ?? false];
    }

    /**
     * Generate a fresh random invite key. The raw key exists only in the
     * invite link; the stored invite_key is its SHA-256 hash.
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * Hash an invite key for storage/lookup.
     */
    public static function keyHash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * Find a pending invite by its raw link key.
     */
    public static function findByKey(string $rawKey): ?self
    {
        /** @var self|null */
        return self::query()
            ->where('invite_key', self::keyHash($rawKey))
            ->first();
    }

    /**
     * Whether a newly registered (not yet verified) account has reserved the
     * invite; it can no longer be used by anyone else.
     */
    public function isReserved(): bool
    {
        return $this->pending_user_id !== null;
    }

    /**
     * The (untranslated) validation error for using this invite with the
     * given email, or null when it is usable (not expired, not reserved,
     * email matches).
     */
    public function usageError(string $email): ?string
    {
        return match (true) {
            $this->isExpired() => 'This invite has expired.',
            $this->isReserved() => 'This invite is invalid.',
            mb_strtolower($this->email) !== mb_strtolower($email) => 'This invite was issued to another email address.',
            default => null,
        };
    }

    /**
     * Invite context for the registration page (inviter's name, profile
     * name, the granted role and the locked email).
     *
     * @return array{profile: string, inviter: string, email: string, role: string}
     */
    public function context(): array
    {
        return [
            'profile' => $this->profile->name,
            'inviter' => $this->invitedBy->name,
            'email' => $this->email,
            'role' => $this->role,
        ];
    }

    /**
     * Invite context for a raw key, or null for unknown, expired or reserved
     * keys.
     *
     * @return array{profile: string, inviter: string, email: string, role: string}|null
     */
    public static function contextFor(?string $inviteKey): ?array
    {
        if ($inviteKey === null || $inviteKey === '') {
            return null;
        }

        $invite = self::findByKey($inviteKey);

        if ($invite === null || $invite->isExpired() || $invite->isReserved()) {
            return null;
        }

        return $invite->context();
    }

    /**
     * Grant the pending membership reserved by the given (now verified)
     * user, then consume the invite.
     *
     * Accounts that register through an invite skip the personal default
     * profile seeding (docs/shared-finances.md — invites), so whenever the
     * pending grant does not happen — the invite was revoked, pruned or has
     * expired, or the account's email changed before verification — the
     * account is seeded with its own default profile here to keep it usable.
     */
    public static function completePendingFor(User $user): void
    {
        DB::transaction(function () use ($user): void {
            /** @var self|null $invite */
            $invite = self::query()
                ->where('pending_user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($invite === null) {
                FinancialProfile::ensureDefaultFor($user);

                return;
            }

            // An expired reservation no longer grants anything: the owner's
            // pending list stops showing (and offering to revoke) invites
            // once they expire, so a grant past expiry would be invisible
            // to them.
            if (! $invite->isExpired()
                && mb_strtolower($invite->email) === mb_strtolower($user->email)
                && ! $invite->profile->isMember($user)) {
                $invite->profile->members()->attach($user->id, $invite->membershipPivotAttributes());

                // The account registered through the invite: the shared
                // profile becomes their default (their only membership —
                // issue #41, completing #39's intent).
                FinancialProfile::ensureDefaultFor($user);
            } else {
                FinancialProfile::ensureDefaultFor($user);
            }

            $invite->consume();
        });
    }

    /**
     * Before the given user's account is deleted: the pending invites they
     * issued on profiles that survive them are re-attributed to each
     * profile's current owner, so the `invited_by` cascade does not silently
     * drop them (an invitee who already registered through the invite would
     * otherwise end up outside the profile). Invites of profiles deleted
     * with the account go away with the profile.
     */
    public static function reassignIssuedBy(User $user): void
    {
        self::query()
            ->where('invited_by', $user->id)
            ->get()
            ->groupBy('profile_id')
            ->each(function (Collection $invites, int $profileId) use ($user): void {
                $owner = FinancialProfile::query()->find($profileId)?->ownerUser();

                if ($owner === null || $owner->id === $user->id) {
                    return;
                }

                self::query()
                    ->whereKey($invites->modelKeys())
                    ->update(['invited_by' => $owner->id, 'updated_at' => now()]);
            });
    }

    /**
     * Before the given (unverified) account is deleted: the invite it
     * reserved is released instead of cascading away, so the owner's invite
     * stays pending and usable (e.g. to re-register with the same email).
     */
    public static function releaseReservationOf(User $user): void
    {
        self::query()
            ->where('pending_user_id', $user->id)
            ->update(['pending_user_id' => null, 'updated_at' => now()]);
    }

    /**
     * Delete stale invites: expired unreserved ones, and reservations whose
     * account never verified within a week of the invite's expiry.
     */
    public static function pruneStale(): int
    {
        return self::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->whereNull('pending_user_id')->where('expires_at', '<=', now()))
                ->orWhere('expires_at', '<=', now()->subWeek()))
            ->delete();
    }
}
