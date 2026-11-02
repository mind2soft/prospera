<?php

/**
 * Playwright seed for e2e/masked-ledger.spec.ts: a published budget owned by
 * the playwright user and an open ledger containing a transaction recorded by
 * a second member against their OWN envelope — the playwright user has no
 * permission on it, so the server masks the amounts (amount null + masked).
 *
 * Runs against the isolated e2e database (.env.e2e — see
 * Runs against the isolated e2e database (.env.e2e — see
 * e2e/serve.sh), never the developer's database:
 * npm run e2e:seed -- seed-masked.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\LedgerPermission;
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

$default = FinancialProfile::createDefaultFor($owner);
$default->members()->attach($partner->id, ['is_owner' => false]);

// Published budget owned by the playwright user (their envelopes only).
$b = Budget::create([
    'profile_id' => $default->id,
    'currency' => 'CAD',
    'effective_year' => 2026,
    'effective_month' => 10,
    'published_at' => now(),
]);
$b->permissions()->create(['user_id' => $owner->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);

$salary = $owner->envelopes()->create([
    'name' => 'Paychecks', 'type' => 'revenue', 'color' => '#10b981',
]);
$food = $owner->envelopes()->create([
    'name' => 'Food', 'type' => 'expense', 'color' => '#f59e0b',
]);
$b->envelopes()->create(['envelope_id' => $salary->id, 'amount' => 2000, 'type' => 'fixed']);
$b->envelopes()->create(['envelope_id' => $food->id, 'amount' => -800, 'type' => 'variable']);

// The partner may record against Food (contributor share) — those
// transactions stay visible to the playwright user.
EnvelopePermission::create([
    'envelope_id' => $food->id,
    'user_id' => $partner->id,
    'permission' => 'contributor',
]);

// Open ledger owned by the playwright user.
$ledger = Ledger::create([
    'profile_id' => $default->id,
    'name' => '2026',
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'starting_balance' => 5000,
]);
$ledger->permissions()->create(['user_id' => $owner->id, 'permission' => LedgerPermission::PERMISSION_OWNER]);

// The partner's own envelope — shared with NO ONE. A transaction recorded
// against it by the partner is metadata-only for the playwright user.
$secret = $partner->envelopes()->create([
    'name' => 'Secret savings', 'type' => 'expense', 'color' => '#8b5cf6',
]);

Transaction::create([
    'ledger_id' => $ledger->id,
    'user_id' => $partner->id,
    'envelope_id' => $secret->id,
    'amount' => -4321,
    'event_at' => '2026-10-10 00:00:00',
]);

// Visible control: partner records against Food (contributor there).
Transaction::create([
    'ledger_id' => $ledger->id,
    'user_id' => $partner->id,
    'envelope_id' => $food->id,
    'amount' => -250,
    'event_at' => '2026-10-11 00:00:00',
]);

echo 'seeded ledger_id='.$ledger->id.' secret_envelope_id='.$secret->id.PHP_EOL;
