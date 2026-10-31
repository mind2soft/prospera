<?php

use App\Models\FinancialProfileInvite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('invites:prune', function (): void {
    $this->info(FinancialProfileInvite::pruneStale().' stale invite(s) deleted.');
})->purpose('Delete expired invites and stale unverified invite reservations');

Schedule::command('invites:prune')->daily();
