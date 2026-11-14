<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Setup-time APP_KEY guard (issue #77): `composer setup` runs this instead of
 * a bare `key:generate`, so rerunning setup can never silently rotate the key
 * that wraps every encrypted amount (docs/encryption.md).
 *
 * A key is generated only for a confirmed fresh installation, decided by
 * read-only inspection. Anything uncertain — an existing migrated database,
 * an unreachable or uninspectable database — fails closed, before migrations
 * run, with recovery instructions. The key itself is never echoed.
 */
class EnsureAppKey extends Command
{
    /**
     * Length (in bytes) of the raw key the AES-256-CBC cipher expects
     * (config/app.php).
     */
    private const KEY_BYTES = 32;

    protected $signature = 'app:ensure-key';

    protected $description = 'Ensure a usable APP_KEY exists, generating one only for a confirmed fresh installation (issue #77)';

    public function handle(): int
    {
        if ($this->isUsable((string) config('app.key'))) {
            return self::SUCCESS;
        }

        $fresh = $this->databaseIsFresh();

        if ($fresh === null) {
            return self::FAILURE;
        }

        if (! $fresh) {
            $this->error('Refusing to generate an APP_KEY: no usable APP_KEY is configured, but the configured database is already migrated. No files were changed.');
            $this->line('A replacement key would make every encrypted amount permanently undecryptable. Restore the original APP_KEY from your backup, or, if the rotation was intentional, list the old key in APP_PREVIOUS_KEYS and run `php artisan amounts:rewrap-keys` (docs/encryption.md), then rerun `composer setup`.');

            return self::FAILURE;
        }

        if (! $this->writeEnvKey('base64:'.base64_encode(random_bytes(self::KEY_BYTES)))) {
            return self::FAILURE;
        }

        $this->info('Fresh installation: generated a new APP_KEY in '.$this->envFilePath().'.');

        return self::SUCCESS;
    }

    /**
     * The env file the generated key is written to. Injectable so tests can
     * run against a disposable environment instead of the developer's `.env`.
     */
    public function envFilePath(): string
    {
        return base_path('.env');
    }

    /**
     * A key is usable when the encrypter (AES-256-CBC) accepts it: a raw
     * 32-byte string, or a `base64:` string decoding to exactly 32 bytes.
     */
    private function isUsable(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false && strlen($decoded) === self::KEY_BYTES;
        }

        return strlen($key) === self::KEY_BYTES;
    }

    /**
     * Read-only freshness inspection: true = confirmed fresh (no migrations
     * repository), false = existing migrated database, null = could not tell
     * (the caller fails closed).
     *
     * An existing SQLite file alone proves nothing (an empty, touched
     * `database/database.sqlite` is still a fresh install — only the
     * migrations repository counts as migrated data), and an unreachable
     * database proves nothing either way. Connecting is deferred until after
     * the sqlite file check so a missing file is never created here; that is
     * `artisan migrate`'s job.
     */
    private function databaseIsFresh(): ?bool
    {
        $connection = config('database.connections.'.config('database.default'));

        if (is_array($connection) && ($connection['driver'] ?? null) === 'sqlite') {
            $database = $connection['database'] ?? null;

            if (is_string($database) && $database !== ':memory:') {
                // Resolve like the connector does (PDO sqlite resolves
                // relative paths against the process cwd), falling back to
                // the project root so running artisan from another directory
                // cannot reclassify an existing database as fresh.
                if (! is_file(is_file($database) ? $database : base_path($database))) {
                    return true;
                }
            }
        }

        try {
            // Resolved through the connection manager at call time — not the
            // `Schema` facade, whose singleton pins whatever connection
            // resolved it first (e.g. the test runner's in-memory database).
            return ! DB::connection()->getSchemaBuilder()->hasTable((string) config('database.migrations.table', 'migrations'));
        } catch (QueryException|PDOException $exception) {
            $this->error('Refusing to generate an APP_KEY: the configured database could not be inspected ('.$exception->getMessage().').');
            $this->line('No files were changed. An unreadable database is not proof of a fresh installation — check the DB_* settings in .env and rerun `composer setup`.');

            return null;
        }
    }

    /**
     * Write the `APP_KEY=` line into the env file, byte-preserving every
     * other line. The key value is never logged.
     *
     * Every APP_KEY occurrence is replaced (export/indented variants
     * included): phpdotenv resolves duplicate definitions last-wins, so a
     * stale empty line after the new one would silently win.
     */
    private function writeEnvKey(string $key): bool
    {
        $path = $this->envFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if (preg_match('/^\s*(?:export\s+)?APP_KEY=/m', $contents) === 1) {
            $updated = (string) preg_replace('/^\s*(?:export\s+)?APP_KEY=.*$/m', 'APP_KEY='.$key, $contents);
        } else {
            $updated = $contents === '' ? '' : $contents.(str_ends_with($contents, "\n") ? '' : "\n");

            $updated .= 'APP_KEY='.$key."\n";
        }

        if (! $this->putEnvContents($path, $updated)) {
            $this->error("Failed to write the generated APP_KEY to {$path} — check the file's permissions. No key was configured.");

            return false;
        }

        return true;
    }

    /**
     * Persist the updated env file contents. Injectable so tests can
     * exercise the write-failure branch (read-only filesystem, permissions)
     * deterministically.
     */
    protected function putEnvContents(string $path, string $contents): bool
    {
        return file_put_contents($path, $contents) !== false;
    }
}
