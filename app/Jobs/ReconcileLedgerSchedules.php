<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Schedules\ScheduleReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Converge one ledger's generated transactions with its schedules and
 * published-budget coverage (docs/scheduled-transactions.md). ID-only and
 * dispatched after commit. Deliberately not unique: every job recomputes
 * from current state under the ledger lock, so a change committed while
 * another run is in progress is picked up by its own job, never dropped.
 */
class ReconcileLedgerSchedules implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $ledgerId)
    {
        $this->afterCommit();
    }

    public function handle(ScheduleReconciler $reconciler): void
    {
        $reconciler->reconcileLedger($this->ledgerId);
    }
}
