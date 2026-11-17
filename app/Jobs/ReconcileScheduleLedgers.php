<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Schedules\ScheduleReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fan a schedule or budget change out to one reconciliation job per
 * affected open ledger (docs/scheduled-transactions.md). The ledger set is
 * discovered when this job runs — after the triggering commit — so it
 * always sees the change; ledgers created or reopened concurrently dispatch
 * their own reconciliation.
 */
class ReconcileScheduleLedgers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const ENVELOPE = 'envelope';

    public const PROFILE = 'profile';

    /** @param self::ENVELOPE|self::PROFILE $scope */
    private function __construct(public readonly string $scope, public readonly int $id)
    {
        $this->afterCommit();
    }

    public static function forEnvelope(int $envelopeId): self
    {
        return new self(self::ENVELOPE, $envelopeId);
    }

    public static function forProfile(int $profileId): self
    {
        return new self(self::PROFILE, $profileId);
    }

    public function handle(ScheduleReconciler $reconciler): void
    {
        $ledgerIds = $this->scope === self::ENVELOPE
            ? $reconciler->ledgerIdsForEnvelope($this->id)
            : $reconciler->ledgerIdsForProfile($this->id);

        foreach ($ledgerIds as $ledgerId) {
            dispatch(new ReconcileLedgerSchedules($ledgerId));
        }
    }
}
