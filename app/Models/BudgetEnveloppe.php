<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $budget_id
 * @property int $enveloppe_id
 * @property int $amount
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BudgetEnveloppe extends Model
{
    public const TYPE_FIXED = 'fixed';

    public const TYPE_VARIABLE = 'variable';

    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_VARIABLE,
    ];

    protected $fillable = ['budget_id', 'enveloppe_id', 'amount', 'type'];

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
     * The enveloppe this assignment points to.
     *
     * @return BelongsTo<Enveloppe, $this>
     */
    public function enveloppe(): BelongsTo
    {
        return $this->belongsTo(Enveloppe::class, 'enveloppe_id');
    }
}
