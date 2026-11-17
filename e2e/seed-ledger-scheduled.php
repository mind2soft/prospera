<?php

/**
 * Playwright seed for scheduled (generated) ledger rows: the seed-ledger.php
 * fixture plus a monthly Mortgage schedule on the 25th, materialized into
 * the open 2026 ledger for October (the published budget's month) through
 * the real reconciler — no hand-written provenance.
 *
 * npm run e2e:seed -- seed-ledger-scheduled.php
 */

use App\Services\Schedules\ScheduleReconciler;

require __DIR__.'/seed-ledger.php';

$rent->schedules()->create([
    'amount' => -4550, // minor units: 45.50
    'period' => 'monthly',
    'start_date' => '2026-01-01',
    'day_of_month' => 25,
]);

$counts = app(ScheduleReconciler::class)->reconcileLedger($ledger->id);

echo 'seeded scheduled rows='.$counts['inserted'].PHP_EOL;
