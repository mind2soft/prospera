<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\EnvelopeLimitChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The deferred half of the "envelope limit exceeded" notification (issue
 * #52, docs/notifications.md): the check for a freshly recorded
 * transaction runs **off the request path**, in the queue, and is
 * dispatched **after commit** — a rolled-back insert never notifies, and
 * the request never pays for the month's totals.
 *
 * Receives the transaction's ID only — the row is re-loaded here (a
 * transaction deleted between the record and this run simply skips), and
 * every name, amount and recipient is resolved by the checker and the
 * mailable at their own time, never serialized into the payload.
 *
 * Schedule-materialized transactions (#15) must call the same checker —
 * the service is the reusable entry point, this job is the async wrapper
 * the transaction endpoint uses.
 */
class CheckEnvelopeLimit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new job instance.
     *
     * @param  int  $transactionId  the freshly recorded transaction
     */
    public function __construct(
        public readonly int $transactionId,
    ) {
        // Dispatched after commit: the checker reads the month's totals
        // from the database, which must include this very transaction.
        $this->afterCommit();
    }

    public function handle(): void
    {
        $transaction = Transaction::query()->find($this->transactionId);

        if ($transaction === null) {
            return; // deleted before the queue ran — nothing to check
        }

        app(EnvelopeLimitChecker::class)->check($transaction);
    }
}
