<?php

/**
 * Playwright seed for the contact specs: clears the submission log so the
 * 12-hour per-IP cooldown never carries over between suite runs (the log is
 * written by every successful e2e submission — see e2e/contact.spec.ts),
 * and ensures the signed-in flow's account exists (verified, with a default
 * profile) so the spec also runs on its own.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-contact.php
 */

use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::table('contact_submissions')->delete();

$u = User::updateOrCreate(['email' => 'playwright@example.com'], [
    'name' => 'Playwright Tester',
    'password' => bcrypt('Password!234'),
]);
$u->forceFill(['email_verified_at' => now()])->save();

if (! $u->profiles()->exists()) {
    FinancialProfile::createDefaultFor($u);
}
