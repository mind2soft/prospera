<?php

/**
 * Playwright seed for e2e/budget-autosave-profiles.spec.ts: the playwright
 * user owns the "Default profile" and belongs to a shared "Family" profile,
 * and both profiles have an editable draft budget that assigns the SAME
 * user-owned envelopes — the same envelope id is thus edited through two
 * different budget identities (docs/shared-finances.md), which is what
 * pending-save scoping must not confuse (#79).
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh),
 * never the developer's database:
 * npm run e2e:seed -- seed-budget-profiles.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\FinancialProfile;
use App\Models\User;

require __DIR__.'/seed-reset.php';

$user = User::create([
    'name' => 'Playwright Tester',
    'email' => 'playwright@example.com',
    'password' => bcrypt('Password!234'),
]);
$user->forceFill(['email_verified_at' => now()])->save();

$partner = User::create([
    'name' => 'Partner Member',
    'email' => 'partner@example.com',
    'password' => bcrypt('Password!234'),
]);
$partner->forceFill(['email_verified_at' => now()])->save();

$default = FinancialProfile::createDefaultFor($user);

$family = FinancialProfile::create(['name' => 'Family']);
$family->members()->attach($partner->id, ['is_owner' => true]);
$family->members()->attach($user->id, ['is_owner' => false]);

// Envelopes belong to the user, not to a profile: both drafts reference the
// very same envelope rows, so an envelope id alone does not identify an edit.
$paychecks = $user->envelopes()->create([
    'name' => 'Paychecks',
    'type' => 'revenue',
    'color' => '#10b981',
]);
$food = $user->envelopes()->create([
    'name' => 'Food',
    'type' => 'expense',
    'color' => '#f59e0b',
]);

$draft = function (FinancialProfile $profile, int $paychecksAmount, int $foodAmount) use ($user, $paychecks, $food) {
    $budget = Budget::create([
        'profile_id' => $profile->id,
        'currency' => 'CAD',
        'effective_year' => 0,
        'effective_month' => 0,
    ]);
    $budget->permissions()->create([
        'user_id' => $user->id,
        'permission' => BudgetPermission::PERMISSION_OWNER,
    ]);
    $budget->envelopes()->create([
        'envelope_id' => $paychecks->id,
        'amount' => $paychecksAmount,
        'type' => 'fixed',
    ]);
    $budget->envelopes()->create([
        'envelope_id' => $food->id,
        'amount' => -$foodAmount,
        'type' => 'variable',
    ]);

    return $budget;
};

$defaultBudget = $draft($default, 2000, 800);
$familyBudget = $draft($family, 1500, 500);

echo 'seeded default_profile='.$default->id.' default_budget='.$defaultBudget->id
    .' family_profile='.$family->id.' family_budget='.$familyBudget->id.PHP_EOL;
