<?php

namespace App\Models;

use App\Models\Concerns\HasProfilePermissions;
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
    ];

    protected $casts = [
        'published_at' => 'datetime',
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
}
