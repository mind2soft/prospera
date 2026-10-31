<?php

namespace App\Models;

use App\Services\ResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $envelope_id
 * @property int $user_id
 * @property string $permission
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EnvelopePermission extends Model
{
    public const PERMISSION_OWNER = ResourcePermissions::OWNER;

    public const PERMISSION_CONTRIBUTOR = ResourcePermissions::CONTRIBUTOR;

    public const PERMISSION_VIEWER = ResourcePermissions::VIEWER;

    protected $fillable = ['envelope_id', 'user_id', 'permission'];

    /**
     * The envelope this share belongs to.
     *
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class, 'envelope_id');
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
