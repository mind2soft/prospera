<?php

/**
 * Playwright seed for e2e/profile-members.spec.ts: the playwright user owns
 * "Household" (their default) with "Partner Member" as a collaborator, and
 * is a collaborator in "Family", owned by the partner.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-profile-members.php
 */

use App\Models\FinancialProfile;
use App\Models\User;

require __DIR__.'/seed-reset.php';

$user = fn (string $name, string $email): User => tap(User::create([
    'name' => $name,
    'email' => $email,
    'password' => bcrypt('Password!234'),
]), fn (User $u) => $u->forceFill(['email_verified_at' => now()])->save());

$me = $user('Playwright Tester', 'playwright@example.com');
$partner = $user('Partner Member', 'partner@example.com');

$household = FinancialProfile::createOwnedBy($me, 'Household', isDefault: true);
$household->members()->attach($partner->id, ['is_owner' => false]);

$family = FinancialProfile::createOwnedBy($partner, 'Family', isDefault: true);
$family->members()->attach($me->id, ['is_owner' => false]);

echo 'seeded household='.$household->id.' family='.$family->id.PHP_EOL;
