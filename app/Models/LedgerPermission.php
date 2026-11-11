<?php

namespace App\Models;

use App\Services\ResourcePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ledger_id
 * @property int $user_id
 * @property string $permission
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LedgerPermission extends Model
{
    public const PERMISSION_OWNER = ResourcePermissions::OWNER;

    public const PERMISSION_CONTRIBUTOR = ResourcePermissions::CONTRIBUTOR;

    public const PERMISSION_VIEWER = ResourcePermissions::VIEWER;

    protected $fillable = ['ledger_id', 'user_id', 'permission'];

    /**
     * The ledger this share belongs to.
     *
     * @return BelongsTo<Ledger, $this>
     */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
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
