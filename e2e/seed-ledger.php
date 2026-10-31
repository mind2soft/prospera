<?php

/**
 * Playwright seed for the ledger spec: resets the playwright@example.com user
 * to a known state — one published budget, one open ledger with transactions.
 *
 * Run order: seed.php first, then seed-ledger.php — both reuse the same
 * default profile of the playwright user (seed.php wipes budgets/ledgers, so
 * run seed-ledger.php after it when a published budget is needed).
 *
 * Runs against the isolated e2e database (.env.e2e — see
 * Runs against the isolated e2e database (.env.e2e — see
 * e2e/serve.sh), never the developer's database:
 * npm run e2e:seed -- seed-ledger.php
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
    ->where('is_default', true)
    ->whereHas('members', fn ($q) => $q->whereKey($u->id))
    ->first() ?? FinancialProfile::createDefaultFor($u);

// Transactions first (they reference the ledgers and budgets being deleted).
Transaction::whereIn('ledger_id', Ledger::query()->where('profile_id', $profile->id)->select('id'))->delete();
Ledger::query()->where('profile_id', $profile->id)->get()->each(fn (Ledger $l) => $l->delete());
Budget::query()->where('profile_id', $profile->id)->get()->each(fn (Budget $b) => $b->delete());
$u->envelopes()->delete();

$b = Budget::create([
    'profile_id' => $profile->id,
    'currency' => 'CAD',
    'effective_year' => 2026,
    'effective_month' => 10,
    'published_at' => now(),
]);
$b->permissions()->create(['user_id' => $u->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);

$mk = function (string $name, string $type, string $color) use ($u) {
    return $u->envelopes()->create(['name' => $name, 'type' => $type, 'color' => $color]);
};
$salary = $mk('Paychecks', 'revenue', '#10b981');
$food = $mk('Food', 'expense', '#f59e0b');
$rent = $mk('Mortgage', 'expense', '#f43f5e');

$add = function ($cat, int $amount, string $type) use ($b) {
    return $b->envelopes()->create([
        'envelope_id' => $cat->id,
        'amount' => $cat->type === 'expense' ? -$amount : $amount,
        'type' => $type,
    ]);
};
$add($salary, 2000, 'fixed');
$add($food, 800, 'variable');
$add($rent, 1300, 'fixed');

$ledger = Ledger::create([
    'profile_id' => $profile->id,
    'name' => '2026',
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'starting_balance' => 5000,
]);
$ledger->permissions()->create(['user_id' => $u->id, 'permission' => LedgerPermission::PERMISSION_OWNER]);

$tx = function ($assignment, int $amount, string $date, int $offsetSeconds) use ($ledger, $u, $b) {
    Transaction::create([
        'ledger_id' => $ledger->id,
        'user_id' => $u->id,
        'envelope_id' => $assignment->envelope_id,
        'budget_id' => $b->id,
        'amount' => $assignment->envelope->type === 'expense' ? -$amount : $amount,
        'event_at' => $date.' 00:00:00',
        'created_at' => now()->addSeconds($offsetSeconds),
        'updated_at' => now()->addSeconds($offsetSeconds),
    ]);
};

// October 2026 (current view month): Paychecks 2000, Food 120, Rent 1300.
$tx($b->envelopes()->where('envelope_id', $salary->id)->first(), 1800, '2026-10-01', 1);
$tx($b->envelopes()->where('envelope_id', $food->id)->first(), 120, '2026-10-15', 2);
$tx($b->envelopes()->where('envelope_id', $rent->id)->first(), 1250, '2026-10-20', 3);
// September 2026 (for month navigation checks).
$tx($b->envelopes()->where('envelope_id', $food->id)->first(), 90, '2026-09-10', 4);

echo 'seeded profile_id='.$profile->id.' budget_id='.$b->id.' ledger_id='.$ledger->id.PHP_EOL;
