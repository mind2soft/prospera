<?php

/**
 * Playwright seed for the ledger spec WITHOUT a published budget: resets two
 * dedicated users to a known state.
 *
 *  - playwright-plain@example.com: one open ledger + one envelope, NO budget
 *    (transactions must be recordable — budgets are for reporting only).
 *  - playwright-bare@example.com: one open ledger, NO envelopes (the entry
 *    form is replaced by the "envelopes required" banner).
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh),
 * never the developer's database:
 * npm run e2e:seed -- seed-ledger-plain.php
 */

use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\Transaction;
use App\Models\User;

$mk = function (string $email): array {
    $u = User::updateOrCreate(['email' => $email], [
        'name' => 'Playwright Tester',
        'password' => bcrypt('Password!234'),
    ]);
    $u->forceFill(['email_verified_at' => now()])->save();

    $profile = FinancialProfile::query()
        ->where('is_default', true)
        ->whereHas('members', fn ($q) => $q->whereKey($u->id))
        ->first() ?? FinancialProfile::createDefaultFor($u);

    Transaction::whereIn('ledger_id', Ledger::query()->where('profile_id', $profile->id)->select('id'))->delete();
    Ledger::query()->where('profile_id', $profile->id)->get()->each(fn (Ledger $l) => $l->delete());
    $u->envelopes()->delete();

    $ledger = Ledger::create([
        'profile_id' => $profile->id,
        'name' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'starting_balance' => 5000,
    ]);
    $ledger->permissions()->create(['user_id' => $u->id, 'permission' => LedgerPermission::PERMISSION_OWNER]);

    return [$u, $ledger];
};

[$plain, $plainLedger] = $mk('playwright-plain@example.com');
$plain->envelopes()->create(['name' => 'Groceries', 'type' => 'expense', 'color' => '#f59e0b']);

[$bare, $bareLedger] = $mk('playwright-bare@example.com');

echo 'seeded plain_ledger_id='.$plainLedger->id.' bare_ledger_id='.$bareLedger->id.PHP_EOL;
