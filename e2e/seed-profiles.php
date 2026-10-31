<?php

/**
 * Playwright seed for e2e/profiles.spec.ts: the playwright user owns a
 * "Default profile" (with a published budget so it is visually distinct) and
 * is a collaborator in a shared "Family" profile owned by a second user.
 *
 * Runs against the isolated e2e database (.env.e2e — see
 * Runs against the isolated e2e database (.env.e2e — see
 * e2e/serve.sh), never the developer's database:
 * npm run e2e:seed -- seed-profiles.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Enveloppe;
use App\Models\EnveloppePermission;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;

// Full reset (dependency order): transactions → ledgers → budgets →
// enveloppe permissions → enveloppes → invites → profiles (cascades memberships)
// → users.
Transaction::query()->delete();
Ledger::query()->delete();
Budget::query()->delete();
EnveloppePermission::query()->delete();
Enveloppe::query()->delete();
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

// The shared profile: owned by the partner, playwright is a collaborator.
$family = FinancialProfile::create(['name' => 'Family', 'is_default' => false]);
$family->members()->attach($partner->id, ['is_owner' => true]);
$family->members()->attach($owner->id, ['is_owner' => false]);

// A published budget in the default profile so the two profiles render
// distinct budget pages.
$b = Budget::create([
    'profile_id' => $default->id,
    'currency' => 'CAD',
    'effective_year' => 2026,
    'effective_month' => 10,
    'published_at' => now(),
]);
$b->permissions()->create(['user_id' => $owner->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);

$mk = fn (string $name, string $type, string $color) => $owner->enveloppes()->create([
    'name' => $name,
    'type' => $type,
    'color' => $color,
]);
$salary = $mk('Paychecks', 'revenue', '#10b981');
$food = $mk('Food', 'expense', '#f59e0b');

$b->enveloppes()->create(['enveloppe_id' => $salary->id, 'amount' => 2000, 'type' => 'fixed']);
$b->enveloppes()->create(['enveloppe_id' => $food->id, 'amount' => -800, 'type' => 'variable']);

echo 'seeded default_profile='.$default->id.' family_profile='.$family->id.PHP_EOL;
