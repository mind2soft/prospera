<?php

declare(strict_types=1);

use App\Models\FinancialProfile;
use App\Models\User;

require __DIR__.'/seed-reset.php';

$owner = User::create(['name' => 'Schedule Owner', 'email' => 'playwright@example.com', 'password' => bcrypt('Password!234')]);
$owner->forceFill(['email_verified_at' => now()])->save();
$member = User::create(['name' => 'Schedule Member', 'email' => 'schedule-member@example.com', 'password' => bcrypt('Password!234')]);
$member->forceFill(['email_verified_at' => now()])->save();
$profile = FinancialProfile::createDefaultFor($owner);
$profile->members()->attach($member->id, ['is_owner' => false, 'is_default' => true]);
$owner->envelopes()->create(['name' => 'Schedule API Rent', 'type' => 'expense', 'color' => '#123456']);

echo 'seeded schedule API profile='.$profile->id.PHP_EOL;
