<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $invite_key
 * @property string $email
 * @property int $invited_by
 * @property int|null $pending_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialProfileInvite extends Model
{
    protected $fillable = ['profile_id', 'invite_key', 'email', 'invited_by', 'pending_user_id', 'expires_at'];

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
     * Invite context for the registration page (inviter's name, profile name
     * and the locked email).
     *
     * @return array{profile: string, inviter: string, email: string}
     */
    public function context(): array
    {
        return [
            'profile' => $this->profile->name,
            'inviter' => $this->invitedBy->name,
            'email' => $this->email,
        ];
    }

    /**
     * Invite context for a raw key, or null for unknown, expired or reserved
     * keys.
     *
     * @return array{profile: string, inviter: string, email: string}|null
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
     * user, then consume the invite. No-op when there is no reservation,
     * the invite was revoked, or the email no longer matches.
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
                return;
            }

            if (mb_strtolower($invite->email) === mb_strtolower($user->email)
                && ! $invite->profile->isMember($user)) {
                $invite->profile->members()->attach($user->id, ['is_owner' => false]);
            }

            $invite->consume();
        });
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
