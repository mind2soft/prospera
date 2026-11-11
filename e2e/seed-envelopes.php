<?php

/**
 * Playwright seed for e2e/envelopes.spec.ts: the playwright user with a
 * default profile and one envelope, "Rent", assigned to a draft budget —
 * so it can neither be deleted nor change direction.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-envelopes.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\FinancialProfile;
use App\Models\User;

require __DIR__.'/seed-reset.php';

$me = User::create([
    'name' => 'Playwright Tester',
    'email' => 'playwright@example.com',
    'password' => bcrypt('Password!234'),
]);
$me->forceFill(['email_verified_at' => now()])->save();

$profile = FinancialProfile::createDefaultFor($me);

$rent = $me->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e']);

$budget = Budget::create([
    'profile_id' => $profile->id,
    'currency' => 'CAD',
    'effective_year' => 0,
    'effective_month' => 0,
]);
$budget->permissions()->create(['user_id' => $me->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);
$budget->envelopes()->create(['envelope_id' => $rent->id, 'amount' => -1300, 'type' => 'fixed']);

echo 'seeded profile='.$profile->id.' rent='.$rent->id.PHP_EOL;
