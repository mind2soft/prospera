<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Back the database up to one file — run by the deploy right before pending
 * migrations (production migrations are forward-only, docs/encryption.md).
 * PostgreSQL is dumped with `pg_dump --format=custom` (restore with
 * `pg_restore`), SQLite copied with `VACUUM INTO`. The file is owner-only:
 * it holds every row, amount keys included (still wrapped by APP_KEY).
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {path : The file to write (must not exist)} {--database= : The connection to back up}';

    protected $description = 'Back the database up to a file (pg_dump for PostgreSQL, VACUUM INTO for SQLite)';

    /** Seconds a dump may take before it is considered hung. */
    private const TIMEOUT = 3600;

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (file_exists($path)) {
            $this->error("Refusing to overwrite {$path}.");

            return self::FAILURE;
        }

        if (! is_dir(dirname($path))) {
            $this->error('The backup directory does not exist: '.dirname($path));

            return self::FAILURE;
        }

        $connection = DB::connection($this->option('database') ?: null);
        $driver = $connection->getDriverName();
        $umask = umask(0077);

        try {
            match ($driver) {
                'pgsql' => $this->dumpPostgres($connection->getConfig(), $path),
                'sqlite' => $connection->statement('VACUUM INTO ?', [$path]),
                default => throw new \RuntimeException("Backups of the [{$driver}] driver are not supported."),
            };

            clearstatcache(true, $path);

            if (! is_file($path) || filesize($path) === 0) {
                throw new \RuntimeException('The backup file is missing or empty.');
            }

            chmod($path, 0600);
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }

            $this->error('Database backup failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            umask($umask);
        }

        $this->info("Database backed up to {$path}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dumpPostgres(array $config, string $path): void
    {
        $environment = ['PGPASSWORD' => (string) ($config['password'] ?? '')];

        if (filled($config['sslmode'] ?? null)) {
            $environment['PGSSLMODE'] = (string) $config['sslmode'];
        }

        $result = Process::env($environment)
            ->timeout(self::TIMEOUT)
            ->run([
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                '--port='.(string) ($config['port'] ?? '5432'),
                '--username='.(string) ($config['username'] ?? ''),
                '--file='.$path,
                '--dbname='.(string) ($config['database'] ?? ''),
            ]);

        if ($result->failed()) {
            throw new \RuntimeException('pg_dump exited with '.$result->exitCode().': '.trim($result->errorOutput()));
        }
    }
}
