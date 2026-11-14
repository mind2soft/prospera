<?php

namespace App\Models;

use App\Security\Amounts\AmountKeyRing;
use App\Services\ResourcePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property CarbonImmutable|null $initial_budget_created_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FinancialProfile extends Model
{
    public const DEFAULT_NAME = 'Default profile';

    protected $fillable = ['name'];

    protected $casts = [
        'initial_budget_created_at' => 'immutable_datetime',
    ];

    /**
     * Every profile gets its amount data key on creation
     * (docs/encryption.md).
     */
    protected static function booted(): void
    {
        static::created(function (FinancialProfile $profile): void {
            app(AmountKeyRing::class)->createKey($profile->id);
        });
    }

    /**
     * The profile's wrapped amount data keys, one row per version.
     *
     * @return HasMany<FinancialProfileKey, $this>
     */
    public function keys(): HasMany
    {
        return $this->hasMany(FinancialProfileKey::class, 'profile_id');
    }

    /**
     * The profile's members.
     *
     * @return BelongsToMany<User, $this, FinancialProfileUser, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'financial_profile_user', 'profile_id', 'user_id')
            ->using(FinancialProfileUser::class)
            ->withPivot('is_owner', 'is_default')
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
     * profile and owner pivot row are created together or not at all. When
     * $isDefault, the profile becomes the creator's default profile (per-user
     * pivot flag — issue #41).
     */
    public static function createOwnedBy(User $user, string $name = self::DEFAULT_NAME, bool $isDefault = false): self
    {
        return DB::transaction(function () use ($user, $name, $isDefault): self {
            $profile = self::create(['name' => $name]);

            $profile->members()->attach($user->id, ['is_owner' => true, 'is_default' => $isDefault]);

            return $profile;
        });
    }

    /**
     * Create the per-user seeded "Default profile" and mark it as the
     * creator's default (per-user pivot flag).
     */
    public static function createDefaultFor(User $user): self
    {
        return self::createOwnedBy($user, self::DEFAULT_NAME, isDefault: true);
    }

    /**
     * Whether the profile is the given user's default profile (per-user
     * pivot flag — docs/shared-finances.md).
     */
    public function isDefaultFor(User $user): bool
    {
        return $this->members()
            ->whereKey($user->id)
            ->wherePivot('is_default', true)
            ->exists();
    }

    /**
     * Mark the profile as the given user's default, clearing their previous
     * default. The user must be a member.
     */
    public function markDefaultFor(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Lock the user's membership rows first: two concurrent marks
            // (or a mark racing a membership-exit fallback) must not end up
            // with two defaults when none exists yet. SQLite serializes
            // writes; MySQL takes the row locks.
            DB::table('financial_profile_user')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            if (! $this->isMember($user)) {
                throw new LogicException(
                    'Only a profile member can have the profile as their default.',
                );
            }

            DB::table('financial_profile_user')
                ->where('user_id', $user->id)
                ->where('is_default', true)
                ->update(['is_default' => false, 'updated_at' => now()]);

            $this->members()->updateExistingPivot($user, ['is_default' => true]);
        });
    }

    /**
     * Guarantee the account has a default profile: keep the current one when
     * it still exists; otherwise reassign to the **earliest-created owned**
     * profile (ties by lowest id), else the **earliest remaining membership**
     * for users who own none; seed a fresh default profile when no membership
     * is left. Every membership exit — a discarded or revoked invite, leaving,
     * being removed, or a profile being deleted — must run this so an account
     * never ends up without a default (issue #41).
     */
    public static function ensureDefaultFor(User $user): void
    {
        if ($user->profiles()->wherePivot('is_default', true)->exists()) {
            return;
        }

        $target = $user->profiles()
            ->wherePivot('is_owner', true)
            ->orderBy('financial_profiles.created_at')
            ->orderBy('financial_profiles.id')
            ->first()
            ?? $user->profiles()
                ->orderBy('financial_profiles.created_at')
                ->orderBy('financial_profiles.id')
                ->first();

        if ($target !== null) {
            $target->markDefaultFor($user);

            return;
        }

        self::createDefaultFor($user);
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
     * Ids of every user sharing at least one profile with the given user
     * (the user included when they have any membership).
     *
     * @return Collection<int, int>
     */
    public static function coMemberIdsOf(int $userId): Collection
    {
        return DB::table('financial_profile_user')
            ->whereIn('profile_id', DB::table('financial_profile_user')
                ->where('user_id', $userId)
                ->select('profile_id'))
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * Whether the envelope is referenced by budgets or ledger transactions
     * of a profile the given user is a member of.
     */
    public static function envelopeReferencedByProfilesOf(int $envelopeId, int $userId): bool
    {
        $profileIds = DB::table('financial_profile_user')
            ->where('user_id', $userId)
            ->pluck('profile_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($profileIds === []) {
            return false;
        }

        return self::referencingEnvelopes(Envelope::query()->whereKey($envelopeId), $profileIds)->exists();
    }

    /**
     * Drop the envelope grants between the given user and each of the
     * given former co-members they no longer share any profile with, in
     * both directions (docs/shared-finances.md — envelope grants follow the
     * profile relationship). Run after a membership exit or a profile
     * deletion. Grants a co-member holds on the user's envelopes survive
     * only while the envelope is still referenced by budgets/ledgers of a
     * profile that co-member belongs to (the leaving member's envelope
     * still used in the profile they left — the profile keeps working).
     *
     * @param  iterable<User>  $formerCoMembers
     */
    public static function pruneEnvelopeGrantsBetween(User $user, iterable $formerCoMembers): void
    {
        $stillShared = self::coMemberIdsOf($user->id);

        foreach ($formerCoMembers as $other) {
            if ($other->id === $user->id || $stillShared->contains($other->id)) {
                continue;
            }

            self::deleteEnvelopeGrants(grantee: $user, envelopeOwner: $other);
            self::deleteEnvelopeGrants(grantee: $other, envelopeOwner: $user);
        }
    }

    /**
     * Delete the grantee's share rows on the envelope owner's envelopes,
     * except on envelopes still referenced by a profile the grantee
     * belongs to.
     */
    private static function deleteEnvelopeGrants(User $grantee, User $envelopeOwner): void
    {
        $granteeProfileIds = DB::table('financial_profile_user')
            ->where('user_id', $grantee->id)
            ->pluck('profile_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $keptEnvelopeIds = $granteeProfileIds === []
            ? []
            : self::referencingEnvelopes(Envelope::query()->where('user_id', $envelopeOwner->id), $granteeProfileIds)
                ->pluck('id')
                ->all();

        DB::table('envelope_permissions')
            ->where('user_id', $grantee->id)
            ->whereIn('envelope_id', Envelope::query()->where('user_id', $envelopeOwner->id)->select('id'))
            ->whereNotIn('envelope_id', $keptEnvelopeIds)
            ->delete();
    }

    /**
     * Transfer ownership to the given member; every other owner row is
     * demoted so the profile always has exactly one owner.
     *
     * The profile's draft budget follows the profile ownership (the
     * previous budget owner becomes contributor): publishing requires both
     * budget and profile ownership and there is one draft slot per profile,
     * so a draft left with the previous owner would block everyone
     * (docs/budget.md — Ownership).
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

            Budget::query()
                ->where('profile_id', $this->id)
                ->whereNull('published_at')
                ->whereDoesntHave('permissions', fn (Builder $query) => $query
                    ->where('user_id', $target->id)
                    ->where('permission', ResourcePermissions::OWNER))
                ->get()
                ->each(fn (Budget $draft) => $draft->transferOwnershipTo($target));
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
     * Envelope grants between the member and the remaining members are
     * dropped (both directions) once they no longer share any profile.
     */
    public function removeMember(User $member, bool $accountDeletion = false): void
    {
        DB::transaction(function () use ($member, $accountDeletion): void {
            $owner = $this->ownerUser();
            $remainingMembers = $this->members()->whereKeyNot($member->id)->get();

            // Before the envelope hand-over below, which only sees live
            // references (docs/undoable-deletion.md).
            Transaction::purgeDeletionsOnMemberEnvelopes($this, $member);

            $this->transferBudgetsOwnedBy($member, $owner);
            $this->deleteBudgetPermissionsFor($member);
            $this->transferLedgersOwnedBy($member, $owner);
            $this->deleteLedgerPermissionsFor($member);
            $this->transferReferencedEnvelopesOwnedBy($member, $owner, keepSharedElsewhere: ! $accountDeletion);

            // The member's dashboard layout for this profile is per-member
            // configuration: it goes with the membership.
            DashboardWidget::query()
                ->where('user_id', $member->id)
                ->where('financial_profile_id', $this->id)
                ->delete();

            $this->members()->detach($member);

            self::pruneEnvelopeGrantsBetween($member, $remainingMembers);

            // On account deletion the account itself goes away; otherwise a
            // member left without any profile must get its own default one.
            if (! $accountDeletion) {
                self::ensureDefaultFor($member);
            }
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
