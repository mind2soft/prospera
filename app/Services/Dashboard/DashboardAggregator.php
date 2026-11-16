<?php

namespace App\Services\Dashboard;

use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Security\Amounts\AmountKeyRing;
use App\Services\ResourcePermissions;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes the server-side aggregate payloads for dashboard widgets
 * (docs/dashboard.md). Amounts are encrypted at rest (docs/encryption.md):
 * transactions are streamed in bounded chunks and summed in PHP — never
 * aggregated in SQL.
 *
 * Masking rule: any aggregate that includes an envelope the requester
 * cannot amount-see (envelope permission below viewer) is masked —
 * `amount: null` + `masked: true`. Grouped sums never leak member amounts
 * by omission: a group containing a masked member is masked as a whole.
 */
final class DashboardAggregator
{
    /**
     * The widget-type-specific aggregate payload.
     *
     * @return array<string, mixed>
     */
    public function data(DashboardWidget $widget, FinancialProfile $profile, User $user, ?CarbonInterface $today = null): array
    {
        // "Today" is the requester's calendar day (LocalCalendar).
        $today = $today === null ? CarbonImmutable::today() : CarbonImmutable::instance($today);

        $selection = WidgetSelection::fromConfig($widget->selection);

        // One query: the selected envelopes with the requester's share rows.
        // Its keys prune deleted envelopes (no orphan rows); the loaded
        // share rows resolve the masking without further queries.
        $envelopes = SelectionEnvelopes::loadFor($user, $selection->envelopeIds());
        $selection = $selection->pruned($envelopes->keys()->all());

        $envelopeIds = $selection->envelopeIds();
        $period = WidgetPeriod::fromConfig($widget->periodConfig());

        if ($envelopeIds === []) {
            return $this->emptyPayload($widget, $period, $today);
        }

        $coMemberIds = FinancialProfile::coMemberIdsOf($user->id);
        $amountVisible = $envelopes->map(
            fn (Envelope $envelope): bool => $envelope->permissionFor($user, $coMemberIds) !== ResourcePermissions::NONE,
        );

        // One streaming scan per request: rows are decoded once and added to
        // the accumulators of every aggregate shape the widget needs.
        $scan = TransactionScan::run(
            $profile,
            $period,
            $envelopeIds,
            $today,
            $this->decryptableEnvelopeIds($widget->type, $envelopeIds, $amountVisible),
        );

        return match ($widget->type) {
            DashboardWidget::TYPE_ENVELOPE_SPENDING => $this->envelopeSpending(
                $profile, $period, $selection, $envelopes, $amountVisible, $scan, $today,
            ),
            DashboardWidget::TYPE_INCOME_EXPENSES => $this->incomeExpenses(
                $period, $selection, $amountVisible, $scan, $today,
            ),
            DashboardWidget::TYPE_RADIAL => $this->radial(
                $selection, $envelopes, $amountVisible, $scan,
            ),
            default => throw new \InvalidArgumentException("Unknown widget type [{$widget->type}]."),
        };
    }

    /**
     * The envelope ids whose amounts may be exposed in the widget's
     * response. Rows outside this set are never fetched or decrypted: every
     * aggregate that includes a masked envelope is masked as a whole
     * (docs/dashboard.md), so masked rows never contribute to an exposed
     * value.
     *
     * @param  list<int>  $envelopeIds
     * @param  Collection<int, bool>  $amountVisible
     * @return list<int>|null null = all selected envelopes may be exposed
     */
    private function decryptableEnvelopeIds(string $widgetType, array $envelopeIds, Collection $amountVisible): ?array
    {
        if ($widgetType === DashboardWidget::TYPE_INCOME_EXPENSES) {
            // The whole series is masked when any selected envelope is
            // masked — then no amount is exposed at all.
            $allVisible = collect($envelopeIds)->every(
                fn (int $envelopeId): bool => $amountVisible->get($envelopeId, false) === true,
            );

            return $allVisible ? null : [];
        }

        // Per-envelope masking: only the visible envelopes' amounts are
        // exposed (spending rows and radial rows for masked envelopes are
        // null, and groups containing a masked member are masked as a whole).
        return array_values($amountVisible
            ->filter(fn (bool $visible): bool => $visible)
            ->keys()
            ->all());
    }

