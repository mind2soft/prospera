<?php

namespace App\Models;

use App\Services\ResourcePermissions;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $type
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Enveloppe extends Model
{
    public const DIRECTION_EXPENSE = 'expense';

    public const DIRECTION_REVENUE = 'revenue';

    public const DIRECTIONS = [self::DIRECTION_EXPENSE, self::DIRECTION_REVENUE];

    protected $table = 'enveloppes';

    protected $fillable = ['user_id', 'name', 'type', 'color'];

    /**
     * The user owning this enveloppe (enveloppes stay user-owned).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The enveloppe's permission rows.
     *
     * @return HasMany<EnveloppePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(EnveloppePermission::class, 'enveloppe_id');
    }

    /**
     * The budget assignments referencing this enveloppe.
     *
     * @return HasMany<BudgetEnveloppe, $this>
     */
    public function budgetEnveloppes(): HasMany
    {
        return $this->hasMany(BudgetEnveloppe::class, 'enveloppe_id');
    }

    /**
     * The transactions recorded against this enveloppe.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'enveloppe_id');
    }

    /**
     * The permission the given user has on this enveloppe: "owner",
     * "contributor", "viewer" — or "none" (metadata-only) for users who
     * merely share a profile with the owner. Enveloppes are not
     * profile-scoped, so there is no implicit viewer visibility.
     */
    public function permissionFor(User $user): string
    {
        if ($this->user_id === $user->id) {
            return ResourcePermissions::OWNER;
        }

        /** @var EnveloppePermission|null $share */
        $share = $this->relationLoaded('permissions')
            ? $this->permissions->firstWhere('user_id', $user->id)
            : $this->permissions()->where('user_id', $user->id)->first();

        if ($share !== null) {
            return $share->permission;
        }

        return ResourcePermissions::NONE;
    }

    /**
     * Whether the enveloppe is owned by the given user.
     */
    public function isOwner(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Scope enveloppes visible to the given user: their own, ones shared with
     * them, and metadata-only ones via profile membership with the owner.
     *
     * @param  Builder<Enveloppe>  $query
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
     * Transfer ownership to the given profile member: the enveloppe becomes
     * theirs (name collisions resolved with "(imported)") and the previous
     * owner becomes a contributor via an enveloppe_permissions row.
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
     * Transfer ownership of this enveloppe to the given user, resolving name
     * collisions (unique per owner) by appending " (imported)". The new
     * owner's former share row is dropped (ownership supersedes it).
     */
    public function transferTo(User $target): void
    {
        if ($this->user_id === $target->id) {
            return;
        }

        $takenNames = Enveloppe::query()
            ->where('user_id', $target->id)
            ->where('name', 'like', $this->name.'%')
            ->pluck('name')
            ->flip();

        $name = $this->name;

        while ($takenNames->has($name)) {
            $name .= ' (imported)';
        }

        DB::transaction(function () use ($target, $name): void {
            $this->permissions()->where('user_id', $target->id)->delete();

            $this->forceFill(['user_id' => $target->id, 'name' => $name])->save();
        });
    }

    /**
     * Sign a positive amount from the enveloppe's direction: expenses are
     * stored negative, revenues positive.
     */
    public function signedAmount(int $amount): int
    {
        return $this->type === self::DIRECTION_EXPENSE ? -$amount : $amount;
    }

    /**
     * The given user's effective permission on each of the given enveloppes
     * in two queries (instead of one per enveloppe), keyed by enveloppe id.
     * Unknown ids are omitted.
     *
     * @param  array<int, int>  $enveloppeIds
     * @return Collection<int, string>
     */
    public static function permissionsFor(User $user, array $enveloppeIds): Collection
    {
        if ($enveloppeIds === []) {
            return collect();
        }

        return self::query()
            ->whereIn('id', $enveloppeIds)
            ->with(['permissions' => fn ($query) => $query->where('user_id', $user->id)])
            ->get()
            ->mapWithKeys(fn (Enveloppe $enveloppe): array => [
                $enveloppe->id => $enveloppe->permissionFor($user),
            ]);
    }
}
