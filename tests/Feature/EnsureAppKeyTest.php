<?php

namespace Tests\Feature;

use App\Console\Commands\EnsureAppKey;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountKeyRing;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Setup-time APP_KEY guard (issue #77): rerunning `composer setup` must
 * preserve an existing key byte-for-byte, generate one only for a confirmed
 * fresh installation, and fail closed — with no writes — on anything
 * uncertain. Everything runs against a disposable environment: a temporary
 * env file and temporary sqlite databases, never the developer's `.env`.
 */
class EnsureAppKeyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $envFile;

    private string $database;

    private string $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = (string) config('database.default');

        $this->dir = sys_get_temp_dir().'/ensure-app-key-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700, true);
        $this->envFile = $this->dir.'/.env';
        $this->database = $this->dir.'/database.sqlite';

        // Route the command at the disposable env file.
        $test = $this;
        $this->app->bind(EnsureAppKey::class, fn (): EnsureAppKey => new class($test->envFile) extends EnsureAppKey
        {
            public function __construct(private readonly string $path)
            {
                parent::__construct();
            }

            public function envFilePath(): string
            {
                return $this->path;
            }
        });

        // The disposable env file is only reached through the container
        // binding above: fail loudly if a refactor ever bypasses it.
        $this->assertSame($this->envFile, $this->app->make(EnsureAppKey::class)->envFilePath());

        // The `setup_tmp` sqlite connection is registered lazily by the
        // tests that opt into it (useTempDatabase/useUnreadableDatabase):
        // a configured-but-unconnected database would be resolved by the
        // RefreshDatabase teardown rollback and blow up when its file does
        // not exist yet.
    }

    protected function tearDown(): void
    {
        // Restore the test database before RefreshDatabase's rollback runs:
        // it resolves `database.default` at this point, and pointing it at
        // the disposable setup_tmp connection would connect (or fail to)
        // there instead of rolling back the migrated in-memory database.
        config(['database.default' => $this->defaultConnection]);
        config(['database.connections.setup_tmp' => null]);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            unlink((string) $file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * Point the command's inspection at the disposable sqlite database.
     */
    private function useTempDatabase(): void
    {
        config(['database.connections.setup_tmp' => ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]]);
        config(['database.default' => 'setup_tmp']);
    }

    /**
     * Point the command's inspection at a sqlite file that cannot be read —
     * any access throws — proving the command exited before touching the DB.
     */
    private function useUnreadableDatabase(): void
    {
        file_put_contents($this->dir.'/corrupt.sqlite', random_bytes(64));
        config(['database.connections.setup_tmp' => ['driver' => 'sqlite', 'database' => $this->dir.'/corrupt.sqlite', 'prefix' => '']]);
        config(['database.default' => 'setup_tmp']);
    }

    /**
     * @return array<string, string>
     */
    private function envFileContents(): array
    {
        $values = [];
        foreach (file($this->envFile) ?: [] as $line) {
            [$name, $value] = array_pad(explode('=', trim((string) $line), 2), 2, '');
            $values[$name] = $value;
        }

        return $values;
    }

    private function assertEnvKeyUsable(): string
    {
        $key = $this->envFileContents()['APP_KEY'] ?? '';
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $key);

        return $key;
    }

    public function test_a_fresh_installation_gets_one_key_and_a_rerun_preserves_it(): void
    {
        file_put_contents($this->envFile, "APP_ENV=local\nAPP_KEY=\n");
        config(['app.key' => null]);
        $this->useTempDatabase();

        $this->artisan('app:ensure-key')->assertSuccessful();
        $first = $this->assertEnvKeyUsable();
        $this->assertSame("APP_ENV=local\nAPP_KEY=".$first."\n", (string) file_get_contents($this->envFile));

        // The command never creates the database: that is `migrate`'s job.
        $this->assertFileDoesNotExist($this->database);

        // A rerun of `composer setup` is a fresh artisan process that loads
        // the .env written above — simulate that by loading the key into
        // config.
        config(['app.key' => $first]);

        $this->artisan('app:ensure-key')->assertSuccessful();
        $this->assertSame($first, $this->assertEnvKeyUsable());
        $this->assertSame("APP_ENV=local\nAPP_KEY=".$first."\n", (string) file_get_contents($this->envFile));
        $this->assertFileDoesNotExist($this->database);
    }

    public function test_an_empty_touched_sqlite_file_still_counts_as_fresh(): void
    {
        touch($this->database);
        file_put_contents($this->envFile, "APP_ENV=local\nAPP_KEY=\n");
        config(['app.key' => null]);
        $this->useTempDatabase();

        $this->artisan('app:ensure-key')->assertSuccessful();
        $this->assertEnvKeyUsable();
        $this->assertSame(0, filesize($this->database));
    }

    public function test_rerunning_setup_preserves_an_existing_key_and_encrypted_fixture(): void
    {
        $user = User::factory()->create();
        $envelope = $user->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e']);
        $profile = FinancialProfile::createOwnedBy($user, 'Mine');
        $ledger = Ledger::create([
            'profile_id' => $profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 100,
        ]);
        $transaction = $ledger->transactions()->create([
            'user_id' => $user->id,
            'envelope_id' => $envelope->id,
            'amount' => -101,
            'event_at' => '2026-06-11 00:00:00',
        ]);

        // The env file holds the effective key, like an existing install.
        $key = (string) config('app.key');
        $this->assertNotSame('', $key);
        file_put_contents($this->envFile, "APP_ENV=local\nAPP_KEY=".$key."\n");
        $before = (string) file_get_contents($this->envFile);

        $raw = fn (): array => [
            (string) DB::table('ledgers')->where('id', $ledger->id)->value('starting_balance'),
            (string) DB::table('transactions')->where('id', $transaction->id)->value('amount'),
        ];
        $payloadsBefore = $raw();

        // A database that would explode on any access: if the command left
        // the success path early (usable key), it is never touched.
        $this->useUnreadableDatabase();

        $this->artisan('app:ensure-key')->assertSuccessful();
        $this->assertSame($before, (string) file_get_contents($this->envFile));

        config(['database.default' => $this->defaultConnection]);
        $this->assertSame($payloadsBefore, $raw());
        app(AmountKeyRing::class)->forget();
        $this->assertSame(100, Ledger::query()->findOrFail($ledger->id)->starting_balance);
        $this->assertSame(-101, Transaction::query()->findOrFail($transaction->id)->amount);
    }

    public function test_an_environment_supplied_key_is_not_written_to_the_env_file(): void
    {
        file_put_contents($this->envFile, "APP_ENV=local\n");
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->useUnreadableDatabase();

        $this->artisan('app:ensure-key')->assertSuccessful();
        $this->assertSame("APP_ENV=local\n", (string) file_get_contents($this->envFile));
        $this->assertArrayNotHasKey('APP_KEY', $this->envFileContents());
    }

    public function test_a_migrated_database_with_a_missing_key_refuses_generation_and_writes_nothing(): void
    {
        $this->assertMigratedDatabaseRefuses('APP_KEY=', null);
    }

    public function test_a_migrated_database_with_an_invalid_key_refuses_generation_and_writes_nothing(): void
    {
        $invalid = 'base64:'.base64_encode(str_repeat('x', 8));
        $this->assertMigratedDatabaseRefuses('APP_KEY='.$invalid, $invalid);
    }

    public function test_an_uninspectable_database_fails_closed(): void
    {
        file_put_contents($this->envFile, "APP_ENV=local\nAPP_KEY=\n");
        config(['app.key' => null]);
        $this->useUnreadableDatabase();
        $envBefore = (string) file_get_contents($this->envFile);

        $this->artisan('app:ensure-key')
            ->expectsOutputToContain('Refusing to generate an APP_KEY')
            ->expectsOutputToContain('fresh installation')
            ->assertFailed();

        $this->assertSame($envBefore, (string) file_get_contents($this->envFile));
    }

    public function test_an_invalid_key_on_a_fresh_install_is_replaced(): void
    {
        file_put_contents($this->envFile, "APP_ENV=local\nAPP_KEY=base64:dG9vc2hvcnQ=\n");
        config(['app.key' => 'base64:dG9vc2hvcnQ=']);
        $this->useTempDatabase();

        $this->artisan('app:ensure-key')->assertSuccessful();
        $this->assertEnvKeyUsable();
        $this->assertStringStartsWith("APP_ENV=local\n", (string) file_get_contents($this->envFile));
        $this->assertFileDoesNotExist($this->database);
    }

    public function test_duplicate_and_export_prefixed_app_key_lines_are_all_replaced(): void
    {
        // phpdotenv resolves duplicates last-wins, so every occurrence must
        // carry the generated key — including export-prefixed variants.
        file_put_contents($this->envFile, "APP_ENV=local\nAPP_KEY=\nexport APP_KEY=\nAPP_KEY=\n");
        config(['app.key' => null]);
        $this->useTempDatabase();

        $this->artisan('app:ensure-key')->assertSuccessful();
        $key = $this->assertEnvKeyUsable();
        $this->assertSame("APP_ENV=local\nAPP_KEY={$key}\nAPP_KEY={$key}\nAPP_KEY={$key}\n", (string) file_get_contents($this->envFile));
    }

    public function test_a_failing_env_write_fails_the_command(): void
    {
        file_put_contents($this->envFile, "APP_ENV=local\nAPP_KEY=\n");
        config(['app.key' => null]);
        $this->useTempDatabase();
        $envBefore = (string) file_get_contents($this->envFile);

        // An unwritable target: the write itself fails, simulating a
        // read-only filesystem or a permissions problem at generation time.
        $this->app->bind(EnsureAppKey::class, fn (): EnsureAppKey => new class($this->envFile) extends EnsureAppKey
        {
            public function __construct(private readonly string $path)
            {
                parent::__construct();
            }

            public function envFilePath(): string
            {
                return $this->path;
            }

            protected function putEnvContents(string $path, string $contents): bool
            {
                unset($path, $contents);

                return false;
            }
        });

        $kernel = $this->app->make(Kernel::class);
        $exit = $kernel->call('app:ensure-key');
        $output = $kernel->output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Failed to write', $output);
        $this->assertStringContainsString('No key was configured', $output);

        $this->assertSame($envBefore, (string) file_get_contents($this->envFile));
    }

    /**
     * The core refusal: a migrated database (the migrations repository
     * exists) without a usable key must fail before any write.
     */
    private function assertMigratedDatabaseRefuses(string $envKeyLine, ?string $configKey): void
    {
        file_put_contents($this->envFile, "APP_ENV=local\n{$envKeyLine}\n");
        config(['app.key' => $configKey]);
        $this->useTempDatabase();
        touch($this->database); // a valid, empty sqlite database
        DB::connection('setup_tmp')->statement('CREATE TABLE migrations (id integer primary key, migration text not null, batch integer not null)');
        DB::connection('setup_tmp')->table('migrations')->insert(['migration' => '0001_01_01_000000_create_users_table', 'batch' => 1]);
        $envBefore = (string) file_get_contents($this->envFile);
        $dbBefore = md5_file($this->database);

        $this->artisan('app:ensure-key')
            ->expectsOutputToContain('Refusing to generate an APP_KEY')
            ->expectsOutputToContain('undecryptable')
            ->assertFailed();

        $this->assertSame($envBefore, (string) file_get_contents($this->envFile));
        $this->assertSame($dbBefore, md5_file($this->database));
    }
}