    /**
     * Envelope spending: per selected envelope/group, the sum of recorded
     * transactions over the period plus the budget progress denominator
     * (sum of the published budgets' allocations across the covered
     * months). Rows without a covering allocation report `allocated: null`
     * (empty progress bar) but still show the spent total.
     *
     * @param  Collection<int, Envelope>  $envelopes
     * @param  Collection<int, bool>  $amountVisible
     * @return array<string, mixed>
     */
    private function envelopeSpending(
        FinancialProfile $profile,
        WidgetPeriod $period,
        WidgetSelection $selection,
        Collection $envelopes,
        Collection $amountVisible,
        TransactionScan $scan,
        CarbonImmutable $today,
    ): array {
        $totals = $scan->totalsByEnvelope();
        $allocations = $this->allocationsByEnvelope($profile, $period, $selection->envelopeIds(), $today);

        $rows = [];

        foreach ($selection->items() as $index => $item) {
            if ($item['kind'] === 'envelope') {
                $envelopeId = $item['envelope_id'];
                $envelope = $envelopes->get($envelopeId);

                if ($envelope === null) {
                    continue;
                }

                $masked = $amountVisible->get($envelopeId, false) === false;

                $rows[] = [
                    'key' => 'e'.$envelopeId,
                    'kind' => 'envelope',
                    'envelope_id' => $envelopeId,
                    'name' => $envelope->name,
                    'color' => $envelope->color,
                    'direction' => $envelope->type,
                    'masked' => $masked,
                    'spent' => $masked ? null : Money::fromMinorUnits($totals->get($envelopeId, 0)),
                    'allocated' => $masked ? null : $allocations->get($envelopeId),
                ];
            } else {
                $memberIds = $item['envelope_ids'];
                $masked = collect($memberIds)->contains(
                    fn (int $envelopeId): bool => $amountVisible->get($envelopeId, false) === false,
                );

                // A group containing a masked member is masked as a whole —
                // the sum never leaks member amounts by omission.
                $spent = $masked
                    ? null
                    : Money::fromMinorUnits(collect($memberIds)->sum(fn (int $envelopeId): int => $totals->get($envelopeId, 0)));

                $memberAllocations = collect($memberIds)
                    ->map(fn (int $envelopeId): ?int => $allocations->get($envelopeId))
                    ->filter(fn (?int $allocation): bool => $allocation !== null);

                $rows[] = [
                    'key' => 'g'.$index,
                    'kind' => 'group',
                    'name' => $item['name'],
                    'masked' => $masked,
                    'spent' => $spent,
                    // Sum of the members' allocations; null when none of the
                    // members is allocated on a covering budget.
                    'allocated' => $masked ? null : ($memberAllocations->isEmpty() ? null : $memberAllocations->sum()),
                ];
            }
        }

        return [
            'type' => DashboardWidget::TYPE_ENVELOPE_SPENDING,
            'rows' => $rows,
        ];
    }

    /**
     * Income & expenses history: a time-series of revenues and expenses
     * (signed convention) bucketed per day for `days` periods, per calendar
     * month for `months` periods. If any selected envelope is not
     * amount-visible to the requester, the whole series is masked — bucket
     * sums must not reveal masked amounts by omission.
     *
     * @param  Collection<int, bool>  $amountVisible
     * @return array<string, mixed>
     */
    private function incomeExpenses(
        WidgetPeriod $period,
        WidgetSelection $selection,
        Collection $amountVisible,
        TransactionScan $scan,
        CarbonImmutable $today,
    ): array {
        $masked = collect($selection->envelopeIds())->contains(
            fn (int $envelopeId): bool => $amountVisible->get($envelopeId, false) === false,
        );

        // Per bucket, the summed revenue and expenses (encrypted at rest —
        // summed in PHP, in exact minor units).
        $byBucket = $scan->bucketTotals()->map(fn (array $totals): array => [
            'revenue' => Money::fromMinorUnits($totals['revenue']),
            'expenses' => Money::fromMinorUnits($totals['expenses']),
        ]);

        $series = [];

        foreach ($period->buckets($today) as $bucket) {
            $amounts = $byBucket->get($bucket, ['revenue' => 0, 'expenses' => 0]);

            $series[] = [
                'bucket' => $bucket,
                'masked' => $masked,
                'revenue' => $masked ? null : $amounts['revenue'],
                'expenses' => $masked ? null : $amounts['expenses'],
            ];
        }

        return [
            'type' => DashboardWidget::TYPE_INCOME_EXPENSES,
            'masked' => $masked,
            'series' => $series,
        ];
    }

