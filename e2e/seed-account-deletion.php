<?php

/**
 * Playwright seed for e2e/account-deletion.spec.ts: a verified
 * "leaving@example.com" account with its own default profile, an envelope
 * and a ledger — recreated from scratch on every run.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-account-deletion.php
 */

use App\Actions\DeleteAccount;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;

$existing = User::query()->where('email', 'leaving@example.com')->first();

if ($existing !== null) {
    app(DeleteAccount::class)->handle($existing);
}

$user = User::create([
    'name' => 'Leaving User',
    'email' => 'leaving@example.com',
    'password' => bcrypt('Password!234'),
]);
$user->forceFill(['email_verified_at' => now()])->save();

$profile = FinancialProfile::createDefaultFor($user);
$user->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e']);

$ledger = Ledger::create([
    'profile_id' => $profile->id,
    'name' => (string) now()->year,
    'start_date' => now()->startOfYear(),
    'end_date' => now()->endOfYear(),
    'starting_balance' => 0,
]);
$ledger->permissions()->create(['user_id' => $user->id, 'permission' => 'owner']);

echo 'seeded user='.$user->id.' profile='.$profile->id.PHP_EOL;
