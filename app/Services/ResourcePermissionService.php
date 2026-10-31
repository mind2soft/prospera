<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Http\Request;
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
     * (row permission, or implicit viewer for members without a row).
     *
     * @return array<int, array<string, int|string>>
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
            ])
            ->values()
            ->all();
    }

    /**
     * The resource's explicit share rows with user info (user-owned
     * resources have no implicit membership visibility).
     *
     * @return array<int, array<string, int|string>>
     */
    public function explicitPermissions(string $shareTable, string $resourceColumn, int $resourceId): array
    {
        return DB::table($shareTable)
            ->join('users', 'users.id', '=', $shareTable.'.user_id')
            ->where($shareTable.'.'.$resourceColumn, $resourceId)
            ->orderBy($shareTable.'.created_at')
            ->get(['users.id', 'users.name', 'users.email', $shareTable.'.permission'])
            ->map(fn ($member) => [
                'user_id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'permission' => $member->permission,
            ])
            ->values()
            ->all();
    }

    /**
     * Validate a grant payload {user_id, permission} for a profile-scoped
     * resource: the target must be a member of the profile and the permission
     * grantable ("owner" is reserved for transfer-ownership).
     *
     * @return array{user_id: int, permission: string}
     */
    public function validateProfilePermission(Request $request, FinancialProfile $profile): array
    {
        $validated = $this->validatePayload($request);

        /** @var User|null $target */
        $target = User::query()->find($validated['user_id']);

        if ($target === null || ! $profile->isMember($target)) {
            throw ValidationException::withMessages([
                'user_id' => __('The target user must be a member of the profile.'),
            ]);
        }

        return [
            'user_id' => (int) $validated['user_id'],
            'permission' => $validated['permission'],
        ];
    }

    /**
     * Validate a grant payload for a user-owned resource: the target must be
     * a member of the owner's profiles.
     *
     * @return array{user_id: int, permission: string}
     */
    public function validateOwnedPermission(Request $request, User $owner): array
    {
        $validated = $this->validatePayload($request);

        /** @var User|null $target */
        $target = User::query()->find($validated['user_id']);

        if ($target === null || ! FinancialProfile::membersShareAProfile($owner, $target)) {
            throw ValidationException::withMessages([
                'user_id' => __('The target user must share a profile with the envelope owner.'),
            ]);
        }

        return [
            'user_id' => (int) $validated['user_id'],
            'permission' => $validated['permission'],
        ];
    }

    /**
     * Validate a permission-only payload ("owner" is rejected — use
     * transfer-ownership).
     */
    public function validatePermission(Request $request): string
    {
        $validated = $request->validate([
            'permission' => ['required', 'string'],
        ]);

        if (! ResourcePermissions::isGrantable($validated['permission'])) {
            throw ValidationException::withMessages([
                'permission' => __('The "owner" permission cannot be granted — use transfer-ownership.'),
            ]);
        }

        return $validated['permission'];
    }

    /**
     * Grant a share row after validation (no duplicates).
     */
    public function createPermission(string $shareTable, string $resourceColumn, int $resourceId, int $userId, string $permission, string $resourceLabel): void
    {
        $exists = DB::table($shareTable)
            ->where($resourceColumn, $resourceId)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'user_id' => __('This :resource is already shared with this user.', ['resource' => $resourceLabel]),
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
     * Validate a {user_id} ownership-transfer target for a profile-scoped
     * resource: an existing member other than the current owner.
     */
    public function validateProfileTransferTarget(Request $request, FinancialProfile $profile, User $currentOwner, string $resourceLabel): User
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        /** @var User|null $target */
        $target = User::query()->find($validated['user_id']);

        if ($target === null || ! $profile->isMember($target)) {
            throw ValidationException::withMessages([
                'user_id' => __('The target user must be a member of the profile.'),
            ]);
        }

        if ($target->id === $currentOwner->id) {
            throw ValidationException::withMessages([
                'user_id' => __('You already own this :resource.', ['resource' => $resourceLabel]),
            ]);
        }

        return $target;
    }

    /**
     * Validate a {user_id} ownership-transfer target for a user-owned
     * resource: a member of the owner's profiles other than the owner.
     */
    public function validateOwnedTransferTarget(Request $request, User $owner): User
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        /** @var User|null $target */
        $target = User::query()->find($validated['user_id']);

        if ($target === null || ! FinancialProfile::membersShareAProfile($owner, $target)) {
            throw ValidationException::withMessages([
                'user_id' => __('The target user must share a profile with the envelope owner.'),
            ]);
        }

        if ($target->id === $owner->id) {
            throw ValidationException::withMessages([
                'user_id' => __('This user already owns this envelope.'),
            ]);
        }

        return $target;
    }

    /**
     * @return array{user_id: mixed, permission: string}
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'permission' => ['required', 'string'],
        ]);

        if (! ResourcePermissions::isGrantable($validated['permission'])) {
            throw ValidationException::withMessages([
                'permission' => __('The "owner" permission cannot be granted — use transfer-ownership.'),
            ]);
        }

        return $validated;
    }
}
