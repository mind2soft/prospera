<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $budget_id
 * @property int $envelope_id
 * @property int $amount
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BudgetEnvelope extends Model
{
    public const TYPE_FIXED = 'fixed';

    public const TYPE_VARIABLE = 'variable';

    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_VARIABLE,
    ];

    protected $fillable = ['budget_id', 'envelope_id', 'amount', 'type'];

    /**
     * The budget this assignment belongs to.
     *
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * The envelope this assignment points to.
     *
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class, 'envelope_id');
    }
}
