<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Exceptions\FinancialLockSetChanged;
use App\Models\Envelope;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Security\Amounts\AmountContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Soft-delete a schedule and permanently remove its generated rows from
 * open ledgers, atomically (docs/scheduled-transactions.md). Closed-ledger
 * rows keep their provenance; reopening the ledger later removes them.
 *
 * Ledger roots must precede the envelope lock (docs/financial-locking.md),
 * but the ledger set is only known from the rows: discover every ledger
 * holding rows of the schedule (closed ones included), lock them ascending,
 * then the envelope, then reauthorize and requery. If a reconciliation
 * committed rows in another ledger in between, the whole attempt rolls
 * back and rediscovers; the set is never extended beneath the envelope.
 */
final class ScheduleDeletion
{
    private const ATTEMPTS = 3;

    /**
     * @param  Closure(Envelope): mixed  $authorize  rechecks ownership on the fresh locked envelope
     */
    public function delete(int $envelopeId, int $scheduleId, Closure $authorize): void
    {
        retry(self::ATTEMPTS, fn () => DB::transaction(function () use ($envelopeId, $scheduleId, $authorize): void {
            $ledgerIds = $this->ledgerIdsOf($scheduleId);
            $locked = Ledger::query()
                ->whereIn('id', $ledgerIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'closed_at']);

            $envelope = Envelope::query()->whereKey($envelopeId)->lockForUpdate()->firstOrFail();
            $authorize($envelope);
            $schedule = $envelope->schedules()->whereKey($scheduleId)->lockForUpdate()->firstOrFail();

            if (array_diff($this->ledgerIdsOf($scheduleId), $locked->modelKeys()) !== []) {
                throw new FinancialLockSetChanged('The schedule generated rows in a ledger that is not locked.');
            }

            $open = $locked->filter(fn (Ledger $ledger): bool => $ledger->isOpen())->modelKeys();

            if ($open !== []) {
                Transaction::withTrashed()
                    ->where('schedule_id', $scheduleId)
                    ->whereIn('ledger_id', $open)
                    ->forceDelete();
            }

            $schedule->delete();
        }, 3), 0, fn (Throwable $exception): bool => $exception instanceof FinancialLockSetChanged);
    }

    /** @return list<int> */
    private function ledgerIdsOf(int $scheduleId): array
    {
        return array_values(Transaction::withTrashed()
            ->where('schedule_id', $scheduleId)
            ->distinct()
            ->orderBy('ledger_id')
            ->pluck('ledger_id')
            ->map(fn (mixed $id): int => AmountContext::id($id, 'ledger_id'))
            ->all());
    }
}
