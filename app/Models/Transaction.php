<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ledger_id
 * @property int|null $user_id
 * @property int $envelope_id
 * @property int|null $budget_id
 * @property int $amount
 * @property Carbon $event_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Transaction extends Model
{
    protected $fillable = ['ledger_id', 'user_id', 'envelope_id', 'budget_id', 'amount', 'event_at'];

    protected $casts = [
        'event_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Retired budgets are deleted automatically when their last
        // referencing transaction is deleted (docs/budget.md — lifecycle).
        static::deleted(function (Transaction $transaction): void {
            Budget::sweepRetired(array_filter([$transaction->budget_id]));
        });
    }

    /**
     * The ledger this transaction belongs to.
     *
     * @return BelongsTo<Ledger, $this>
     */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    /**
     * The user who entered this transaction.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The envelope this transaction draws from.
     *
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class, 'envelope_id');
    }

    /**
     * The budget (snapshot) the transaction was recorded against — null when
     * the envelope was not part of a published budget at entry time.
     *
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }
}
