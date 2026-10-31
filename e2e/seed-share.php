<?php

/**
 * Playwright seed for e2e/share.spec.ts: a published budget owned by the
 * playwright user, shared with a second profile member as contributor.
 *
 * Runs against the isolated e2e database (.env.e2e — see
 * Runs against the isolated e2e database (.env.e2e — see
 * e2e/serve.sh), never the developer's database:
 * npm run e2e:seed -- seed-share.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;

// Full reset (dependency order).
Transaction::query()->delete();
Ledger::query()->delete();
Budget::query()->delete();
EnvelopePermission::query()->delete();
Envelope::query()->delete();
FinancialProfileInvite::query()->delete();
FinancialProfile::query()->delete();
User::query()->delete();

$owner = User::create([
    'name' => 'Playwright Tester',
    'email' => 'playwright@example.com',
    'password' => bcrypt('Password!234'),
]);
$owner->forceFill(['email_verified_at' => now()])->save();

$partner = User::create([
    'name' => 'Partner Member',
    'email' => 'partner@example.com',
    'password' => bcrypt('Password!234'),
]);
$partner->forceFill(['email_verified_at' => now()])->save();

$default = FinancialProfile::createDefaultFor($owner);
$default->members()->attach($partner->id, ['is_owner' => false]);

$b = Budget::create([
    'profile_id' => $default->id,
    'currency' => 'CAD',
    'effective_year' => 2026,
    'effective_month' => 10,
    'published_at' => now(),
]);
$b->permissions()->create(['user_id' => $owner->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);
// The partner is an explicit contributor on the budget (the Share drawer
// escalates the implicit viewer default).
$b->permissions()->create(['user_id' => $partner->id, 'permission' => BudgetPermission::PERMISSION_CONTRIBUTOR]);

$mk = fn (string $name, string $type, string $color) => $owner->envelopes()->create([
    'name' => $name,
    'type' => $type,
    'color' => $color,
]);
$salary = $mk('Paychecks', 'revenue', '#10b981');
$food = $mk('Food', 'expense', '#f59e0b');

$b->envelopes()->create(['envelope_id' => $salary->id, 'amount' => 2000, 'type' => 'fixed']);
$b->envelopes()->create(['envelope_id' => $food->id, 'amount' => -800, 'type' => 'variable']);

echo 'seeded profile_id='.$default->id.' budget_id='.$b->id.' partner_id='.$partner->id.PHP_EOL;
