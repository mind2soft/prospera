<?php

namespace App\Models;

use App\Services\ResourcePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $type
 * @property string $color
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Envelope extends Model
{
    public const DIRECTION_EXPENSE = 'expense';

    public const DIRECTION_REVENUE = 'revenue';

    public const DIRECTIONS = [self::DIRECTION_EXPENSE, self::DIRECTION_REVENUE];

    /**
     * The schema limit for envelope names (`envelopes.name` is varchar(60),
     * see the create-envelopes migration): transfer collision resolution
     * must keep every generated name within it.
     */
    public const MAX_NAME_LENGTH = 60;

    /**
     * The first suffix appended to a colliding name on transfer; repeated
     * collisions are numbered (" (imported 2)", " (imported 3)", ...).
     */
    private const IMPORTED_SUFFIX = ' (imported)';

    protected $table = 'envelopes';

    protected $fillable = ['user_id', 'name', 'type', 'color'];

    /**
     * The user owning this envelope (envelopes stay user-owned).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The envelope's permission rows.
     *
     * @return HasMany<EnvelopePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(EnvelopePermission::class, 'envelope_id');
    }

    /**
     * The budget assignments referencing this envelope.
     *
     * @return HasMany<BudgetEnvelope, $this>
     */
    public function budgetEnvelopes(): HasMany
    {
        return $this->hasMany(BudgetEnvelope::class, 'envelope_id');
    }

    /**
     * The transactions recorded against this envelope.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'envelope_id');
    }

    /**
     * The permission the given user has on this envelope: "owner",
     * "contributor", "viewer" — or "none" (metadata-only) for users who
     * merely share a profile with the owner. Envelopes are not
     * profile-scoped, so there is no implicit viewer visibility.
     *
     * Defence in depth (docs/shared-finances.md): a share row only counts
     * while the grantee still shares a profile with the owner, or while the
     * envelope is referenced by a profile the grantee belongs to — a stale
     * grant otherwise resolves to "none". $coMemberIds (the ids sharing a
     * profile with $user) may be precomputed for bulk lookups.
     *
     * @param  Collection<int, int>|null  $coMemberIds
     */
    public function permissionFor(User $user, ?Collection $coMemberIds = null): string
    {
        if ($this->user_id === $user->id) {
            return ResourcePermissions::OWNER;
        }

        /** @var EnvelopePermission|null $share */
        $share = $this->relationLoaded('permissions')
            ? $this->permissions->firstWhere('user_id', $user->id)
            : $this->permissions()->where('user_id', $user->id)->first();

        if ($share === null) {
            return ResourcePermissions::NONE;
        }

        $coMemberIds ??= FinancialProfile::coMemberIdsOf($user->id);

        if (! $coMemberIds->contains($this->user_id)
            && ! FinancialProfile::envelopeReferencedByProfilesOf($this->id, $user->id)) {
            return ResourcePermissions::NONE;
        }

        return $share->permission;
    }

    /**
     * What references the envelope and so blocks its deletion and a
     * direction change (docs/envelope.md): "budget" (a budget assignment),
     * "transactions", or null when nothing does.
     *
     * @return 'budget'|'transactions'|null
     */
    public function referencedBy(): ?string
    {
        return match (true) {
            $this->budgetEnvelopes()->exists() => 'budget',
            $this->transactions()->exists() => 'transactions',
            default => null,
        };
    }

    public function isReferenced(): bool
    {
        return $this->referencedBy() !== null;
    }

    /**
     * Scope envelopes referenced by a budget assignment or a transaction.
     *
     * @param  Builder<Envelope>  $query
     */
    #[Scope]
    protected function referenced(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereHas('budgetEnvelopes')->orWhereHas('transactions'));
    }

    /**
     * Whether the envelope is owned by the given user.
     */
    public function isOwner(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Scope envelopes visible to the given user: their own, ones shared with
     * them, and metadata-only ones via profile membership with the owner.
     *
     * @param  Builder<Envelope>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id)
                ->orWhereHas('permissions', fn (Builder $q) => $q->where('user_id', $user->id))
                ->orWhereHas('user.profiles', fn (Builder $q) => $q->whereHas(
                    'members',
                    fn (Builder $members) => $members->whereKey($user->id),
                ));
        });
    }

    /**
     * Transfer ownership to the given profile member: the envelope becomes
     * theirs (name collisions resolved with "(imported)") and the previous
     * owner becomes a contributor via an envelope_permissions row.
     */
    public function transferOwnershipTo(User $target): void
    {
        $previousOwnerId = $this->user_id;

        if ($previousOwnerId === $target->id) {
            return;
        }

        DB::transaction(function () use ($target, $previousOwnerId): void {
            $this->transferTo($target);

            $this->permissions()->updateOrCreate(
                ['user_id' => $previousOwnerId],
                ['permission' => ResourcePermissions::CONTRIBUTOR],
            );
        });
    }

    /**
     * Transfer ownership of this envelope to the given user, resolving name
     * collisions (unique per owner) with a bounded " (imported)" suffix. The
     * new owner's former share row is dropped (ownership supersedes it).
     */
    public function transferTo(User $target): void
    {
        if ($this->user_id === $target->id) {
            return;
        }

        // A concurrent transfer or rename of another envelope can claim the
        // chosen name between the existence check and the save: the
        // unique(user_id, name) index is the authority, so retry the whole
        // resolution instead of failing the enclosing operation (member
        // removal, account deletion).
        retry(3, function () use ($target): void {
            DB::transaction(function () use ($target): void {
                $this->permissions()->where('user_id', $target->id)->delete();

                $this->forceFill([
                    'user_id' => $target->id,
                    'name' => $this->transferredNameFor($target->id),
                ])->save();
            });
        }, 50, fn (Throwable $exception) => self::isUniqueViolation($exception));
    }

    /**
     * The name this envelope takes on transfer to the given user: the
     * original name when it is free, otherwise the original base truncated
     * (Unicode-aware) to leave room for an " (imported)" suffix, numbered
     * " (imported 2)", " (imported 3)", ... on repeated collisions. Each
     * candidate is regenerated from the original base, so no candidate ever
     * exceeds the schema's name length limit.
     */
    private function transferredNameFor(int $targetUserId): string
    {
        $base = $this->name;

        if (! self::nameTakenBy($base, $targetUserId)) {
            return $base;
        }

        for ($attempt = 1; ; $attempt++) {
            $suffix = $attempt === 1
                ? self::IMPORTED_SUFFIX
                : ' (imported '.$attempt.')';
            $candidate = mb_substr($base, 0, max(0, self::MAX_NAME_LENGTH - mb_strlen($suffix))).$suffix;

            if (! self::nameTakenBy($candidate, $targetUserId)) {
                return $candidate;
            }
        }
    }

    /**
     * Whether the given user already owns an envelope with the exact given
     * name: an exact match, not a wildcard prefix, so names containing
     * "%" or "_" cannot cause false collisions.
     */
    private static function nameTakenBy(string $name, int $userId): bool
    {
        return Envelope::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();
    }

    /**
     * Whether the exception chain carries a database unique-constraint
     * violation: SQLSTATE 23505 on PostgreSQL, 23000 on SQLite.
     */
    private static function isUniqueViolation(Throwable $exception): bool
    {
        $cause = $exception;

        while ($cause !== null) {
            if ($cause instanceof QueryException) {
                return in_array($cause->errorInfo[0] ?? null, ['23000', '23505'], true);
            }

            $cause = $cause->getPrevious();
        }

        return false;
    }

    /**
     * Sign a positive amount from the envelope's direction: expenses are
     * stored negative, revenues positive.
     */
    public function signedAmount(int|float $amount): int|float
    {
        return $this->type === self::DIRECTION_EXPENSE ? -$amount : $amount;
    }
}
