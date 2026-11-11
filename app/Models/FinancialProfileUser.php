<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot table for profile membership (table: financial_profile_user).
 *
 * @property int $profile_id
 * @property int $user_id
 * @property bool $is_owner
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FinancialProfileUser extends Pivot
{
    protected $table = 'financial_profile_user';

    protected $casts = [
        'is_owner' => 'boolean',
        'is_default' => 'boolean',
    ];
}
