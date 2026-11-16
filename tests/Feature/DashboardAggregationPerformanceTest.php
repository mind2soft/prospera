<?php

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountKeyRing;
use App\Services\Dashboard\TransactionScan;
use App\Services\Dashboard\WidgetPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * The streaming aggregation of dashboard widgets (issue #86): the scan must
 * reproduce the full-hydration sums exactly (result parity) while keeping
 * peak memory bounded by its chunk size — not the history length — and never
 * scanning per aggregate shape.
 */
class DashboardAggregationPerformanceTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;

    /**
     * The measured peak-memory budget for one scan (docs/dashboard.md):
     * full hydration of the same 10k-row history would retain every model
     * and payload (~10-15MB on top of the scan's own footprint); the scan
     * must stay far below that.
     */
    private const PEAK_MEMORY_BUDGET_BYTES = 8 * 1048576;

    private User $owner;

    private FinancialProfile $profile;

    private Ledger $ledger;

    private Ledger $otherLedger;

    private Envelope $expenses;

    private Envelope $revenue;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = CarbonImmutable::parse('2026-11-10 12:00:00');

        $this->owner = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => 'Main ledger',
            'start_date' => '2025-01-01',
            'end_date' => '2027-12-31',
            'starting_balance' => 0,
        ]);
        $this->otherLedger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => 'Side ledger',
            'start_date' => '2025-01-01',
            'end_date' => '2027-12-31',
            'starting_balance' => 0,
        ]);

        $this->expenses = $this->owner->envelopes()->create([
            'name' => 'Expenses',
            'type' => 'expense',
            'color' => '#ef4444',
        ]);
        $this->revenue = $this->owner->envelopes()->create([
            'name' => 'Revenue',
            'type' => 'revenue',
            'color' => '#3b82f6',
        ]);
    }

    /**
     * The scan's accumulators must equal sums computed independently from the
     * seeded rows — across chunk boundaries (1100 rows = 3 chunks of 500),
     * both ledgers, cents, revenue/expenses, zero amounts, the month
     * boundary, rows outside the window and a soft-deleted row.
     */
    public function test_scan_accumulators_match_independently_computed_sums_across_chunk_boundaries(): void
    {
        $envelopeIds = [$this->expenses->id, $this->revenue->id];

        $spec = [];
        $expectedEnvelopeTotals = [];
        $expectedBucketTotals = [];

        for ($i = 1; $i <= 1100; $i++) {
            $expense = $i % 2 === 1;
            $minor = ($i % 9973) + 1; // exact cents, deterministic
            $amount = $expense ? -$minor : $minor;
            $envelopeId = $expense ? $this->expenses->id : $this->revenue->id;

            // 45 days back from today: crosses the 2026-11-01 month boundary.
            $date = $this->today->subDays($i % 45)->startOfDay()->toDateTimeString();

            $spec[] = [$envelopeId, $amount, $date, $this->ledger];

            $expectedEnvelopeTotals[$envelopeId] = ($expectedEnvelopeTotals[$envelopeId] ?? 0) + $amount;

            $bucket = substr($date, 0, 7); // months period bucket: "Y-m"
            $bucketTotals = $expectedBucketTotals[$bucket] ?? ['revenue' => 0, 'expenses' => 0];

            if ($amount > 0) {
                $bucketTotals['revenue'] += $amount;
            } else {
                $bucketTotals['expenses'] += $amount;
            }

            $expectedBucketTotals[$bucket] = $bucketTotals;
        }

        // Zero amounts count toward neither side.
        $spec[] = [$this->expenses->id, 0, '2026-11-05 00:00:00', $this->ledger];

        // Rows in the second ledger count toward the same profile totals.
        $spec[] = [$this->expenses->id, -1234, '2026-11-06 00:00:00', $this->otherLedger];
        $spec[] = [$this->revenue->id, 4321, '2026-10-31 00:00:00', $this->otherLedger];

        $expectedEnvelopeTotals[$this->expenses->id] -= 1234;
        $expectedEnvelopeTotals[$this->revenue->id] += 4321;
        $expectedBucketTotals['2026-11']['expenses'] -= 1234;
        $expectedBucketTotals['2026-10']['revenue'] += 4321;

        ksort($expectedBucketTotals);

        // Rows outside the window are not part of the scan (whereBetween).
        $spec[] = [$this->expenses->id, -100000, '2026-05-01 00:00:00', $this->ledger];

        $this->seedTransactions($spec);

        // A soft-deleted row is excluded, as with any transaction query.
        $deleted = $this->ledger->transactions()->create([
            'user_id' => $this->owner->id,
            'envelope_id' => $this->expenses->id,
            'amount' => -999,
            'event_at' => CarbonImmutable::parse('2026-11-07 00:00:00'),
        ]);
        $deleted->delete();

        $scan = TransactionScan::run(
            $this->profile,
            WidgetPeriod::months(3),
            $envelopeIds,
            $this->today,
        );

        $this->assertSame($expectedEnvelopeTotals, $scan->totalsByEnvelope()->all());

        $bucketTotals = $scan->bucketTotals()->all();
        ksort($bucketTotals);
        $this->assertSame($expectedBucketTotals, $bucketTotals);
    }

    public function test_peak_memory_is_bounded_by_chunk_size_not_history_length(): void
    {
        // 10k rows = 20 chunks of 500: the scan must never retain the full
        // history, and never scan twice for the two aggregate shapes.
        $spec = [];

        for ($i = 1; $i <= 10_000; $i++) {
            $expense = $i % 2 === 1;
            $minor = ($i % 9973) + 1;
            $amount = $expense ? -$minor : $minor;
            $envelopeId = $expense ? $this->expenses->id : $this->revenue->id;
            $date = $this->today->subDays($i % 365)->startOfDay()->toDateTimeString();

            $spec[] = [$envelopeId, $amount, $date, $this->ledger];
        }

        $this->seedTransactions($spec);

        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();

        $queries = $this->queriesDuring(fn (): TransactionScan => TransactionScan::run(
            $this->profile,
            WidgetPeriod::months(12),
            [$this->expenses->id, $this->revenue->id],
            $this->today,
        ));

        $peak = memory_get_peak_usage(true) - $baseline;

        // Measured budget (docs/dashboard.md): the scan must stay far below
        // the ~10-15MB a full hydration of the same history would retain.
        $this->assertLessThan(self::PEAK_MEMORY_BUDGET_BYTES, $peak);

        // One scan, chunked: 10k rows in chunks of 500 means 20 transaction
        // queries — not one full-history query, not one query per shape.
        $transactionQueries = array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'from "transactions"'),
        );

        $this->assertGreaterThanOrEqual(3, count($transactionQueries));
    }

    public function test_no_rows_are_scanned_when_no_amount_is_exposed(): void
    {
        $this->seedTransactions([
            [$this->expenses->id, -1000, '2026-11-05 00:00:00', $this->ledger],
            [$this->revenue->id, 2000, '2026-11-06 00:00:00', $this->ledger],
        ]);

        // Every aggregate that includes a masked envelope is masked as a
        // whole (docs/dashboard.md), so no row is fetched or decrypted.
        $scan = null;

        $queries = $this->queriesDuring(function () use (&$scan): void {
            $scan = TransactionScan::run(
                $this->profile,
                WidgetPeriod::months(1),
                [$this->expenses->id, $this->revenue->id],
                $this->today,
                [],
            );
        });

        $this->assertEmpty(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'from "transactions"'),
        ));

        $this->assertSame([], $scan->totalsByEnvelope()->all());
        $this->assertSame([], $scan->bucketTotals()->all());
    }

    /**
     * Seeds the given rows in bulk with pre-encrypted ciphertexts — allowed
     * by GuardedBuilder (ciphertext only), and orders of magnitude faster
     * than model saves for these volumes. Rows are
     * [envelopeId, amountMinor, eventAt, ledger].
     *
     * @param  list<array{0: int, 1: int, 2: string, 3: Ledger}>  $rows
     */
    private function seedTransactions(array $rows): void
    {
        $keyRing = app(AmountKeyRing::class);
        $now = now();

        $inserts = [];

        foreach ($rows as [$envelopeId, $amountMinor, $eventAt, $ledger]) {
            $context = Transaction::amountContextFor('amount', [
                'ledger_id' => $ledger->id,
                'envelope_id' => $envelopeId,
                'event_at' => $eventAt,
            ], $this->profile->id);

            $inserts[] = [
                'ledger_id' => $ledger->id,
                'user_id' => $this->owner->id,
                'envelope_id' => $envelopeId,
                'amount' => $keyRing->encrypt($amountMinor, $context),
                'event_at' => $eventAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            Transaction::query()->insert($chunk);
        }
    }
}
