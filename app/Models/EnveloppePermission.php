<?php

namespace App\Models;

use App\Services\ResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $enveloppe_id
 * @property int $user_id
 * @property string $permission
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EnveloppePermission extends Model
{
    public const PERMISSION_OWNER = ResourcePermissions::OWNER;

    public const PERMISSION_CONTRIBUTOR = ResourcePermissions::CONTRIBUTOR;

    public const PERMISSION_VIEWER = ResourcePermissions::VIEWER;

    protected $fillable = ['enveloppe_id', 'user_id', 'permission'];

    /**
     * The enveloppe this share belongs to.
     *
     * @return BelongsTo<Enveloppe, $this>
     */
    public function enveloppe(): BelongsTo
    {
        return $this->belongsTo(Enveloppe::class, 'enveloppe_id');
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
