<?php

/**
 * Playwright seed: resets the playwright@example.com user to a known state —
 * one draft budget with the envelopes used by e2e/budget.spec.ts.
 *
 * Run order: seed.php first, then seed-ledger.php — both reuse the same
 * default profile of the playwright user (seed.php wipes budgets/ledgers, so
 * run seed-ledger.php after it when a published budget is needed).
 *
 * Runs against the isolated e2e database (.env.e2e — see
 * e2e/serve.sh), never the developer's database:
 * npm run e2e:seed -- seed.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
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

// Clear in dependency order: transactions → ledgers → budgets (the profile
// and envelope foreign keys are restrict-on-delete).
Transaction::whereIn('ledger_id', Ledger::query()->where('profile_id', $profile->id)->select('id'))->delete();
Ledger::query()->where('profile_id', $profile->id)->get()->each(fn (Ledger $l) => $l->delete());
Budget::query()->where('profile_id', $profile->id)->get()->each(fn (Budget $b) => $b->delete());
$u->envelopes()->delete();

$b = Budget::create([
    'profile_id' => $profile->id,
    'currency' => 'CAD',
    'effective_year' => 0,
    'effective_month' => 0,
]);
$b->permissions()->create(['user_id' => $u->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);

$mk = function (string $name, string $type, string $color) use ($u) {
    return $u->envelopes()->create(['name' => $name, 'type' => $type, 'color' => $color]);
};
$salary = $mk('Paychecks', 'revenue', '#10b981');
$side = $mk('Passive income', 'revenue', '#3b82f6');
$rent = $mk('Mortgage', 'expense', '#f43f5e');
$food = $mk('Food', 'expense', '#f59e0b');
$gas = $mk('Gas', 'expense', '#8b5cf6');
$stream = $mk('Streaming', 'expense', '#14b8a6');

$add = function ($cat, int $amount, string $type) use ($b) {
    $b->envelopes()->create([
        'envelope_id' => $cat->id,
        'amount' => $cat->type === 'expense' ? -$amount : $amount,
        'type' => $type,
    ]);
};
$add($salary, 2000, 'fixed');
$add($side, 1000, 'variable');
$add($rent, 1300, 'fixed');
$add($food, 800, 'variable');
$add($gas, 300, 'variable');
$add($stream, 30, 'fixed');

echo 'seeded profile_id='.$profile->id.' budget_id='.$b->id.PHP_EOL;
