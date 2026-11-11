<?php

namespace App\Models;

use App\Services\ResourcePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $budget_id
 * @property int $user_id
 * @property string $permission
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class BudgetPermission extends Model
{
    public const PERMISSION_OWNER = ResourcePermissions::OWNER;

    public const PERMISSION_CONTRIBUTOR = ResourcePermissions::CONTRIBUTOR;

    public const PERMISSION_VIEWER = ResourcePermissions::VIEWER;

    protected $fillable = ['budget_id', 'user_id', 'permission'];

    /**
     * The budget this share belongs to.
     *
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * The user this share was granted to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
