<?php

/**
 * Playwright seed for e2e/onboarding.spec.ts: the playwright user midway
 * through the 30-day challenge — 1 revenue and 3 expense envelopes, an open
 * ledger, and transactions recorded 12 days ago in two of the envelopes.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-onboarding-challenge.php
 */

use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\Transaction;
use App\Models\User;

require __DIR__.'/seed-reset.php';

$me = User::create([
    'name' => 'Playwright Tester',
    'email' => 'playwright@example.com',
    'password' => bcrypt('Password!234'),
]);
$me->forceFill(['email_verified_at' => now()])->save();
$profile = FinancialProfile::createDefaultFor($me);

$mk = fn (string $name, string $type, string $color) => $me->envelopes()->create(['name' => $name, 'type' => $type, 'color' => $color]);
$paycheck = $mk('Paycheck', 'revenue', '#10b981');
$rent = $mk('Rent or mortgage', 'expense', '#f43f5e');
$mk('Groceries', 'expense', '#f59e0b');
$mk('Gas', 'expense', '#8b5cf6');

$year = (int) now()->format('Y');
$ledger = Ledger::create([
    'profile_id' => $profile->id,
    'name' => (string) $year,
    'start_date' => $year.'-01-01',
    'end_date' => $year.'-12-31',
    'starting_balance' => 1200,
]);
$ledger->permissions()->create(['user_id' => $me->id, 'permission' => LedgerPermission::PERMISSION_OWNER]);

$recordedAt = now()->subDays(12)->subHour();
foreach ([[$paycheck, 2400], [$rent, -1300]] as [$envelope, $amount]) {
    $transaction = Transaction::create([
        'ledger_id' => $ledger->id,
        'user_id' => $me->id,
        'envelope_id' => $envelope->id,
        'amount' => $amount,
        'event_at' => $recordedAt->format('Y-m-d').' 00:00:00',
    ]);
    // The 30-day clock counts record-creation time: backdate it explicitly.
    $transaction->forceFill(['created_at' => $recordedAt])->saveQuietly();
}

echo 'seeded onboarding challenge profile='.$profile->id.PHP_EOL;
