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

// Encryption at rest (docs/encryption.md): monthly data-key rotation; retired
// key versions are deleted after their grace period.
Schedule::command('amounts:rotate-keys --all')->monthly()->withoutOverlapping()->onOneServer();
Schedule::command('amounts:prune-keys')->daily()->withoutOverlapping()->onOneServer();
