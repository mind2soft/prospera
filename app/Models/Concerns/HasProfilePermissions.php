<?php

namespace App\Models\Concerns;

use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared permission logic for profile-scoped resources (budgets, ledgers):
 * ownership lives in the resource's "owner" permission row and profile
 * members without a row get implicit viewer access.
 *
 * Classes using this trait define `profile()` (BelongsTo FinancialProfile)
 * and `permissions()` (HasMany of rows with user_id + permission).
 *
 * @property int $profile_id
 * @property-read FinancialProfile $profile
 */
trait HasProfilePermissions
{
    /**
     * The permission the given user has on this resource. Uses the
     * eager-loaded `permissions` relation when present (no extra query per
     * row). Pass $isMember when profile membership is already known (e.g.
     * listings scoped to the caller's profiles) to skip the membership query.
     */
    public function permissionFor(User $user, ?bool $isMember = null): string
    {
        /** @var Model|null $share */
        $share = $this->relationLoaded('permissions')
            ? $this->permissions->firstWhere('user_id', $user->id)
            : $this->permissions()->where('user_id', $user->id)->first();

        if ($share !== null) {
            return (string) $share->getAttribute('permission');
        }

        return ($isMember ?? $this->profile->isMember($user))
            ? ResourcePermissions::VIEWER
            : ResourcePermissions::NONE;
    }

    /**
     * Transfer ownership to the given profile member; the previous owner
     * becomes a contributor.
     */
    public function transferOwnershipTo(User $target): void
    {
        DB::transaction(function () use ($target): void {
            $ownerRow = $this->permissions()->where('permission', ResourcePermissions::OWNER)->first();
            $targetRow = $this->permissions()->where('user_id', $target->id)->first();

            if ($targetRow !== null) {
                $targetRow->forceFill(['permission' => ResourcePermissions::OWNER])->save();
            } else {
                $this->permissions()->create([
                    'user_id' => $target->id,
                    'permission' => ResourcePermissions::OWNER,
                ]);
            }

            $ownerRow?->forceFill(['permission' => ResourcePermissions::CONTRIBUTOR])->save();
        });

        $this->unsetRelation('permissions');
    }

    /**
     * Scope resources visible to the given user (profile members).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('profile.members', fn (Builder $q) => $q->whereKey($user->id));
    }
}
