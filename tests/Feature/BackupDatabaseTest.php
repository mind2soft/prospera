<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\TestCase;

/**
 * `db:backup` — the deploy's pre-migration backup (docs/encryption.md,
 * "Deployments").
 */
class BackupDatabaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/backups-'.getmypid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function usePostgres(): void
    {
        config()->set('database.connections.backup_pg', [
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => '5433',
            'database' => 'prospera',
            'username' => 'app',
            'password' => 's3cret',
            'sslmode' => 'require',
        ]);
    }

    public function test_sqlite_databases_are_copied_to_an_owner_only_file(): void
    {
        // A connection of its own: VACUUM cannot run inside the test's
        // transaction (the deploy runs it outside any).
        $source = $this->dir.'/source.sqlite';
        $pdo = new PDO('sqlite:'.$source);
        $pdo->exec("create table users (email text); insert into users values ('backup@example.com')");
        config()->set('database.connections.backup_sqlite', ['driver' => 'sqlite', 'database' => $source, 'prefix' => '']);
        $path = $this->dir.'/backup.dump';

        $this->artisan('db:backup', ['path' => $path, '--database' => 'backup_sqlite'])->assertSuccessful();

        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $copy = new PDO('sqlite:'.$path);
        $this->assertSame('backup@example.com', $copy->query('select email from users')->fetchColumn());
    }

    public function test_postgres_databases_are_dumped_with_pg_dump_and_the_password_kept_off_the_command_line(): void
    {
        $this->usePostgres();
        $path = $this->dir.'/backup.dump';

        Process::fake(function (PendingProcess $process) use ($path) {
            file_put_contents($path, 'PGDMP');

            return Process::result();
        });

        $this->artisan('db:backup', ['path' => $path, '--database' => 'backup_pg'])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--host=db.internal',
            '--port=5433',
            '--username=app',
            '--file='.$path,
            '--dbname=prospera',
        ]
            && $process->environment === ['PGPASSWORD' => 's3cret', 'PGSSLMODE' => 'require']);

        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function test_a_failing_pg_dump_fails_and_leaves_no_partial_file(): void
    {
        $this->usePostgres();
        $path = $this->dir.'/backup.dump';

        Process::fake(function () use ($path) {
            file_put_contents($path, 'PGD');

            return Process::result(errorOutput: 'connection refused', exitCode: 1);
        });

        $this->artisan('db:backup', ['path' => $path, '--database' => 'backup_pg'])
            ->expectsOutputToContain('connection refused')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);
    }

    public function test_an_empty_dump_fails(): void
    {
        $this->usePostgres();
        $path = $this->dir.'/backup.dump';

        Process::fake(function () use ($path) {
            touch($path);

            return Process::result();
        });

        $this->artisan('db:backup', ['path' => $path, '--database' => 'backup_pg'])->assertFailed();

        $this->assertFileDoesNotExist($path);
    }

    public function test_an_existing_file_is_never_overwritten(): void
    {
        $path = $this->dir.'/backup.dump';
        file_put_contents($path, 'previous backup');

        $this->artisan('db:backup', ['path' => $path])->assertFailed();

        $this->assertSame('previous backup', file_get_contents($path));
    }

    public function test_a_missing_directory_fails(): void
    {
        $this->artisan('db:backup', ['path' => $this->dir.'/missing/backup.dump'])->assertFailed();
    }

    public function test_unsupported_drivers_fail(): void
    {
        config()->set('database.connections.backup_my', ['driver' => 'mysql', 'database' => 'x']);

        $this->artisan('db:backup', ['path' => $this->dir.'/backup.dump', '--database' => 'backup_my'])
            ->expectsOutputToContain('not supported')
            ->assertFailed();
    }
}
