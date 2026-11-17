<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Asserts the test environment really runs on the expected stack and
 * reports it — the release-gating PostgreSQL job of issue #84 must fail
 * loudly instead of silently testing SQLite while its credentials point
 * elsewhere. Only driver, server version and PHP version are printed: no
 * host, port, user, password or database name.
 *
 * Usage (environment selects the expectations):
 *
 *   EXPECTED_DB_DRIVER=pgsql EXPECTED_DB_SERVER=10. EXPECTED_PHP=8.4 \
 *     php scripts/ci/assert-test-runtime.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$driver = $connection->getDriverName();
$server = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

printf("database driver: %s\n", $driver);
printf("database server: %s\n", $server);
printf("php runtime: %s\n", PHP_VERSION);

$failures = [];

$expectedDriver = getenv('EXPECTED_DB_DRIVER') ?: '';
if ($expectedDriver !== '' && $driver !== $expectedDriver) {
    $failures[] = "expected the {$expectedDriver} driver, got {$driver}.";
}

$expectedServer = getenv('EXPECTED_DB_SERVER') ?: '';
if ($expectedServer !== '' && ! str_starts_with($server, $expectedServer)) {
    $failures[] = "expected a {$expectedServer}x database server, got {$server}.";
}

$expectedPhp = getenv('EXPECTED_PHP') ?: '';
if ($expectedPhp !== '' && ! str_starts_with(PHP_VERSION, $expectedPhp.'.')) {
    $failures[] = "expected PHP {$expectedPhp}.x, got ".PHP_VERSION.'.';
}

if ($failures !== []) {
    fwrite(STDERR, "The test runtime does not match the expected stack:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }

    exit(1);
}
