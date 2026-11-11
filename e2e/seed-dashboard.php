<?php

/**
 * Playwright seed for the dashboard spec: resets the playwright@example.com
 * user to a known state — one published budget (current month) covering the
 * envelope allocations, one open ledger with recent transactions, and no
 * dashboard widgets (the dashboard starts empty).
 *
 * Run order: after seed.php (it wipes budgets/ledgers/envelopes). Run against
 * the isolated e2e database (.env.e2e — see e2e/serve.sh), never the
 * developer's database:
 *
 * npm run e2e:seed -- seed-dashboard.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

$u = User::updateOrCreate(['email' => 'playwright@example.com'], [
    'name' => 'Playwright Tester',
    'password' => bcrypt('Password!234'),
]);
$u->forceFill(['email_verified_at' => now()])->save();

$profile = FinancialProfile::query()
    ->whereHas('members', fn ($q) => $q->whereKey($u->id)->where('financial_profile_user.is_default', true))
    ->first() ?? FinancialProfile::createDefaultFor($u);

// Transactions first (they reference the ledgers being deleted).
Transaction::whereIn('ledger_id', Ledger::query()->where('profile_id', $profile->id)->select('id'))->delete();
Ledger::query()->where('profile_id', $profile->id)->get()->each(fn (Ledger $l) => $l->delete());
Budget::query()->where('profile_id', $profile->id)->get()->each(fn (Budget $b) => $b->delete());
$u->envelopes()->delete();

$today = Carbon::today();

// The published budget covering the current month — the dashboard's budget
// progress comes from published budgets covering the widget's period.
$b = Budget::create([
    'profile_id' => $profile->id,
    'currency' => 'CAD',
    'effective_year' => $today->year,
    'effective_month' => $today->month,
    'published_at' => now(),
]);
$b->permissions()->create(['user_id' => $u->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);

$mk = function (string $name, string $type, string $color) use ($u) {
    return $u->envelopes()->create(['name' => $name, 'type' => $type, 'color' => $color]);
};
$salary = $mk('Paychecks', 'revenue', '#10b981');
$food = $mk('Food', 'expense', '#f59e0b');
$rent = $mk('Mortgage', 'expense', '#f43f5e');

$add = function ($envelope, int $amount, string $type) use ($b) {
    return $b->envelopes()->create([
        'envelope_id' => $envelope->id,
        'amount' => $envelope->type === 'expense' ? -$amount : $amount,
        'type' => $type,
    ]);
};
$add($salary, 2000, 'fixed');
$add($food, 800, 'variable');
$add($rent, 1300, 'fixed');

// Not allocated on the budget — its progress bar must stay empty.
$fun = $mk('Fun money', 'expense', '#a78bfa');

$ledger = Ledger::create([
    'profile_id' => $profile->id,
    'name' => '2026',
    'start_date' => $today->copy()->startOfYear()->toDateString(),
    'end_date' => $today->copy()->endOfYear()->toDateString(),
    'starting_balance' => 5000,
]);
$ledger->permissions()->create(['user_id' => $u->id, 'permission' => LedgerPermission::PERMISSION_OWNER]);

$tx = function ($envelope, int $amount, Carbon $date) use ($ledger, $u) {
    Transaction::create([
        'ledger_id' => $ledger->id,
        'user_id' => $u->id,
        'envelope_id' => $envelope->id,
        'amount' => $amount,
        'event_at' => $date->startOfDay(),
    ]);
};

$tx($salary, 2000, $today->copy());
$tx($food, -120, $today->copy());
$tx($food, -450, $today->copy()->subDays(3));
$tx($rent, -1300, $today->copy()->subDays(5));
$tx($fun, -25, $today->copy());

echo "dashboard seed ok: profile {$profile->id}\n";
