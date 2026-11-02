<?php

namespace App\Models;

use App\Models\Concerns\HasProfilePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $starting_balance
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Ledger extends Model
{
    use HasProfilePermissions;

    protected $fillable = ['profile_id', 'name', 'start_date', 'end_date', 'starting_balance', 'closed_at'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];

    /**
     * The financial profile this ledger belongs to.
     *
     * @return BelongsTo<FinancialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class);
    }

    /**
     * The ledger's permission rows.
     *
     * @return HasMany<LedgerPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(LedgerPermission::class);
    }

    /**
     * The transactions recorded in this ledger.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Delete the ledger and all its transactions in bulk.
     */
    public function deleteWithTransactions(): void
    {
        DB::transaction(function (): void {
            $this->transactions()->delete();
            $this->delete();
        });
    }

    /**
     * Whether the ledger is open (transactions can still be added).
     */
    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Whether the ledger is closed (read-only).
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Whether a transaction can be entered on the given date: the ledger must
     * be open and the date must fall within the ledger's range.
     */
    public function acceptsEventDate(Carbon $date): bool
    {
        return $this->isOpen()
            && $date->between($this->start_date, $this->end_date);
    }
}