    /**
     * Radial: the ratio of the selected envelopes' absolute totals
     * (v = |a|). Masked envelopes/groups report `value: null` and keep
     * their metadata so the legend stays complete without leaking amounts.
     *
     * @param  Collection<int, Envelope>  $envelopes
     * @param  Collection<int, bool>  $amountVisible
     * @return array<string, mixed>
     */
    private function radial(
        WidgetSelection $selection,
        Collection $envelopes,
        Collection $amountVisible,
        TransactionScan $scan,
    ): array {
        $totals = $scan->totalsByEnvelope();

        $rows = [];

        foreach ($selection->items() as $index => $item) {
            if ($item['kind'] === 'envelope') {
                $envelopeId = $item['envelope_id'];
                $envelope = $envelopes->get($envelopeId);

                if ($envelope === null) {
                    continue;
                }

                $masked = $amountVisible->get($envelopeId, false) === false;

                $rows[] = [
                    'key' => 'e'.$envelopeId,
                    'kind' => 'envelope',
                    'envelope_id' => $envelopeId,
                    'name' => $envelope->name,
                    'color' => $envelope->color,
                    'masked' => $masked,
                    'value' => $masked ? null : Money::fromMinorUnits(abs($totals->get($envelopeId, 0))),
                ];
            } else {
                $memberIds = $item['envelope_ids'];
                $masked = collect($memberIds)->contains(
                    fn (int $envelopeId): bool => $amountVisible->get($envelopeId, false) === false,
                );

                $rows[] = [
                    'key' => 'g'.$index,
                    'kind' => 'group',
                    'name' => $item['name'],
                    'masked' => $masked,
                    'value' => $masked
                        ? null
                        : Money::fromMinorUnits(abs(collect($memberIds)->sum(fn (int $envelopeId): int => $totals->get($envelopeId, 0)))),
                ];
            }
        }

        return [
            'type' => DashboardWidget::TYPE_RADIAL,
            'rows' => $rows,
        ];
    }

    /**
     * The payload for a selection that references no (existing) envelope:
     * empty rows/series with the correct shape.
     *
     * @return array<string, mixed>
     */
    private function emptyPayload(DashboardWidget $widget, WidgetPeriod $period, CarbonImmutable $today): array
    {
        return match ($widget->type) {
            DashboardWidget::TYPE_ENVELOPE_SPENDING => [
                'type' => $widget->type,
                'rows' => [],
            ],
            DashboardWidget::TYPE_INCOME_EXPENSES => [
                'type' => $widget->type,
                'masked' => false,
                'series' => collect($period->buckets($today))->map(fn (string $bucket): array => [
                    'bucket' => $bucket,
                    'masked' => false,
                    'revenue' => 0,
                    'expenses' => 0,
                ])->all(),
            ],
            default => [
                'type' => $widget->type,
                'rows' => [],
            ],
        };
    }

    /**
     * The budget progress denominator per envelope: the sum of the
     * allocations on the published budgets covering the period's months.
     * Allocations are encrypted at rest and summed in PHP. Envelopes not
     * allocated on any covering budget are absent from the result — the
     * progress bar renders empty.
     *
     * @param  list<int>  $envelopeIds
     * @return Collection<int, int> envelope id → summed allocation
     */
    private function allocationsByEnvelope(FinancialProfile $profile, WidgetPeriod $period, array $envelopeIds, CarbonImmutable $today): Collection
    {
        ['from' => $from, 'to' => $to] = $period->window($today);

        // The calendar months the period window touches.
        $months = [];

        $cursor = $from->startOfMonth();

        while ($cursor->lte($to)) {
            $months[] = [$cursor->year, $cursor->month];

            $cursor = $cursor->addMonth();
        }

        // Months are plaintext (only amounts are encrypted): filtered in SQL.
        $budgetIds = Budget::query()
            ->where('profile_id', $profile->id)
            ->whereNotNull('published_at')
            ->where(function (Builder $query) use ($months): void {
                foreach ($months as [$year, $month]) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('effective_year', $year)
                        ->where('effective_month', $month));
                }
            })
            ->pluck('id');

        if ($budgetIds->isEmpty()) {
            return collect();
        }

        app(AmountKeyRing::class)->rememberBudgetProfiles($budgetIds, $profile->id);

        return BudgetEnvelope::query()
            ->whereIn('budget_id', $budgetIds)
            ->whereIn('envelope_id', $envelopeIds)
            ->get()
            ->groupBy(fn (BudgetEnvelope $allocation): int => $allocation->envelope_id)
            ->map(fn (Collection $group): int => (int) $group->sum(
                fn (BudgetEnvelope $allocation): int => $allocation->amount,
            ));
    }
}
