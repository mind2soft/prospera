<?php

namespace App\Services;

use Illuminate\Auth\Access\Response;

/**
 * Resource-level permission resolution, shared by the budget, ledger and
 * envelope policies and controllers (docs/shared-finances.md — permission model).
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

    /**
     * Permissions the Share drawer may grant ("owner" is reserved for
     * transfer-ownership).
     */
    public const GRANTABLE = [self::CONTRIBUTOR, self::VIEWER];

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
     * Authorization outcome for profile-scoped resources (budgets, ledgers),
     * used by their policies: "none" means the resource is invisible to the
     * caller (404); anything below the required minimum is forbidden (403).
     */
    public static function inspect(string $permission, string $minimum): Response
    {
        if ($permission === self::NONE) {
            return Response::denyAsNotFound();
        }

        return self::HIERARCHY[$permission] >= self::HIERARCHY[$minimum]
            ? Response::allow()
            : Response::denyWithStatus(403);
    }
}
