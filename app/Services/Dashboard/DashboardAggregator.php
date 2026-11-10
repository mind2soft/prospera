<?php

namespace App\Services\Dashboard;

use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Support\Collection;

/**
 * Computes the server-side aggregate payloads for dashboard widgets
 * (docs/dashboard.md). Amounts are encrypted at rest
 * (docs/encryption.md): transactions and budget allocations are hydrated
 * as models and summed in PHP — never aggregated in SQL.
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
    public function data(DashboardWidget $widget, FinancialProfile $profile, User $user): array
    {
        $selection = WidgetSelection::fromConfig($widget->selection);

        $envelopeIds = $selection->envelopeIds();

        // Prune deleted envelopes before computing (no orphan rows).
        if ($envelopeIds !== []) {
            $existing = Envelope::query()
                ->whereIn('id', $envelopeIds)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();
            $selection = $selection->pruned($existing);
        }

        $envelopeIds = $selection->envelopeIds();
        $period = WidgetPeriod::fromConfig($widget->periodConfig());

        if ($envelopeIds === []) {
            return $this->emptyPayload($widget, $period);
        }

        $envelopes = Envelope::query()->whereIn('id', $envelopeIds)->get()->keyBy('id');

        $permissions = Envelope::permissionsFor($user, $envelopeIds);

        $amountVisible = $permissions->mapWithKeys(
            fn (string $permission, int $envelopeId): array => [
                $envelopeId => $permission !== ResourcePermissions::NONE,
            ],
        );

        $transactions = $this->transactions($profile, $period, $envelopeIds);

        return match ($widget->type) {
            DashboardWidget::TYPE_ENVELOPE_SPENDING => $this->envelopeSpending(
                $widget, $profile, $period, $selection, $envelopes, $amountVisible, $transactions,
            ),
            DashboardWidget::TYPE_INCOME_EXPENSES => $this->incomeExpenses(
                $widget, $period, $selection, $amountVisible, $transactions,
            ),
            DashboardWidget::TYPE_RADIAL => $this->radial(
                $widget, $period, $selection, $envelopes, $amountVisible, $transactions,
            ),
            default => throw new \InvalidArgumentException("Unknown widget type [{$widget->type}]."),
        };
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
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, mixed>
     */
    private function envelopeSpending(
        DashboardWidget $widget,
        FinancialProfile $profile,
        WidgetPeriod $period,
        WidgetSelection $selection,
        Collection $envelopes,
        Collection $amountVisible,
        Collection $transactions,
    ): array {
        $totals = $this->totalsByEnvelope($transactions);
        $allocations = $this->allocationsByEnvelope($profile, $period, $selection->envelopeIds());

        $rows = [];

        foreach ($selection->items() as $item) {
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
                    'spent' => $masked ? null : $totals->get($envelopeId, 0),
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
                    : collect($memberIds)->sum(fn (int $envelopeId): int => $totals->get($envelopeId, 0));

                $memberAllocations = collect($memberIds)
                    ->map(fn (int $envelopeId): ?int => $allocations->get($envelopeId))
                    ->filter(fn (?int $allocation): bool => $allocation !== null);

                $rows[] = [
                    'key' => 'g'.$item['name'],
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
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, mixed>
     */
    private function incomeExpenses(
        DashboardWidget $widget,
        WidgetPeriod $period,
        WidgetSelection $selection,
        Collection $amountVisible,
        Collection $transactions,
    ): array {
        $masked = collect($selection->envelopeIds())->contains(
            fn (int $envelopeId): bool => $amountVisible->get($envelopeId, false) === false,
        );

        // Per envelope, per bucket, the summed amount (encrypted at rest —
        // summed in PHP).
        $byBucket = $transactions
            ->groupBy(fn (Transaction $transaction): string => $period->bucketKey($transaction->event_at))
            ->map(fn (Collection $group): array => [
                'revenue' => (int) $group->filter(
                    fn (Transaction $transaction): bool => $transaction->amount > 0,
                )->sum(fn (Transaction $transaction): int => $transaction->amount),
                'expenses' => (int) $group->filter(
                    fn (Transaction $transaction): bool => $transaction->amount < 0,
                )->sum(fn (Transaction $transaction): int => $transaction->amount),
            ]);

        $series = [];

        foreach ($period->buckets() as $bucket) {
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
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, mixed>
     */
    private function radial(
        DashboardWidget $widget,
        WidgetPeriod $period,
        WidgetSelection $selection,
        Collection $envelopes,
        Collection $amountVisible,
        Collection $transactions,
    ): array {
        $totals = $this->totalsByEnvelope($transactions);

        $rows = [];

        foreach ($selection->items() as $item) {
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
                    'value' => $masked ? null : abs($totals->get($envelopeId, 0)),
                ];
            } else {
                $memberIds = $item['envelope_ids'];
                $masked = collect($memberIds)->contains(
                    fn (int $envelopeId): bool => $amountVisible->get($envelopeId, false) === false,
                );

                $rows[] = [
                    'key' => 'g'.$item['name'],
                    'kind' => 'group',
                    'name' => $item['name'],
                    'masked' => $masked,
                    'value' => $masked
                        ? null
                        : abs(collect($memberIds)->sum(fn (int $envelopeId): int => $totals->get($envelopeId, 0))),
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
    private function emptyPayload(DashboardWidget $widget, WidgetPeriod $period): array
    {
        return match ($widget->type) {
            DashboardWidget::TYPE_ENVELOPE_SPENDING => [
                'type' => $widget->type,
                'rows' => [],
            ],
            DashboardWidget::TYPE_INCOME_EXPENSES => [
                'type' => $widget->type,
                'masked' => false,
                'series' => collect($period->buckets())->map(fn (string $bucket): array => [
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
     * The profile's transactions on the selected envelopes within the
     * period window, across all of the profile's ledgers (open and closed —
     * history is history). Amounts are decrypted by hydration.
     *
     * @param  list<int>  $envelopeIds
     * @return Collection<int, Transaction>
     */
    private function transactions(FinancialProfile $profile, WidgetPeriod $period, array $envelopeIds): Collection
    {
        $ledgerIds = Ledger::query()->where('profile_id', $profile->id)->pluck('id');

        if ($ledgerIds->isEmpty()) {
            return collect();
        }

        ['from' => $from, 'to' => $to] = $period->window();

        return Transaction::query()
            ->whereIn('ledger_id', $ledgerIds)
            ->whereIn('envelope_id', $envelopeIds)
            ->whereBetween('event_at', [$from, $to])
            ->get();
    }

    /**
     * The signed total per envelope, computed in PHP from the hydrated rows
     * (docs/encryption.md).
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, int>
     */
    private function totalsByEnvelope(Collection $transactions): Collection
    {
        return $transactions
            ->groupBy(fn (Transaction $transaction): int => $transaction->envelope_id)
            ->map(fn (Collection $group): int => (int) $group->sum(
                fn (Transaction $transaction): int => $transaction->amount,
            ));
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
    private function allocationsByEnvelope(FinancialProfile $profile, WidgetPeriod $period, array $envelopeIds): Collection
    {
        ['from' => $from, 'to' => $to] = $period->window();

        // The calendar months the period window touches.
        $months = [];

        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $months[$cursor->year.'-'.$cursor->month] = true;

            $cursor->addMonth();
        }

        $budgets = Budget::query()
            ->where('profile_id', $profile->id)
            ->whereNotNull('published_at')
            ->get()
            ->filter(fn (Budget $budget): bool => isset($months[$budget->effective_year.'-'.$budget->effective_month]));

        if ($budgets->isEmpty()) {
            return collect();
        }

        return BudgetEnvelope::query()
            ->whereIn('budget_id', $budgets->pluck('id'))
            ->whereIn('envelope_id', $envelopeIds)
            ->get()
            ->groupBy(fn (BudgetEnvelope $allocation): int => $allocation->envelope_id)
            ->map(fn (Collection $group): int => (int) $group->sum(
                fn (BudgetEnvelope $allocation): int => $allocation->amount,
            ));
    }
}
