<?php

namespace App\Services;

/**
 * Resource-level permission resolution, shared by the budget, ledger and
 * envelope controllers (docs/shared-finances.md — permission model).
 *
 * Effective permissions are "owner" | "contributor" | "viewer" | "none":
 * - budgets and ledgers: the resource's permission row, or implicit
 *   viewer-equivalent read-only for profile members;
 * - envelopes: the envelope's permission row, "owner" for the creator, or
 *   "none" (metadata-only) — no implicit viewer visibility.
 */
final class ResourcePermissions
{
    public const NONE = 'none';

    public const VIEWER = 'viewer';

    public const CONTRIBUTOR = 'contributor';

    public const OWNER = 'owner';

    private const HIERARCHY = [
        self::NONE => 0,
        self::VIEWER => 1,
        self::CONTRIBUTOR => 2,
        self::OWNER => 3,
    ];

    /**
     * Whether the effective permission satisfies the required minimum.
     */
    public function isAtLeast(string $permission, string $minimum): bool
    {
        return self::HIERARCHY[$permission] >= self::HIERARCHY[$minimum];
    }

    /**
     * Authorization guard for profile-scoped resources (budgets, ledgers):
     * "none" means the resource is invisible to the caller (404); anything
     * below the required minimum is forbidden (403). Returns the effective
     * permission so callers can branch on it.
     */
    public function authorize(string $permission, string $minimum): string
    {
        if ($permission === self::NONE) {
            abort(404);
        }

        if (! $this->isAtLeast($permission, $minimum)) {
            abort(403);
        }

        return $permission;
    }

    /**
     * Whether the given permission row value is a grantable permission
     * ("owner" is reserved for transfer-ownership).
     */
    public static function isGrantable(string $permission): bool
    {
        return in_array($permission, [self::CONTRIBUTOR, self::VIEWER], true);
    }
}
