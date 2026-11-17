<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Jobs\CheckEnvelopeLimitGroup;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\EnvelopeSchedule;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountKeyRing;
use App\Security\Amounts\AmountKeyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materializes schedule occurrences into one open ledger
 * (docs/scheduled-transactions.md). Every run recomputes the complete
 * expected set of generated rows from current state and converges the
 * ledger to it — inserting missing rows, re-amounting changed ones and
 * permanently deleting superfluous ones — so repeated runs, retries and
 * catch-up after downtime are idempotent without any last-run watermark.
 *
 * Lock order (docs/financial-locking.md): ledger root (exclusive) →
 * covering published budgets (shared, ascending id) → every relevant
 * envelope (shared, ascending id) → schedule reads and transaction writes.
 * Key memos are refreshed beneath the locks on every attempt. Amounts are
 * exact integer minor units, compared and written in PHP only.
 */
final class ScheduleReconciler
{
    public function __construct(private readonly ScheduleOccurrenceResolver $resolver) {}

    /**
     * @return array{inserted: int, updated: int, deleted: int}
     */
    public function reconcileLedger(int $ledgerId): array
    {
        return DB::transaction(fn (): array => $this->reconcileLocked($ledgerId), 3);
    }

    /**
     * Open ledgers whose generated rows may depend on the envelope: those of
     * every profile with a published budget assigning it, plus those already
     * holding rows generated from its schedules.
     *
     * @return list<int>
     */
    public function ledgerIdsForEnvelope(int $envelopeId): array
    {
        return $this->openLedgerIds(fn ($query) => $query
            ->whereIn('profile_id', Budget::publishedAssigning($envelopeId)->select('profile_id'))
            ->orWhereIn('id', Transaction::withTrashed()
                ->where('envelope_id', $envelopeId)
                ->whereNotNull('schedule_id')
                ->select('ledger_id')));
    }

    /** @return list<int> */
    public function ledgerIdsForProfile(int $profileId): array
    {
        return $this->openLedgerIds(fn ($query) => $query->where('profile_id', $profileId));
    }

