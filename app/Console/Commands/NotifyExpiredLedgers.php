<?php

namespace App\Console\Commands;

use App\Mail\LedgerExpired;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The "ledger expired" daily run (issue #50, docs/notifications.md): every
 * open ledger whose end date has passed and that has not been notified yet
 * queues one email per eligible recipient — the ledger owner plus every
 * member of the ledger's financial profile, deduplicated.
 *
 * A ledger is notified **at most once ever**: `ledgers.expired_notified_at`
 * is claimed atomically (`UPDATE … WHERE expired_notified_at IS NULL`)
 * before dispatching, so overlapping runs and command re-runs never
 * double-send, and a scheduler catch-up after missed days still finds every
 * expired-and-unnotified ledger ("first detects" = first run after the end
 * date). Closed ledgers are never notified; a ledger closed before expiry
 * and re-opened afterwards counts as first detected on the next run, and
 * re-opening never resets the marker.
 */
class NotifyExpiredLedgers extends Command
{
    protected $signature = 'ledgers:notify-expired';

    protected $description = 'Queue one "ledger expired" email per recipient for every open ledger past its end date, sent once per ledger when the daily run first detects it (issue #50)';

    public function handle(): int
    {
        $today = now()->toDateString();
        $notified = 0;
        $failed = 0;

        // Only non-encrypted columns are selected — no amount decryption
        // runs here (docs/encryption.md).
        $candidates = Ledger::query()
            ->select(['ledgers.id', 'ledgers.profile_id', 'ledgers.name', 'ledgers.start_date', 'ledgers.end_date'])
            ->whereNull('closed_at')
            ->where('end_date', '<', $today)
            ->whereNull('expired_notified_at')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $ledger) {
            try {
                if (! $this->claimAndDispatch($ledger)) {
                    continue; // claimed by a concurrent run, or closed since
                }

                $notified++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        $this->info($notified.' expired ledger(s) notified.');

        if ($failed > 0) {
            $this->warn($failed.' expired ledger(s) could not be notified (reported; retried on the next run).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Claim the ledger (at most one email ever) and queue its mails in the
     * same transaction: the queue driver is the database, so the claim and
     * the queued job rows commit together — a rolled-back claim leaves no
     * mail behind, and committed mails always leave a claim.
     *
     * @return bool whether this run claimed the ledger
     */
    private function claimAndDispatch(Ledger $ledger): bool
    {
        return DB::transaction(function () use ($ledger): bool {
            $claimed = DB::table('ledgers')
                ->where('id', $ledger->id)
                ->whereNull('closed_at')
                ->whereNull('expired_notified_at')
                ->update(['expired_notified_at' => now()]) === 1;

            if ($claimed) {
                $this->dispatchMails($ledger);
            }

            return $claimed;
        });
    }

    /**
     * Queue one email per deduplicated recipient: the ledger owner plus
     * every member of the ledger's financial profile (all of them can view
     * ledgers — docs/ledger.md). The notification gate and the verified
     * address are checked here too, as a dispatch-time pre-filter; the
     * mailable re-checks everything at send time, when it actually
     * delivers.
     */
    private function dispatchMails(Ledger $ledger): void
    {
        $profileName = (string) FinancialProfile::query()
            ->whereKey($ledger->profile_id)
            ->value('name');

        $recipients = DB::table('ledger_permissions')
            ->where('ledger_id', $ledger->id)
            ->where('permission', LedgerPermission::PERMISSION_OWNER)
            ->pluck('user_id')
            ->merge(DB::table('financial_profile_user')
                ->where('profile_id', $ledger->profile_id)
                ->pluck('user_id'))
            ->unique()
            ->values();

        $users = User::query()
            ->whereIntegerInRaw('id', $recipients->all())
            ->get();

        foreach ($users as $user) {
            if ($user->email_verified_at === null
                || ! $user->wantsNotification(NotificationSettings::LEDGER_EXPIRED)) {
                continue;
            }

            Mail::to($user->email)->queue(new LedgerExpired(
                ledgerId: (int) $ledger->id,
                ledgerName: (string) $ledger->name,
                profileId: (int) $ledger->profile_id,
                profileName: $profileName,
                startDate: CarbonImmutable::instance($ledger->start_date),
                endDate: CarbonImmutable::instance($ledger->end_date),
                recipientUserId: $user->id,
                recipientLocale: $user->locale ?? 'en',
            ));
        }
    }
}
