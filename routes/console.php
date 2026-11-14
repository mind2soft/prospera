<?php

use App\Models\FinancialProfileInvite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('invites:prune', function (): void {
    $this->info(FinancialProfileInvite::pruneStale().' stale invite(s) deleted.');
})->purpose('Delete expired invites and stale unverified invite reservations');

Schedule::command('invites:prune')->daily();

// Contact-form submission log (issue #17): retention is configurable via
// CONTACT_LOG_RETENTION_DAYS (default 90 days).
Schedule::command('contact:prune')->daily();

// Accounts never verified within UNVERIFIED_ACCOUNT_RETENTION_DAYS (default
// 30) that hold no data are deleted (mistyped emails, abandoned sign-ups).
Schedule::command('users:prune-unverified')->daily()->withoutOverlapping()->onOneServer();

// Undoable transaction deletion (issue #48, docs/undoable-deletion.md):
// deleted transactions stay restorable for Transaction::UNDO_WINDOW_SECONDS
// (3 minutes), then this sweep removes them for good.
Schedule::command('transactions:purge-deleted')->everyMinute()->withoutOverlapping()->onOneServer();

// Encryption at rest (docs/encryption.md): monthly data-key rotation; retired
// key versions are deleted after their grace period. The daily verify catches
// a broken APP_KEY in the logs even when no request touches amounts — the
// boot-time check (issue #36) only covers HTTP requests.
Schedule::command('amounts:rotate-keys --all')->monthly()->withoutOverlapping()->onOneServer();
Schedule::command('amounts:prune-keys')->daily()->withoutOverlapping()->onOneServer();
Schedule::command('amounts:verify')->daily()->withoutOverlapping()->onOneServer();

// Queued emails (docs/notifications.md). The production host is shared
// hosting with no long-lived worker: the scheduler drains the queue every
// minute instead. Each run stops once the queue is empty or after 50s (the
// next run takes over); in the background, so it does not delay the other
// tasks. The overlap lock expires after 10 minutes should a run be killed.
// Mailables set their own retries and backoff (RetriesDelivery).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

// Failed jobs (encrypted payloads) are kept 30 days for inspection and retry.
Schedule::command('queue:prune-failed --hours=720')->daily();
