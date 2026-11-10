<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single dashboard widget of a user's dashboard for one financial profile
 * (docs/dashboard.md). Configuration only — the displayed amounts are always
 * computed server-side by the widget data endpoint, never stored.
 *
 * @property int $id
 * @property int $user_id
 * @property int $financial_profile_id
 * @property string $type
 * @property string|null $title null → the client renders the localized default title
 * @property string $view_type
 * @property int $column_width 1..5
 * @property string $period_type days | months
 * @property int $period_range canonical enumeration, see PERIOD_RANGES
 * @property array<int, array<string, mixed>> $selection
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DashboardWidget extends Model
{
    public const TYPE_ENVELOPE_SPENDING = 'envelope_spending';

    public const TYPE_INCOME_EXPENSES = 'income_expenses';

    public const TYPE_RADIAL = 'radial';

    /** The widget catalog (issue #29). */
    public const TYPES = [
        self::TYPE_ENVELOPE_SPENDING,
        self::TYPE_INCOME_EXPENSES,
        self::TYPE_RADIAL,
    ];

    /**
     * The valid view types per widget type. `envelope_spending` has a single
     * view; the charts offer alternates.
     */
    public const VIEW_TYPES = [
        self::TYPE_ENVELOPE_SPENDING => ['single'],
        self::TYPE_INCOME_EXPENSES => ['stacked_bars', 'line'],
        self::TYPE_RADIAL => ['pie', 'donut'],
    ];

    /**
     * Canonical period enumeration (issue #29): days — today, past 2/3 days,
     * past week (7), past 2 weeks (14); months — current month, past 2/3/6/12
     * months. The value is the number of days / months the period spans.
     */
    public const PERIOD_RANGES = [
        'days' => [1, 2, 3, 7, 14],
        'months' => [1, 2, 3, 6, 12],
    ];

    public const PERIOD_DAYS = 'days';

    public const PERIOD_MONTHS = 'months';

    public const MIN_COLUMN_WIDTH = 1;

    public const MAX_COLUMN_WIDTH = 5;

    protected $fillable = [
        'user_id',
        'financial_profile_id',
        'type',
        'title',
        'view_type',
        'column_width',
        'period_type',
        'period_range',
        'selection',
        'sort_order',
    ];

    protected $casts = [
        'selection' => 'array',
        'column_width' => 'integer',
        'period_range' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * The owning user (each member has their own dashboard per profile).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The financial profile the widget is configured for.
     *
     * @return BelongsTo<FinancialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'financial_profile_id');
    }

    /**
     * Widgets of the given user's dashboard for the given profile, in
     * display order.
     *
     * @return Builder<static>
     */
    public static function scopeFor(int $userId, int $profileId): Builder
    {
        $query = self::query();

        $query
            ->where('user_id', $userId)
            ->where('financial_profile_id', $profileId)
            ->orderBy('sort_order')
            ->orderBy('id');

        return $query;
    }

    /**
     * Whether the widget belongs to the given user's dashboard for the given
     * profile.
     */
    public function isOwnedBy(int $userId, int $profileId): bool
    {
        return $this->user_id === $userId && $this->financial_profile_id === $profileId;
    }

    /**
     * The period configuration as sent over the API.
     *
     * @return array{type: string, range: int}
     */
    public function periodConfig(): array
    {
        return ['type' => $this->period_type, 'range' => $this->period_range];
    }
}
