<?php

/**
 * Playwright seed for e2e/dashboard-autosave.spec.ts: the playwright user is
 * a member of two profiles — their own "Default profile" and a shared
 * "Family" profile — with two titled widgets on each profile's dashboard.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh),
 * never the developer's database:
 * npm run e2e:seed -- seed-dashboard-profiles.php
 */

use App\Models\DashboardWidget;
use App\Models\FinancialProfile;
use App\Models\User;

require __DIR__.'/seed-reset.php';

$user = User::create([
    'name' => 'Playwright Tester',
    'email' => 'playwright@example.com',
    'password' => bcrypt('Password!234'),
]);
$user->forceFill(['email_verified_at' => now()])->save();

$partner = User::create([
    'name' => 'Partner Member',
    'email' => 'partner@example.com',
    'password' => bcrypt('Password!234'),
]);
$partner->forceFill(['email_verified_at' => now()])->save();

$default = FinancialProfile::createDefaultFor($user);

$family = FinancialProfile::create(['name' => 'Family']);
$family->members()->attach($partner->id, ['is_owner' => true]);
$family->members()->attach($user->id, ['is_owner' => false]);

$widgets = [
    [$default, ['Home spending', 'Home trend']],
    [$family, ['Family spending', 'Family trend']],
];

foreach ($widgets as [$profile, $titles]) {
    foreach ($titles as $order => $title) {
        DashboardWidget::create([
            'user_id' => $user->id,
            'financial_profile_id' => $profile->id,
            'type' => DashboardWidget::TYPE_ENVELOPE_SPENDING,
            'title' => $title,
            'view_type' => 'single',
            'column_width' => 2,
            'period_type' => DashboardWidget::PERIOD_MONTHS,
            'period_range' => 1,
            'selection' => [],
            'sort_order' => $order,
        ]);
    }
}

echo 'seeded default_profile='.$default->id.' family_profile='.$family->id.PHP_EOL;
