<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A versioned per-profile amount data key, stored wrapped (encrypted) by the
 * application key. See docs/encryption.md.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $version
 * @property string $wrapped_key
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialProfileKey extends Model
{
    protected $fillable = ['profile_id', 'version', 'wrapped_key', 'retired_at'];

    protected $hidden = ['wrapped_key'];

    protected $casts = [
        'version' => 'integer',
        'retired_at' => 'datetime',
    ];

    /**
     * The financial profile this key encrypts amounts for.
     *
     * @return BelongsTo<FinancialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class);
    }
}
