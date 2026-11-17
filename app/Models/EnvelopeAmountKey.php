<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned envelope-scoped data key, wrapped with APP_KEY, never plaintext.
 *
 * @property int $id
 * @property int $envelope_id
 * @property int $version
 * @property string $wrapped_key
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EnvelopeAmountKey extends Model
{
    protected $fillable = ['envelope_id', 'version', 'wrapped_key', 'retired_at'];

    protected $hidden = ['wrapped_key'];

    protected $casts = ['version' => 'integer', 'retired_at' => 'datetime'];

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
