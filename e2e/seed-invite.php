<?php

/**
 * Playwright seed for e2e/invite.spec.ts: the playwright user owns a Default
 * profile with three pending invites with deterministic link keys:
 *
 *   REGISTER_KEY → for a brand-new user (registration through the link)
 *   ACCEPT_KEY   → for the seeded partner (signed-in accept flow)
 *   DECLINE_KEY  → for a seeded third user (decline keeps them out)
 *
 * Runs against the isolated e2e database (.env.e2e — see
 * Runs against the isolated e2e database (.env.e2e — see
 * e2e/serve.sh), never the developer's database:
 * npm run e2e:seed -- seed-invite.php
 */

use App\Models\Budget;
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

$waverer = User::create([
    'name' => 'Waverer Member',
    'email' => 'waverer@example.com',
    'password' => bcrypt('Password!234'),
]);
$waverer->forceFill(['email_verified_at' => now()])->save();

$default = FinancialProfile::createDefaultFor($owner);

$mk = function (string $email, string $rawKey) use ($default, $owner) {
    return FinancialProfileInvite::create([
        'profile_id' => $default->id,
        'invite_key' => FinancialProfileInvite::keyHash($rawKey),
        'email' => $email,
        'invited_by' => $owner->id,
        'expires_at' => now()->addHours(48),
    ]);
};

$registerKey = str_repeat('a', 40);
$acceptKey = str_repeat('b', 40);
$declineKey = str_repeat('c', 40);

$mk('invitee@example.com', $registerKey);
$mk('partner@example.com', $acceptKey);
$mk('waverer@example.com', $declineKey);

echo 'seeded profile_id='.$default->id.PHP_EOL;
echo 'register_key='.$registerKey.PHP_EOL;
echo 'accept_key='.$acceptKey.PHP_EOL;
echo 'decline_key='.$declineKey.PHP_EOL;
