<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivot table for profile membership (table: financial_profile_user).
 *
 * @property int $profile_id
 * @property int $user_id
 * @property bool $is_owner
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialProfileUser extends Pivot
{
    protected $table = 'financial_profile_user';

    protected $casts = [
        'is_owner' => 'boolean',
        'is_default' => 'boolean',
    ];
}
