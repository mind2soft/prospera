<?php

declare(strict_types=1);

// Add internal schedule storage to seed-envelopes.php's isolated fixture.
// There is deliberately no schedule UI/API yet.
use App\Models\User;

$me = User::query()->where('email', 'playwright@example.com')->sole();
$envelope = $me->envelopes()->create(['name' => 'Insurance', 'type' => 'expense', 'color' => '#123456']);
$envelope->schedules()->create(['amount' => -12345, 'period' => 'once', 'start_date' => '2026-01-01']);

echo 'seeded scheduled envelope='.$envelope->id.PHP_EOL;
