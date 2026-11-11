<?php

/**
 * Playwright seed for e2e/invite-states.spec.ts: the playwright user owns a
 * Default profile with pending invites covering the confirmation page's
 * states (deterministic link keys):
 *
 *   RETURNING_KEY  → for a registered, signed-out user ("Log in" keeps the invite)
 *   UNVERIFIED_KEY → for a registered user whose email is not verified yet
 *   OTHER_KEY      → for an address with no account (opened by another user)
 *   EXPIRED_KEY    → expired
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh),
 * never the developer's database:
 * npm run e2e:seed -- seed-invite-states.php
 */

use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\User;

require __DIR__.'/seed-reset.php';

$user = function (string $name, string $email, bool $verified = true): User {
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => bcrypt('Password!234'),
    ]);

    if ($verified) {
        $user->forceFill(['email_verified_at' => now()])->save();
    }

    return $user;
};

$owner = $user('Playwright Tester', 'playwright@example.com');
$returning = $user('Returning Member', 'returning@example.com');
$user('Unverified Member', 'unverified@example.com', verified: false);

$default = FinancialProfile::createDefaultFor($owner);
FinancialProfile::createDefaultFor($returning);

$mk = fn (string $email, string $rawKey, bool $expired = false) => FinancialProfileInvite::create([
    'profile_id' => $default->id,
    'invite_key' => FinancialProfileInvite::keyHash($rawKey),
    'email' => $email,
    'invited_by' => $owner->id,
    'expires_at' => $expired ? now()->subHour() : now()->addHours(48),
]);

$mk('returning@example.com', str_repeat('g', 40));
$mk('unverified@example.com', str_repeat('h', 40));
$mk('someone-else@example.com', str_repeat('i', 40));
$mk('late@example.com', str_repeat('j', 40), expired: true);

echo 'seeded profile_id='.$default->id.PHP_EOL;
