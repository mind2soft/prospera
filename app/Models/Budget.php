<?php

namespace App\Models;

use App\Models\Concerns\HasProfilePermissions;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $currency
 * @property int $effective_year
 * @property int $effective_month
 * @property Carbon|null $published_at
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Budget extends Model
{
    use HasProfilePermissions;

    protected $fillable = [
        'profile_id',
        'currency',
        'effective_year',
        'effective_month',
        'published_at',
        'retired_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    /**
     * The financial profile this budget belongs to.
     *
     * @return BelongsTo<FinancialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class);
    }

    /**
     * The budget's permission rows (ownership is the "owner" row — there is
     * no owner column on budgets).
     *
     * @return HasMany<BudgetPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(BudgetPermission::class);
    }

    /**
     * The envelopes assigned to this budget.
     *
     * @return HasMany<BudgetEnvelope, $this>
     */
    public function envelopes(): HasMany
    {
        return $this->hasMany(BudgetEnvelope::class);
    }

    /**
     * Whether the budget is in draft mode.
     */
    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    /**
     * Whether the budget is retired (replaced by a revision while
     * transactions still reference it — hidden from the selector).
     */
    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * The transactions recorded against this budget (snapshot budget).
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Delete the given retired budgets that no transaction references any
     * more. Called after transactions are deleted (individually or in bulk).
     *
     * @param  iterable<int>  $budgetIds
     */
    public static function sweepRetired(iterable $budgetIds): void
    {
        // Nulls are expected since budget_id is nullable on transactions.
        $ids = collect($budgetIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        self::query()
            ->whereIn('id', $ids)
            ->whereNotNull('retired_at')
            ->whereDoesntHave('transactions')
            ->get()
            ->each(fn (Budget $budget) => $budget->delete());
    }

    /**
     * Scope non-retired budgets (draft first, then published, newest first —
     * the selector order). Retired budgets are excluded.
     *
     * @param  Builder<Budget>  $query
     */
    #[Scope]
    protected function nonRetired(Builder $query): void
    {
        $query->whereNull('retired_at');
    }
}
