<?php

/**
 * Playwright seed for e2e/ledger-allocation-invalidation.spec.ts: resets the
 * playwright@example.com user to a known state — a PUBLISHED budget for the
 * current month (Food allocated 800), an alternative DRAFT for the same
 * month (Food allocated 500), and one open ledger with a single expense
 * transaction of 120 in that month.
 *
 * The spec exercises the client's stale-cache window (the app-wide 30 s
 * staleTime): the seeded month must be the month the Ledger page opens on,
 * so the budget month and the transaction date are derived from the server
 * clock (UTC) and the spec pins the browser to UTC.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh),
 * never the developer's database:
 * npm run e2e:seed -- seed-ledger-invalidation.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\Transaction;
use App\Models\User;

$u = User::updateOrCreate(['email' => 'playwright@example.com'], [
    'name' => 'Playwright Tester',
    'password' => bcrypt('Password!234'),
]);
$u->forceFill(['email_verified_at' => now()])->save();

$profile = FinancialProfile::query()
    ->whereHas('members', fn ($q) => $q->whereKey($u->id)->where('financial_profile_user.is_default', true))
    ->first() ?? FinancialProfile::createDefaultFor($u);

// Clear in dependency order: transactions → ledgers → budgets (the profile
// and envelope foreign keys are restrict-on-delete).
Transaction::whereIn('ledger_id', Ledger::query()->where('profile_id', $profile->id)->select('id'))->delete();
Ledger::query()->where('profile_id', $profile->id)->get()->each(fn (Ledger $l) => $l->delete());
Budget::query()->where('profile_id', $profile->id)->get()->each(fn (Budget $b) => $b->delete());
require_once __DIR__.'/seed-helpers.php';
clear_envelopes_of($u);

$month = now()->startOfMonth();
$year = (int) $month->format('Y');
$mon = (int) $month->format('n');

$mk = function (string $name, string $type, string $color) use ($u) {
    return $u->envelopes()->create(['name' => $name, 'type' => $type, 'color' => $color]);
};
$food = $mk('Food', 'expense', '#f59e0b');
$salary = $mk('Paychecks', 'revenue', '#10b981');

$add = function ($budget, $envelope, int $amount, string $type) {
    return $budget->envelopes()->create([
        'envelope_id' => $envelope->id,
        'amount' => $envelope->type === 'expense' ? -$amount : $amount,
        'type' => $type,
    ]);
};

// The published budget the ledger report is derived from: Food 800.
$published = Budget::create([
    'profile_id' => $profile->id,
    'currency' => 'CAD',
    'effective_year' => $year,
    'effective_month' => $mon,
    'published_at' => now(),
]);
$published->permissions()->create(['user_id' => $u->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);
$add($published, $salary, 2000, 'fixed');
$add($published, $food, 800, 'variable');

// The alternative revision the spec publishes over it: Food 500. It must
// stay a draft (no effective month, no published_at).
$draft = Budget::create([
    'profile_id' => $profile->id,
    'currency' => 'CAD',
    'effective_year' => 0,
    'effective_month' => 0,
]);
$draft->permissions()->create(['user_id' => $u->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);
$add($draft, $salary, 2000, 'fixed');
$add($draft, $food, 500, 'variable');

$ledger = Ledger::create([
    'profile_id' => $profile->id,
    'name' => 'Current month',
    'start_date' => $month->toDateString(),
    'end_date' => $month->copy()->endOfMonth()->toDateString(),
    'starting_balance' => 5000,
]);
$ledger->permissions()->create(['user_id' => $u->id, 'permission' => LedgerPermission::PERMISSION_OWNER]);

Transaction::create([
    'ledger_id' => $ledger->id,
    'user_id' => $u->id,
    'envelope_id' => $food->id,
    'amount' => -120,
    'event_at' => $month->copy()->addDays(14)->toDateString().' 00:00:00',
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'seeded profile_id='.$profile->id
    .' published_id='.$published->id
    .' draft_id='.$draft->id
    .' ledger_id='.$ledger->id
    .' month='.$month->format('Y-m')
    .PHP_EOL;
