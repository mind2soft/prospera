<?php

/**
 * Playwright seed for e2e/profile-invites.spec.ts: the playwright user owns
 * a solo "Household" profile without pending invites.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-profile-invites.php
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

$household = FinancialProfile::createOwnedBy($me, 'Household', isDefault: true);

echo 'seeded household='.$household->id.PHP_EOL;
