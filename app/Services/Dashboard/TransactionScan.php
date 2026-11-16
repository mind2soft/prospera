<?php

namespace App\Services\Dashboard;

use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Security\Amounts\AmountKeyRing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Streams the transactions behind a dashboard widget and accumulates its
 * aggregates in bounded chunks (docs/dashboard.md). Amounts are encrypted at
 * rest (docs/encryption.md): every row is decrypted in PHP — never summed,
 * filtered or sorted in SQL — but the scan never retains the full history:
 * rows are fetched in id-ordered chunks of minimal columns, decoded once,
 * added to the accumulators and released with their chunk. Peak memory is
 * bounded by the chunk size, not the history length.
 *
 * One scan serves every aggregate shape a widget needs: the signed sum per
 * envelope (envelope spending, radial) and the revenue/expense sums per
 * period bucket (income & expenses) are accumulated in the same pass.
 *
 * Masking rule: rows on envelopes the requester cannot amount-see are never
 * part of an exposed aggregate — every aggregate that includes one is masked
 * as a whole (docs/dashboard.md) — so they are not fetched or decrypted at
 * all. The same holds for the whole income/expenses series when any selected
 * envelope is masked: no amount is exposed, so nothing is scanned.
 */
final class TransactionScan
{
    /**
     * Rows per chunk. A chunk of hydrated models stays well within a
     * shared-hosting request memory limit for any history length
     * (measured budget: see docs/dashboard.md).
     */
    public const CHUNK_SIZE = 500;

    /** @var array<int, int> envelope id → signed sum in minor units */
    private array $envelopeTotals = [];

    /** @var array<string, array{revenue: int, expenses: int}> bucket key → minor-unit sums */
    private array $bucketTotals = [];

    private function __construct(private readonly WidgetPeriod $period) {}

    /**
     * The profile's transactions on the selected envelopes within the
     * period window, across all of the profile's ledgers (open and closed —
     * history is history), accumulated chunk by chunk.
     *
     * @param  list<int>  $envelopeIds
     * @param  list<int>|null  $amountVisibleEnvelopeIds  the envelope ids whose
     *                                                    amounts may be exposed in the response; null = all selected envelopes
     *                                                    are amount-visible. Rows outside this set are not fetched or decrypted.
     */
    public static function run(
        FinancialProfile $profile,
        WidgetPeriod $period,
        array $envelopeIds,
        CarbonImmutable $today,
        ?array $amountVisibleEnvelopeIds = null,
    ): self {
        $scan = new self($period);

        // Rows on envelopes whose aggregates are never exposed contribute
        // nothing to the response — the scan skips them entirely.
        $decryptableIds = $amountVisibleEnvelopeIds === null
            ? $envelopeIds
            : array_values(array_intersect($envelopeIds, $amountVisibleEnvelopeIds));

        if ($decryptableIds === []) {
            return $scan;
        }

        $ledgerIds = Ledger::query()->where('profile_id', $profile->id)->pluck('id');

        if ($ledgerIds->isEmpty()) {
            return $scan;
        }

        // Decryption binds each row's profile: known here, no lookup per
        // ledger or per chunk.
        app(AmountKeyRing::class)->rememberLedgerProfiles($ledgerIds, $profile->id);

        ['from' => $from, 'to' => $to] = $period->window($today);

        // Minimal columns only: the encryption context (ledger_id,
        // envelope_id, event_at) plus the amount. lazyById pages on the
        // stable id, so chunk boundaries never skip or repeat rows. The
        // soft-delete scope applies as with any transaction query.
        Transaction::query()
            ->select(['id', 'ledger_id', 'envelope_id', 'event_at', 'amount'])
            ->whereIn('ledger_id', $ledgerIds)
            ->whereIn('envelope_id', $decryptableIds)
            ->whereBetween('event_at', [$from, $to])
            ->lazyById(self::CHUNK_SIZE)
            ->each(fn (Transaction $transaction) => $scan->accumulate($transaction));

        return $scan;
    }

    /**
     * The signed total per envelope, in exact integer minor units
     * (docs/encryption.md).
     *
     * @return Collection<int, int> Integer minor units per envelope.
     */
    public function totalsByEnvelope(): Collection
    {
        return collect($this->envelopeTotals);
    }

    /**
     * The revenue/expense sums per period bucket, in exact integer minor
     * units. Zero amounts count toward neither.
     *
     * @return Collection<string, array{revenue: int, expenses: int}>
     */
    public function bucketTotals(): Collection
    {
        return collect($this->bucketTotals);
    }

    private function accumulate(Transaction $transaction): void
    {
        // One decryption per row (memoized on the model), added to both
        // accumulator shapes; the model is released with its chunk.
        $amount = $transaction->amountMinorUnits();
        $envelopeId = $transaction->envelope_id;

        $this->envelopeTotals[$envelopeId] = ($this->envelopeTotals[$envelopeId] ?? 0) + $amount;

        $bucket = $this->period->bucketKey($transaction->event_at);
        $totals = $this->bucketTotals[$bucket] ?? ['revenue' => 0, 'expenses' => 0];

        if ($amount > 0) {
            $totals['revenue'] += $amount;
        } elseif ($amount < 0) {
            $totals['expenses'] += $amount;
        }

        $this->bucketTotals[$bucket] = $totals;
    }
}
