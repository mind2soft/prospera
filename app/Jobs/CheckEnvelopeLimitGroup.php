<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\EnvelopeLimitChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The "envelope limit exceeded" check for one ledger × envelope × month
 * group whose spending grew through schedule reconciliation
 * (docs/scheduled-transactions.md). IDs only, dispatched after commit; the
 * checker re-reads every amount beneath its own locks.
 */
class CheckEnvelopeLimitGroup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  string  $month  "Y-m"
     * @param  int  $fromTransactionId  the lowest generated row whose amount was inserted or increased
     */
    public function __construct(
        public readonly int $ledgerId,
        public readonly int $envelopeId,
        public readonly string $month,
        public readonly int $fromTransactionId,
    ) {
        $this->afterCommit();
    }

    public function handle(EnvelopeLimitChecker $checker): void
    {
        $checker->checkGroup($this->ledgerId, $this->envelopeId, $this->month, $this->fromTransactionId);
    }
}
