<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The save revision of a user's dashboard layout for one financial profile
 * (issue #55). The row exists independently of the widget rows — a layout
 * with zero widgets still has a revision — and every write to the layout
 * (full save or widget removal) compares and increments it under a lock, so
 * a stale or concurrent save can never overwrite newer state.
 *
 * @property int $id
 * @property int $user_id
 * @property int $financial_profile_id
 * @property int $revision starts at INITIAL_REVISION, incremented on every write
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DashboardLayout extends Model
{
    /** The revision a layout that was never saved presents. */
    public const INITIAL_REVISION = 1;

    protected $fillable = [
        'user_id',
        'financial_profile_id',
        'revision',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'financial_profile_id' => 'integer',
        'revision' => 'integer',
    ];

    /**
     * The layout row of the given user's dashboard for the given profile.
     *
     * @return Builder<static>
     */
    public static function forUserAndProfile(int $userId, int $profileId): Builder
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('financial_profile_id', $profileId);
    }
}
