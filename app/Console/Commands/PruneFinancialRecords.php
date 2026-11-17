<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReconcileScheduleLedgers;
use App\Models\Budget;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * Financial data retention (issue #61, docs/retention.md): the automated
 * prune behind the privacy policy's published promise. A ledger — with all
 * of its transactions, pending deletions included — is deleted 7 years
 * after its end date, a published budget 7 years past its effective month,
 * and a budget draft 7 years after its creation.
 *
 * The run is date-based and idempotent: candidates are selected from
 * plaintext date columns only (never encrypted amounts, no SQL arithmetic
 * on them), there is no last-run watermark, so re-runs, overlaps and
 * catch-ups after downtime converge without duplicates. Every resource is
 * deleted in its own transaction after its eligibility is re-checked under
 * its serialization lock: one failure is reported and skipped, the rest of
 * the run goes on (the next run retries it). Nothing is due before 2033
 * under the default 7-year window; the boundaries are documented in
 * docs/retention.md.
 */
class PruneFinancialRecords extends Command
{
    protected $signature = 'retention:prune {--dry-run : Report what would be deleted without writing}';

    protected $description = 'Delete ledgers and budgets past the financial retention window (7 years by default), exactly as the privacy policy promises (issue #61)';

    public function handle(): int
    {
        $years = max(1, (int) config('prospera.financial_retention_years', 7));
        $today = CarbonImmutable::now();

        // Date-granularity cutoff: a resource whose period ended exactly on
        // the cutoff date is kept ("kept until 7 years after" — the first
        // eligible day is the day after the anniversary, so a ledger ending
        // December 31, 2026 is deleted at the start of 2034).
        $cutoff = $today->subYears($years)->startOfDay();

        if ((bool) $this->option('dry-run')) {
            return $this->reportDryRun($cutoff, $years);
        }

        $deletedLedgers = 0;
        $deletedPublished = 0;
        $deletedDrafts = 0;
        $failed = 0;

        // Ledgers first: their transactions (and their generated schedule
        // rows) go with them. Open and closed ledgers share the rule — the
        // policy keys on the covered period, not the lifecycle state.
        $this->ledgerCandidates($cutoff)
            ->each(function (Ledger $candidate) use ($cutoff, &$deletedLedgers, &$failed): void {
                try {
                    if ($candidate->deleteWithTransactions(
                        // Re-check eligibility under the ledger's row lock:
                        // the period never changes, but the ledger may have
                        // been deleted (or, later, moved) since selection.
                        fn (Ledger $locked): bool => $locked->end_date->lessThan($cutoff),
                    )) {
                        $deletedLedgers++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            });

        // Budgets (published oldest effective month first, then drafts):
        // each in its own transaction, with reconciliation queued after
        // commit for published coverage that disappears.
        $this->publishedBudgetCandidates($cutoff)
            ->each(function (Budget $candidate) use ($cutoff, &$deletedPublished, &$failed): void {
                $this->pruneBudget($candidate, $cutoff, $deletedPublished, $failed);
            });

        $this->draftBudgetCandidates($cutoff)
            ->each(function (Budget $candidate) use ($cutoff, &$deletedDrafts, &$failed): void {
                $this->pruneBudget($candidate, $cutoff, $deletedDrafts, $failed);
            });

        $this->info($deletedLedgers.' ledger(s) deleted.');
        $this->info($deletedPublished.' published budget(s) deleted.');
        $this->info($deletedDrafts.' draft budget(s) deleted.');

        if ($failed > 0) {
            $this->warn($failed.' resource(s) could not be deleted (reported; retried on the next run).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Delete one budget in its own transaction — the same path as the
     * budget controller: row lock, eligibility re-check, cascade (shares,
     * envelope assignments, envelope-limit claims), then schedule
     * reconciliation for a published budget's profile, queued after commit.
     *
     * @param  int  $deleted  by-reference counter (published or draft)
     * @param  int  $failed  by-reference failure counter
     */
    private function pruneBudget(Budget $candidate, CarbonImmutable $cutoff, int &$deleted, int &$failed): void
    {
        try {
            $wasDeleted = DB::transaction(function () use ($candidate, $cutoff): bool {
                $locked = Budget::query()->whereKey($candidate->id)->lockForUpdate()->first();

                if ($locked === null || ! $this->eligibleBudget($locked, $cutoff)) {
                    return false;
                }

                $locked->delete();

                // Removed coverage: its generated rows leave the open
                // ledgers (dispatched after commit).
                if (! $locked->isDraft()) {
                    dispatch(ReconcileScheduleLedgers::forProfile((int) $locked->profile_id));
                }

                return true;
            });

            if ($wasDeleted) {
                $deleted++;
            }
        } catch (Throwable $exception) {
            report($exception);
            $failed++;
        }
    }

    /**
     * Dry-run report: candidate counts and rows, nothing written.
     */
    private function reportDryRun(CarbonImmutable $cutoff, int $years): int
    {
        $ledgers = $this->ledgerCandidates($cutoff);
        $published = $this->publishedBudgetCandidates($cutoff);
        $drafts = $this->draftBudgetCandidates($cutoff);

        $rows = [];
        foreach ($ledgers as $ledger) {
            $rows[] = ['ledger', (int) $ledger->id, (int) $ledger->profile_id, (string) $ledger->name,
                'end_date '.$ledger->end_date->toDateString()];
        }
        foreach ($published as $budget) {
            $rows[] = ['budget (published)', (int) $budget->id, (int) $budget->profile_id, (string) $budget->currency,
                'effective '.$budget->effective_year.'-'.str_pad((string) $budget->effective_month, 2, '0', STR_PAD_LEFT)];
        }
        foreach ($drafts as $budget) {
            $rows[] = ['budget (draft)', (int) $budget->id, (int) $budget->profile_id, (string) $budget->currency,
                'created '.$budget->created_at?->toDateString()];
        }

        $this->table(['type', 'id', 'profile_id', 'label', 'expires'], $rows);
        $this->info($ledgers->count().' ledger(s) and '.($published->count() + $drafts->count())
            .' budget(s) would be deleted ('.$years.' years past '
            .$cutoff->toDateString().'). Dry run: nothing was written.');

        return self::SUCCESS;
    }

    /**
     * Ledgers kept past 7 years after their end date, oldest first. Only
     * plaintext columns are selected — no amount decryption (docs/encryption.md).
     * Chunked paging keeps the run bounded on a large database.
     *
     * @return LazyCollection<int, Ledger>
     */
    private function ledgerCandidates(CarbonImmutable $cutoff): LazyCollection
    {
        return Ledger::query()
            ->select(['id', 'profile_id', 'name', 'end_date'])
            ->where('end_date', '<', $cutoff->toDateString())
            ->orderBy('id')
            ->lazyById();
    }

    /**
     * Published budgets kept past 7 years after the end of their effective
     * month. The SQL filter is a superset of the exact boundary (months
     * strictly before the cutoff's month, plus the cutoff's own month);
     * the exact end-of-month re-check runs under the budget's lock.
     * Chunked paging keeps the run bounded on a large database.
     *
     * @return LazyCollection<int, Budget>
     */
    private function publishedBudgetCandidates(CarbonImmutable $cutoff): LazyCollection
    {
        $threshold = $cutoff->subDay();

        return Budget::query()
            ->select(['id', 'profile_id', 'currency', 'effective_year', 'effective_month'])
            ->whereNotNull('published_at')
            ->where(function (Builder $query) use ($threshold): void {
                $query->where('effective_year', '<', $threshold->year)
                    ->orWhere(fn (Builder $inner) => $inner
                        ->where('effective_year', $threshold->year)
                        ->where('effective_month', '<=', $threshold->month));
            })
            ->lazyById();
    }

    /**
     * Draft budgets kept past 7 years after their creation.
     * Chunked paging keeps the run bounded on a large database.
     *
     * @return LazyCollection<int, Budget>
     */
    private function draftBudgetCandidates(CarbonImmutable $cutoff): LazyCollection
    {
        return Budget::query()
            ->select(['id', 'profile_id', 'currency', 'created_at'])
            ->whereNull('published_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->lazyById();
    }

    /**
     * Exact eligibility of a budget re-read under its lock: a published
     * budget expires 7 years after the end of its effective month; a draft
     * 7 years after its creation (both strict — the anniversary itself is
     * kept, the day after is not).
     */
    private function eligibleBudget(Budget $budget, CarbonImmutable $cutoff): bool
    {
        if ($budget->isDraft()) {
            return $budget->created_at !== null && $budget->created_at->lessThan($cutoff);
        }

        // Strict creation: an impossible effective month (corrupt row)
        // throws instead of silently shifting, which the caller reports and
        // keeps — a resource is never deleted on an unreadable boundary.
        $monthEnd = CarbonImmutable::createStrict($budget->effective_year, $budget->effective_month, 1)
            ->endOfMonth()
            ->startOfDay();

        return $monthEnd->lessThan($cutoff);
    }
}
