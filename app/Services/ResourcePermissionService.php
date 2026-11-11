<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared CRUD/validation for resource permission rows (budget_permissions,
 * ledger_permissions, envelope_permissions) — the backend of the Share drawer
 * (docs/shared-finances.md — permission model).
 *
 * Profile-scoped resources (budgets, ledgers) grant to members of the
 * resource's profile; user-owned resources (envelopes) grant to members of
 * the owner's profiles.
 */
final class ResourcePermissionService
{
    /**
     * The profile's members with their effective permission on the resource
     * (row permission, or implicit viewer for members without a row). The
     * `explicit` flag tells the Share drawer whether the member has an
     * actual share row — implicit viewers cannot be revoked.
     *
     * @return array<int, array<string, int|string|bool>>
     */
    public function profilePermissions(FinancialProfile $profile, string $shareTable, string $resourceColumn, int $resourceId): array
    {
        return DB::table('financial_profile_user')
            ->join('users', 'users.id', '=', 'financial_profile_user.user_id')
            ->leftJoin($shareTable, function ($join) use ($resourceColumn, $resourceId, $shareTable): void {
                $join->on($shareTable.'.user_id', '=', 'users.id')
                    ->where($shareTable.'.'.$resourceColumn, $resourceId);
            })
            ->where('financial_profile_user.profile_id', $profile->id)
            ->orderBy('financial_profile_user.created_at')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.email', $shareTable.'.permission'])
            ->map(fn ($member) => [
                'user_id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'permission' => $member->permission ?? ResourcePermissions::VIEWER,
                'explicit' => $member->permission !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * The resource's explicit share rows with user info (user-owned
     * resources have no implicit membership visibility). When the resource
     * owner is given, their row is listed first (labeled "owner" by the
     * Share drawer) — the drawer always shows a people-with-access list
     * anchored by the owner.
     *
     * @return array<int, array<string, int|string|bool>>
     */
    public function explicitPermissions(string $shareTable, string $resourceColumn, int $resourceId, ?User $owner = null): array
    {
        $shares = DB::table($shareTable)
            ->join('users', 'users.id', '=', $shareTable.'.user_id')
            ->where($shareTable.'.'.$resourceColumn, $resourceId)
            ->when($owner !== null, fn ($query) => $query->where('users.id', '!=', $owner->id))
            ->orderBy($shareTable.'.created_at')
            ->get(['users.id', 'users.name', 'users.email', $shareTable.'.permission'])
            ->map(fn ($member) => [
                'user_id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'permission' => $member->permission,
                'explicit' => true,
            ])
            ->values()
            ->all();

        if ($owner !== null) {
            array_unshift($shares, [
                'user_id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'permission' => ResourcePermissions::OWNER,
                'explicit' => true,
            ]);
        }

        return $shares;
    }

    /**
     * The target of a grant or transfer on a profile-scoped resource
     * (budgets, ledgers): a member of the resource's profile.
     *
     * @throws ValidationException
     */
    public function profileMember(FinancialProfile $profile, int $userId): User
    {
        return $this->eligibleTarget(
            $userId,
            fn (User $target): bool => $profile->isMember($target),
            __('The target user must be a member of the profile.'),
        );
    }

    /**
     * The target of a grant or transfer on a user-owned resource
     * (envelopes): a member of the owner's profiles.
     *
     * @throws ValidationException
     */
    public function ownerCoMember(User $owner, int $userId): User
    {
        return $this->eligibleTarget(
            $userId,
            fn (User $target): bool => FinancialProfile::membersShareAProfile($owner, $target),
            __('The target user must share a profile with the envelope owner.'),
        );
    }

    /**
     * An ownership-transfer target must not be the current owner.
     *
     * @throws ValidationException
     */
    public function ensureNotOwner(User $target, User $currentOwner, string $message): User
    {
        if ($target->id === $currentOwner->id) {
            throw ValidationException::withMessages(['user_id' => $message]);
        }

        return $target;
    }

    /**
     * Grant a share row after validation (no duplicates: $duplicateMessage
     * is reported on `user_id`).
     */
    public function createPermission(string $shareTable, string $resourceColumn, int $resourceId, int $userId, string $permission, string $duplicateMessage): void
    {
        $exists = DB::table($shareTable)
            ->where($resourceColumn, $resourceId)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'user_id' => $duplicateMessage,
            ]);
        }

        DB::table($shareTable)->insert([
            $resourceColumn => $resourceId,
            'user_id' => $userId,
            'permission' => $permission,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Change a member's permission. The owner row cannot be changed through
     * the drawer (transfer-ownership only).
     */
    public function updatePermission(string $shareTable, string $resourceColumn, int $resourceId, int $userId, string $permission): void
    {
        $current = DB::table($shareTable)
            ->where($resourceColumn, $resourceId)
            ->where('user_id', $userId)
            ->value('permission');

        if ($current === null) {
            abort(404);
        }

        if ($current === ResourcePermissions::OWNER) {
            throw ValidationException::withMessages([
                'share' => __('Use transfer-ownership to change the resource owner.'),
            ]);
        }

        DB::table($shareTable)
            ->where($resourceColumn, $resourceId)
            ->where('user_id', $userId)
            ->update(['permission' => $permission, 'updated_at' => now()]);
    }

    /**
     * Revoke a share. The owner row cannot be removed.
     */
    public function deletePermission(string $shareTable, string $resourceColumn, int $resourceId, int $userId): void
    {
        $permission = DB::table($shareTable)
            ->where($resourceColumn, $resourceId)
            ->where('user_id', $userId)
            ->value('permission');

        if ($permission === null) {
            abort(404);
        }

        if ($permission === ResourcePermissions::OWNER) {
            throw ValidationException::withMessages([
                'share' => __('The resource owner cannot be removed.'),
            ]);
        }

        DB::table($shareTable)
            ->where($resourceColumn, $resourceId)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * @param  Closure(User): bool  $eligible
     *
     * @throws ValidationException
     */
    private function eligibleTarget(int $userId, Closure $eligible, string $message): User
    {
        /** @var User|null $target */
        $target = User::query()->find($userId);

        if ($target === null || ! $eligible($target)) {
            throw ValidationException::withMessages(['user_id' => $message]);
        }

        return $target;
    }
}