    /**
     * @param  callable(Builder<Ledger>): mixed  $scope
     * @return list<int>
     */
    private function openLedgerIds(callable $scope): array
    {
        return array_values(Ledger::query()
            ->whereNull('closed_at')
            ->where(fn ($query) => $scope($query))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => AmountContext::id($id, 'ledger_id'))
            ->all());
    }

    /**
     * @return array{inserted: int, updated: int, deleted: int}
     */
    private function reconcileLocked(int $ledgerId): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];
        $ring = app(AmountKeyRing::class);

        $ledger = Ledger::query()->whereKey($ledgerId)->lockForUpdate()->first();

        // Closed ledgers are never touched, including their generated rows.
        if ($ledger === null || $ledger->isClosed()) {
            return $counts;
        }

        $ring->forget($ledger->profile_id);
        $ring->rememberLedgerProfiles([$ledger->id], $ledger->profile_id);

        $coverage = $this->coverage($ledger);

        // Existing generated rows count as relevant even when their coverage,
        // schedule or envelope assignment is gone: that is how removed
        // coverage is cleaned up.
        $generated = Transaction::withTrashed()
            ->where('ledger_id', $ledger->id)
            ->whereNotNull('schedule_id')
            ->orderBy('id')
            ->get();

        $envelopeIds = $coverage->flatten()
            ->merge($generated->pluck('envelope_id'))
            ->map(fn (mixed $id): int => AmountContext::id($id, 'envelope_id'))
            ->unique()
            ->sort()
            ->values();

        if ($envelopeIds->isEmpty()) {
            return $counts;
        }

        $envelopes = Envelope::query()
            ->whereIn('id', $envelopeIds->all())
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'type'])
            ->keyBy('id');

        foreach ($envelopeIds as $envelopeId) {
            $ring->forget($envelopeId, AmountKeyScope::Envelope);
        }

        $expected = $this->expectedRows($ledger, $coverage);

        // Groups whose recorded spending grew: (envelope, month) => the
        // lowest changed row id, the start of the limit check's window.
        $grown = [];
        $remember = function (int $envelopeId, CarbonImmutable $date, int $rowId) use (&$grown, $envelopes): void {
            if ($envelopes->get($envelopeId)?->type !== Envelope::DIRECTION_EXPENSE) {
                return;
            }
            $group = $envelopeId.'|'.$date->format('Y-m');
            $grown[$group] = min($grown[$group] ?? $rowId, $rowId);
        };

        $superfluous = [];

        foreach ($generated as $row) {
            $key = $row->schedule_id.'|'.$row->event_at->toDateString();
            $wanted = $expected[$key] ?? null;

            if ($wanted === null || $row->trashed() || (int) $row->envelope_id !== $wanted['envelope_id']) {
                $superfluous[] = $row->id;

                continue;
            }

            unset($expected[$key]);
            $current = $row->amountMinorUnits();

            if ($current !== $wanted['amount']) {
                $row->setAmountMinorUnits($wanted['amount'])->save();
                $counts['updated']++;

                if ($wanted['amount'] < $current) {
                    $remember($wanted['envelope_id'], $row->event_at, $row->id);
                }
            }
        }

        if ($superfluous !== []) {
            $counts['deleted'] = Transaction::withTrashed()->whereKey($superfluous)->forceDelete();
        }

        foreach ($expected as $wanted) {
            $row = (new Transaction)->forceFill([
                'ledger_id' => $ledger->id,
                'user_id' => null,
                'envelope_id' => $wanted['envelope_id'],
                'schedule_id' => $wanted['schedule_id'],
                'event_at' => $wanted['date'],
            ])->setAmountMinorUnits($wanted['amount']);
            $row->save();
            $counts['inserted']++;
            $remember($wanted['envelope_id'], $wanted['date'], $row->id);
        }

        // ID-only and after commit: the check takes its own locks and reads
        // committed totals, including later manual rows of the same month.
        foreach ($grown as $group => $fromId) {
            [$envelopeId, $month] = explode('|', $group);
            dispatch(new CheckEnvelopeLimitGroup($ledger->id, (int) $envelopeId, $month, $fromId));
        }

        return $counts;
    }

    /**
     * The ledger's months covered by a published budget, each with the
     * envelopes assigned on it (the latest published budget of a month wins,
     * like allocation reporting). Covering budgets are share-locked.
     *
     * @return Collection<string, list<int>> envelope ids keyed by "Y-m"
     */
    private function coverage(Ledger $ledger): Collection
    {
        $budgets = Budget::query()
            ->where('profile_id', $ledger->profile_id)
            ->whereNotNull('published_at')
            ->whereRaw('effective_year * 100 + effective_month between ? and ?', [
                $ledger->start_date->year * 100 + $ledger->start_date->month,
                $ledger->end_date->year * 100 + $ledger->end_date->month,
            ])
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'effective_year', 'effective_month', 'published_at']);

        $latest = $budgets
            ->sortBy([['published_at', 'asc'], ['id', 'asc']])
            ->keyBy(fn (Budget $budget): string => sprintf('%04d-%02d', $budget->effective_year, $budget->effective_month));

        if ($latest->isEmpty()) {
            return collect();
        }

        // Only plaintext ids: no assignment amount is read or decrypted.
        $assigned = BudgetEnvelope::query()
            ->whereIn('budget_id', $latest->pluck('id')->all())
            ->get(['budget_id', 'envelope_id'])
            ->groupBy('budget_id');

        return $latest->map(fn (Budget $budget): array => array_values($assigned->get($budget->id, collect())
            ->map(fn (BudgetEnvelope $row): int => AmountContext::id($row->envelope_id, 'envelope_id'))
            ->sort()
            ->all()));
    }

    /**
     * Every occurrence of an active schedule on a covered envelope, within
     * its covered month and the ledger's period — the whole month at once,
     * future dates included.
     *
     * @param  Collection<string, list<int>>  $coverage
     * @return array<string, array{envelope_id: int, schedule_id: int, date: CarbonImmutable, amount: int}>
     */
    private function expectedRows(Ledger $ledger, Collection $coverage): array
    {
        $envelopeIds = $coverage->flatten()->unique()->values()->all();

        if ($envelopeIds === []) {
            return [];
        }

        $schedules = EnvelopeSchedule::query()
            ->whereIn('envelope_id', $envelopeIds)
            ->orderBy('id')
            ->get()
            ->groupBy('envelope_id');

        $expected = [];

        foreach ($coverage as $month => $monthEnvelopeIds) {
            $monthStart = CarbonImmutable::parse($month.'-01');
            $from = $monthStart->max($ledger->start_date)->toDateString();
            $to = $monthStart->endOfMonth()->startOfDay()->min($ledger->end_date)->toDateString();

            if ($from > $to) {
                continue;
            }

            foreach ($monthEnvelopeIds as $envelopeId) {
                /** @var EnvelopeSchedule $schedule */
                foreach ($schedules->get($envelopeId, collect()) as $schedule) {
                    foreach ($this->resolver->resolve($schedule->rule(), $from, $to) as $date) {
                        $expected[$schedule->id.'|'.$date] = [
                            'envelope_id' => $envelopeId,
                            'schedule_id' => $schedule->id,
                            'date' => CarbonImmutable::parse($date)->startOfDay(),
                            'amount' => $schedule->amount,
                        ];
                    }
                }
            }
        }

        return $expected;
    }
}
