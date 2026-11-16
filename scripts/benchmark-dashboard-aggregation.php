<?php

/**
 * Repeatable baseline for dashboard aggregation (issue #86).
 *
 * Seeds a synthetic encrypted transaction history into a disposable SQLite
 * database in the system temp directory — never the configured application
 * database — then measures time and peak memory of the dashboard aggregate
 * payloads for the three widget types over the same (overlapping) selection.
 *
 * Each variant is measured after memory_reset_peak_usage(), so peak-memory
 * numbers are comparable within one process; for fully comparable peaks run
 * one variant per process:
 *
 *   php scripts/benchmark-dashboard-aggregation.php --rows=10000 --type=envelope_spending
 *   php scripts/benchmark-dashboard-aggregation.php --rows=10000 --type=income_expenses
 *   php scripts/benchmark-dashboard-aggregation.php --rows=10000 --type=radial
 *   php scripts/benchmark-dashboard-aggregation.php --rows=50000
 *
 * Output lines (one per variant):
 *   <rows> <type> <elapsed_ms> <peak_mb> <scanned_rows>
 */

use App\Models\DashboardWidget;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use App\Services\Dashboard\DashboardAggregator;
use App\Services\Dashboard\TransactionScan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$rows = 10_000;
$type = 'all';

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--rows=(\d+)$/', $arg, $m) === 1) {
        $rows = (int) $m[1];
    } elseif (preg_match('/^--type=([a-z_]+)$/', $arg, $m) === 1) {
        $type = $m[1];
    } else {
        fwrite(STDERR, "Unknown argument [{$arg}].\n");

        exit(1);
    }
}

if (! in_array($type, ['all', 'envelope_spending', 'income_expenses', 'radial'], true)) {
    fwrite(STDERR, "Unknown type [{$type}].\n");

    exit(1);
}

// A disposable database: a fresh SQLite file the script deletes at the end.
$database = tempnam(sys_get_temp_dir(), 'prospera-bench-').'.sqlite';

$config = $app['config'];
$config->set('database.connections.benchmark', [
    'driver' => 'sqlite',
    'database' => $database,
    'prefix' => '',
    'foreign_key_constraints' => true,
]);
$config->set('database.default', 'benchmark');

Artisan::call('migrate', ['--force' => true]);

try {
    $user = User::factory()->create();
    $profile = FinancialProfile::createOwnedBy($user, 'Benchmark');
    $ledger = Ledger::create([
        'profile_id' => $profile->id,
        'name' => 'Benchmark ledger',
        'start_date' => '2020-01-01',
        'end_date' => '2030-12-31',
        'starting_balance' => 0,
    ]);

    $expenses = $user->envelopes()->create([
        'name' => 'Expenses', 'type' => 'expense', 'color' => '#ef4444',
    ]);
    $revenue = $user->envelopes()->create([
        'name' => 'Revenue', 'type' => 'revenue', 'color' => '#3b82f6',
    ]);

    $today = CarbonImmutable::today();

    // Synthetic history: every row inside the longest period (12 months),
    // alternating envelopes, cents everywhere (amounts like -123.45).
    $seededAt = microtime(true);

    DB::transaction(function () use ($rows, $ledger, $user, $expenses, $revenue, $today): void {
        for ($i = 1; $i <= $rows; $i++) {
            $expense = $i % 2 === 1;
            $minor = ($i % 9973) + 1; // exact cents, deterministic

            $ledger->transactions()->create([
                'user_id' => $user->id,
                'envelope_id' => $expense ? $expenses->id : $revenue->id,
                'amount' => $expense ? -($minor / 100) : $minor / 100,
                'event_at' => $today->subDays($i % 365)->startOfDay(),
            ]);
        }
    });

    $seedSeconds = microtime(true) - $seededAt;

    $selection = [
        ['kind' => 'envelope', 'envelope_id' => $expenses->id],
        ['kind' => 'envelope', 'envelope_id' => $revenue->id],
        ['kind' => 'group', 'name' => 'All', 'envelope_ids' => [$expenses->id, $revenue->id]],
    ];

    $types = $type === 'all'
        ? ['envelope_spending', 'income_expenses', 'radial']
        : [$type];

    foreach ($types as $widgetType) {
        $widget = DashboardWidget::create([
            'user_id' => $user->id,
            'financial_profile_id' => $profile->id,
            'type' => $widgetType,
            'title' => null,
            'view_type' => 'single',
            'column_width' => 3,
            'period_type' => 'months',
            'period_range' => 12,
            'selection' => $selection,
            'sort_order' => 0,
        ]);

        $aggregator = $app->make(DashboardAggregator::class);

        memory_reset_peak_usage();

        $startedAt = hrtime(true);
        $payload = $aggregator->data($widget, $profile, $user, $today);
        $elapsedMs = (hrtime(true) - $startedAt) / 1e6;

        $peakMb = memory_get_peak_usage(true) / 1048576;
        $scanned = count($payload['rows'] ?? $payload['series'] ?? []);

        printf(
            "%d %s %.1fms %.1fMB %d\n",
            $rows,
            $widgetType,
            $elapsedMs,
            $peakMb,
            $scanned,
        );
    }

    printf("# seed: %d rows in %.1fs (chunk size %d)\n", $rows, $seedSeconds, TransactionScan::CHUNK_SIZE);
} finally {
    @unlink($database);
}
