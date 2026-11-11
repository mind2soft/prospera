<?php

/**
 * Playwright seed for e2e/profile-invites.spec.ts: the playwright user owns
 * a solo "Household" profile without pending invites.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-profile-invites.php
 */

use App\Models\Budget;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;

// Full reset, in dependency order.
Transaction::query()->delete();
Ledger::query()->delete();
Budget::query()->delete();
EnvelopePermission::query()->delete();
Envelope::query()->delete();
FinancialProfileInvite::query()->delete();
FinancialProfile::query()->delete();
User::query()->delete();

$me = User::create([
    'name' => 'Playwright Tester',
    'email' => 'playwright@example.com',
    'password' => bcrypt('Password!234'),
]);
$me->forceFill(['email_verified_at' => now()])->save();

$household = FinancialProfile::createOwnedBy($me, 'Household', isDefault: true);

echo 'seeded household='.$household->id.PHP_EOL;
