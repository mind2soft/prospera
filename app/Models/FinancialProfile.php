<?php

namespace App\Models;

use App\Services\ResourcePermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialProfile extends Model
{
    public const DEFAULT_NAME = 'Default profile';

    protected $fillable = ['name', 'is_default'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * The profile's members.
     *
     * @return BelongsToMany<User, $this, FinancialProfileUser, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'financial_profile_user', 'profile_id', 'user_id')
            ->using(FinancialProfileUser::class)
            ->withPivot('is_owner')
            ->withTimestamps();
    }

    /**
     * The profile's owner.
     */
    public function ownerUser(): ?User
    {
        /** @var User|null */
        return $this->members()->wherePivot('is_owner', true)->first();
    }

    /**
     * Whether the given user is the profile's owner.
     */
    public function isOwner(User $user): bool
    {
        return $this->members()
            ->whereKey($user->id)
            ->wherePivot('is_owner', true)
            ->exists();
    }

    /**
     * Whether the given user is a member of the profile.
     */
    public function isMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Whether the profile only has the owner as a member.
     */
    public function isSolo(): bool
    {
        return $this->members()->count() === 1;
    }

    /**
     * Create a profile owned by the given user. Wrapped in a transaction:
     * profile and owner pivot row are created together or not at all.
     */
    public static function createOwnedBy(User $user, string $name = self::DEFAULT_NAME, bool $isDefault = false): self
    {
        return DB::transaction(function () use ($user, $name, $isDefault): self {
            $profile = self::create(['name' => $name, 'is_default' => $isDefault]);

            $profile->members()->attach($user->id, ['is_owner' => true]);

            return $profile;
        });
    }

    /**
     * Create the per-user seeded "Default profile" (deletion-guarded via the
     * is_default flag).
     */
    public static function createDefaultFor(User $user): self
    {
        return self::createOwnedBy($user, self::DEFAULT_NAME, isDefault: true);
    }

    /**
     * Whether the two users share membership in at least one profile.
     */
    public static function membersShareAProfile(User $a, User $b): bool
    {
        return self::query()
            ->whereHas('members', fn (Builder $query) => $query->whereKey($a->id))
            ->whereHas('members', fn (Builder $query) => $query->whereKey($b->id))
            ->exists();
    }

    /**
     * Transfer ownership to the given member; every other owner row is
     * demoted so the profile always has exactly one owner.
     */
    public function transferOwnershipTo(User $target): void
    {
        DB::transaction(function () use ($target): void {
            $this->members()->updateExistingPivot($target, ['is_owner' => true]);

            DB::table('financial_profile_user')
                ->where('profile_id', $this->id)
                ->where('is_owner', true)
                ->where('user_id', '!=', $target->id)
                ->update(['is_owner' => false, 'updated_at' => now()]);
        });
    }

    /**
     * Transfer ownership to the earliest joiner (lowest financial_profile_user.created_at,
     * ties broken by the lowest user_id). Returns the new owner, or null when
     * the owner is the only member.
     */
    public function transferOwnershipToEarliestJoiner(): ?User
    {
        $next = $this->members()
            ->wherePivot('is_owner', false)
            ->orderByPivot('created_at')
            ->orderBy('users.id')
            ->first();

        if ($next === null) {
            return null;
        }

        $this->transferOwnershipTo($next);

        return $next;
    }

    /**
     * Remove a member: their owned resources in the profile auto-transfer to
     * the profile owner and their share rows on the profile's resources are
     * deleted. Only the member's envelopes referenced by this profile's
     * budgets/ledgers transfer — the member still exists as a user and keeps
     * their unreferenced envelopes. When the member is merely leaving (not
     * deleting their account), envelopes they still use in another of their
     * profiles stay theirs and the profile owner gets contributor access.
     */
    public function removeMember(User $member, bool $accountDeletion = false): void
    {
        DB::transaction(function () use ($member, $accountDeletion): void {
            $owner = $this->ownerUser();

            $this->transferBudgetsOwnedBy($member, $owner);
            $this->deleteBudgetPermissionsFor($member);
            $this->transferLedgersOwnedBy($member, $owner);
            $this->deleteLedgerPermissionsFor($member);
            $this->deleteEnvelopePermissionsFor($member);
            $this->transferReferencedEnvelopesOwnedBy($member, $owner, keepSharedElsewhere: ! $accountDeletion);

            $this->members()->detach($member);
        });
    }

    /**
     * Reassign the member's budget "owner" share rows (on this profile's
     * budgets) to the target user, upgrading their existing share row when
     * they already have one.
     */
    public function transferBudgetsOwnedBy(User $member, ?User $target): void
    {
        if ($target === null) {
            return;
        }

        $this->transferOwnershipInPermissionTable('budget_permissions', 'budgets', 'budget_id', $member, $target);
    }

    /**
     * Reassign the member's ledger "owner" share rows (on this profile's
     * ledgers) to the target user, upgrading their existing share row when
     * they already have one.
     */
    public function transferLedgersOwnedBy(User $member, ?User $target): void
    {
        if ($target === null) {
            return;
        }

        $this->transferOwnershipInPermissionTable('ledger_permissions', 'ledgers', 'ledger_id', $member, $target);
    }

    /**
     * Delete the member's share rows on the profile's budgets (their
     * contributor/viewer grants).
     */
    public function deleteBudgetPermissionsFor(User $member): void
    {
        DB::table('budget_permissions')
            ->whereIn('budget_id', Budget::query()->where('profile_id', $this->id)->select('id'))
            ->where('user_id', $member->id)
            ->delete();
    }

    /**
     * Delete the member's share rows on the profile's ledgers (their
     * contributor/viewer grants).
     */
    public function deleteLedgerPermissionsFor(User $member): void
    {
        DB::table('ledger_permissions')
            ->whereIn('ledger_id', Ledger::query()->where('profile_id', $this->id)->select('id'))
            ->where('user_id', $member->id)
            ->delete();
    }

    /**
     * Delete the member's envelope share rows that were granted by owners
     * who are members of this profile (grants target profile members only).
     */
    public function deleteEnvelopePermissionsFor(User $member): void
    {
        DB::table('envelope_permissions')
            ->whereIn('envelope_id', Envelope::query()
                ->whereIn('user_id', $this->members()->select('users.id'))
                ->select('id'))
            ->where('user_id', $member->id)
            ->delete();
    }

    /**
     * Generic share-table ownership transfer: the member's "owner" rows on
     * the profile's resources move to the target user, upgrading their
     * existing share row when they already have one.
     */
    private function transferOwnershipInPermissionTable(
        string $shareTable,
        string $resourceTable,
        string $resourceForeignKey,
        User $member,
        User $target,
    ): void {
        $resourceIds = DB::table($resourceTable)
            ->where('profile_id', $this->id)
            ->pluck('id');

        $ownedResourceIds = DB::table($shareTable)
            ->whereIn($resourceForeignKey, $resourceIds)
            ->where('user_id', $member->id)
            ->where('permission', ResourcePermissions::OWNER)
            ->pluck($resourceForeignKey);

        // When the target already has a share row, upgrade it to owner and
        // drop the member's row (unique [resource, user_id]).
        DB::table($shareTable)
            ->whereIn($resourceForeignKey, $ownedResourceIds)
            ->where('user_id', $target->id)
            ->update(['permission' => ResourcePermissions::OWNER, 'updated_at' => now()]);

        DB::table($shareTable)
            ->whereIn($resourceForeignKey, $ownedResourceIds)
            ->where('user_id', $member->id)
            ->where('permission', ResourcePermissions::OWNER)
            ->whereIn($resourceForeignKey, function (QueryBuilder $query) use ($shareTable, $resourceForeignKey, $target): void {
                $query->from($shareTable)
                    ->select($resourceForeignKey)
                    ->where('user_id', $target->id);
            })
            ->delete();

        // Otherwise reassign the member's owner row to the target.
        DB::table($shareTable)
            ->whereIn($resourceForeignKey, $ownedResourceIds)
            ->where('user_id', $member->id)
            ->where('permission', ResourcePermissions::OWNER)
            ->update(['user_id' => $target->id, 'updated_at' => now()]);
    }

    /**
     * Transfer the member's envelopes referenced by this profile's budgets
     * or ledgers to the target user, resolving name collisions with the
     * "(imported)" suffix. Unreferenced envelopes stay owned by the member.
     *
     * With $keepSharedElsewhere, envelopes also referenced by another
     * profile the member still belongs to are not transferred (that would
     * break the member's other profile); the target gets contributor access
     * instead so this profile's budgets and ledgers keep working.
     */
    public function transferReferencedEnvelopesOwnedBy(User $member, ?User $target, bool $keepSharedElsewhere = false): void
    {
        if ($target === null || $target->id === $member->id) {
            return;
        }

        $otherProfileIds = $keepSharedElsewhere
            ? $member->profiles()->whereKeyNot($this->id)->pluck('financial_profiles.id')
            : collect();

        self::referencingEnvelopes(Envelope::query()->where('user_id', $member->id), [$this->id])
            ->get()
            ->each(function (Envelope $envelope) use ($target, $otherProfileIds): void {
                $usedElsewhere = $otherProfileIds->isNotEmpty() && self::referencingEnvelopes(
                    Envelope::query()->whereKey($envelope->id),
                    $otherProfileIds->all(),
                )->exists();

                if (! $usedElsewhere) {
                    $envelope->transferTo($target);

                    return;
                }

                $envelope->permissions()->updateOrCreate(
                    ['user_id' => $target->id],
                    ['permission' => ResourcePermissions::CONTRIBUTOR],
                );
            });
    }

    /**
     * Constrain an envelope query to the ones referenced by the given
     * profiles' budgets or ledger transactions.
     *
     * @param  Builder<Envelope>  $query
     * @param  array<int, int>  $profileIds
     * @return Builder<Envelope>
     */
    private static function referencingEnvelopes(Builder $query, array $profileIds): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereHas('budgetEnvelopes.budget', fn (Builder $budget) => $budget->whereIn('budgets.profile_id', $profileIds))
            ->orWhereHas('transactions.ledger', fn (Builder $ledger) => $ledger->whereIn('ledgers.profile_id', $profileIds)));
    }

    /**
     * Delete the profile along with the resources scoped to it (solo profiles
     * on account deletion): the ledgers with their transactions, then the
     * budgets and the profile itself.
     */
    public function deleteWithResources(): void
    {
        DB::transaction(function (): void {
            Ledger::query()->where('profile_id', $this->id)->get()
                ->each(fn (Ledger $ledger) => $ledger->deleteWithTransactions());

            // Budget children (permissions, envelope assignments) cascade.
            Budget::query()->where('profile_id', $this->id)->delete();

            $this->delete();
        });
    }
}
