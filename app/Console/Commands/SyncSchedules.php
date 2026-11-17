<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ledger;
use App\Security\Amounts\AmountContext;
use App\Services\Schedules\ScheduleReconciler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily catch-up (docs/scheduled-transactions.md): reconciles every open
 * ledger in full, whatever happened since the last run (lost jobs,
 * downtime, a crash mid-way). Each ledger converges in its own transaction;
 * a failure is reported and does not stop the others.
 */
class SyncSchedules extends Command
{
    protected $signature = 'schedules:sync';

    protected $description = 'Reconcile scheduled transactions in every open ledger (idempotent catch-up)';

    public function handle(ScheduleReconciler $reconciler): int
    {
        $totals = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];
        $failed = 0;

        Ledger::query()->whereNull('closed_at')->select('id')->orderBy('id')
            ->chunkById(500, function ($ledgers) use ($reconciler, &$totals, &$failed): void {
                foreach ($ledgers as $ledger) {
                    $id = AmountContext::id($ledger->id, 'ledger_id');

                    try {
                        foreach ($reconciler->reconcileLedger($id) as $key => $count) {
                            $totals[$key] += $count;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->error("Ledger {$id}: scheduled transactions could not be reconciled (reported; retried on the next run).");
                        $failed++;
                    }
                }
            });

        $this->info("Scheduled transactions: {$totals['inserted']} inserted, {$totals['updated']} updated, {$totals['deleted']} deleted.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
