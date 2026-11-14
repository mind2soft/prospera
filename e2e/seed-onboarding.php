<?php

/**
 * Playwright seed for e2e/onboarding.spec.ts: a brand-new playwright user
 * (default profile, no envelopes, ledgers or budgets) — the start of the
 * setup journey — plus a partner who owns a shared "Family" profile the
 * playwright user is a member of (the read-only journey).
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-onboarding.php
 */

use App\Models\FinancialProfile;
use App\Models\User;

require __DIR__.'/seed-reset.php';

$me = User::create([
    'name' => 'Playwright Tester',
    'email' => 'playwright@example.com',
    'password' => bcrypt('Password!234'),
]);
$me->forceFill(['email_verified_at' => now()])->save();
FinancialProfile::createDefaultFor($me);

$partner = User::create([
    'name' => 'Pat Partner',
    'email' => 'partner@example.com',
    'password' => bcrypt('Password!234'),
]);
$partner->forceFill(['email_verified_at' => now()])->save();
FinancialProfile::createDefaultFor($partner);

$family = FinancialProfile::create(['name' => 'Family']);
$family->members()->attach($partner->id, ['is_owner' => true]);
$family->members()->attach($me->id, ['is_owner' => false]);

echo 'seeded onboarding'.PHP_EOL;
