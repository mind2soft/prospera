<?php

declare(strict_types=1);

/**
 * Playwright seed for e2e/schedules.spec.ts: the playwright user owns a
 * "Rent" expense envelope assigned to a budget published for the CURRENT
 * month, and an open ledger covering the current year — so a schedule
 * created in the drawer materializes into that month once the queue runs.
 * A profile co-member holds a contributor grant on Rent (not the owner:
 * no Schedules menu item).
 *
 * npm run e2e:seed -- seed-schedules.php
 */

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__.'/seed-reset.php';

// Jobs queued by earlier specs target rows the reset removed: start the
// queue empty so draining it runs this spec's reconciliations only.
DB::table('jobs')->delete();

$owner = User::create(['name' => 'Playwright Tester', 'email' => 'playwright@example.com', 'password' => bcrypt('Password!234')]);
$owner->forceFill(['email_verified_at' => now()])->save();
$member = User::create(['name' => 'Schedule Member', 'email' => 'schedule-member@example.com', 'password' => bcrypt('Password!234')]);
$member->forceFill(['email_verified_at' => now()])->save();

$profile = FinancialProfile::createDefaultFor($owner);
$profile->members()->attach($member->id, ['is_owner' => false, 'is_default' => true]);

$rent = $owner->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e']);
EnvelopePermission::create(['envelope_id' => $rent->id, 'user_id' => $member->id, 'permission' => 'contributor']);

$budget = Budget::create([
    'profile_id' => $profile->id,
    'currency' => 'CAD',
    'effective_year' => now()->year,
    'effective_month' => now()->month,
    'published_at' => now(),
]);
$budget->permissions()->create(['user_id' => $owner->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);
$budget->envelopes()->create(['envelope_id' => $rent->id, 'amount' => -1300, 'type' => 'fixed']);

$ledger = Ledger::create([
    'profile_id' => $profile->id,
    'name' => (string) now()->year,
    'start_date' => now()->startOfYear()->toDateString(),
    'end_date' => now()->endOfYear()->toDateString(),
    'starting_balance' => 0,
]);
$ledger->permissions()->create(['user_id' => $owner->id, 'permission' => LedgerPermission::PERMISSION_OWNER]);

echo 'seeded profile='.$profile->id.' rent='.$rent->id.' ledger='.$ledger->id.PHP_EOL;
