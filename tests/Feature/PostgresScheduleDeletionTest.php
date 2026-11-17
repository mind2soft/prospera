<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Schedules\ScheduleDeletion;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;
use Throwable;

/**
 * Two-connection proofs of the schedule-deletion lock protocol
 * (docs/financial-locking.md): ledger roots before the envelope, and a
 * rediscovery when a concurrent reconciliation committed rows in a ledger
 * outside the first attempt's lock set. The release-gating PostgreSQL job
 * executes these; they skip when PostgreSQL is unavailable.
 */
final class PostgresScheduleDeletionTest extends TestCase
{
    private static ?string $skip = null;

    private static string $database = 'prospera_barrier_testing';

    private static int $sequence = 0;

    private ?string $schema = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (! extension_loaded('pdo_pgsql')) {
            self::$skip = 'The pdo_pgsql extension is not loaded.';

            return;
        }
        self::$database = getenv('PGDATABASE') ?: self::$database;
        // Only a private, generated schema in a disposable test database.
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*_(?:test|testing)\z/', self::$database)) {
            self::$skip = 'PGDATABASE must name an explicitly disposable *_test or *_testing database.';

            return;
        }

        try {
            $admin = new PDO(self::dsn('postgres'), getenv('PGUSER') ?: get_current_user(), getenv('PGPASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $check = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $check->execute([self::$database]);
            if (! $check->fetchColumn()) {
                $admin->exec('CREATE DATABASE "'.self::$database.'"');
            }
        } catch (Throwable) {
            self::$skip = 'PostgreSQL is unavailable for the schedule-deletion barrier tests.';
        }
    }

    private static function dsn(string $database): string
    {
        return 'pgsql:host='.(getenv('PGHOST') ?: '127.0.0.1').';port='.(getenv('PGPORT') ?: '5432').';dbname='.$database;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$skip !== null) {
            $this->markTestSkipped(self::$skip);
        }

        $this->schema = 'prospera_schedule_deletion_'.getmypid().'_'.++self::$sequence;
        config(['database.connections.schedule_deletion_testing' => [
            'driver' => 'pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: '5432',
            'database' => self::$database, 'username' => getenv('PGUSER') ?: get_current_user(),
            'password' => getenv('PGPASSWORD') ?: '', 'charset' => 'utf8', 'prefix' => '',
            'search_path' => $this->schema,
        ], 'database.default' => 'schedule_deletion_testing']);
        DB::purge('schedule_deletion_testing');
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        // Metadata-only fixture: deletion never reads an encrypted value.
        foreach ([
            'CREATE TABLE envelopes (id bigint PRIMARY KEY, user_id bigint, name text, type text)',
            'CREATE TABLE ledgers (id bigint PRIMARY KEY, profile_id bigint, closed_at timestamp NULL)',
            'CREATE TABLE envelope_schedules (id bigint PRIMARY KEY, envelope_id bigint REFERENCES envelopes(id), created_at timestamp NULL, updated_at timestamp NULL, deleted_at timestamp NULL)',
            'CREATE TABLE transactions (id bigint PRIMARY KEY, ledger_id bigint REFERENCES ledgers(id), envelope_id bigint REFERENCES envelopes(id), schedule_id bigint REFERENCES envelope_schedules(id), deleted_at timestamp NULL)',
            "INSERT INTO envelopes VALUES (100, 1, 'Rent', 'expense')",
            'INSERT INTO ledgers VALUES (1000, 10, NULL), (2000, 10, NULL)',
            'INSERT INTO envelope_schedules (id, envelope_id) VALUES (500, 100)',
            'INSERT INTO transactions VALUES (10000, 1000, 100, 500, NULL)',
        ] as $sql) {
            DB::statement($sql);
        }
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            DB::purge('schedule_deletion_testing');
        }
        parent::tearDown();
    }

    private function otherConnection(): PDO
    {
        $pdo = new PDO(self::dsn(self::$database), getenv('PGUSER') ?: get_current_user(), getenv('PGPASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET search_path TO "'.$this->schema.'"');
        $pdo->exec("SET lock_timeout = '150ms'");

        return $pdo;
    }

    private function deleteSchedule(): void
    {
        app(ScheduleDeletion::class)->delete(100, 500, fn (): bool => true);
    }

    public function test_deletion_waits_on_ledger_roots_before_locking_the_envelope(): void
    {
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->exec('SELECT id FROM ledgers WHERE id = 1000 FOR UPDATE');
        $released = false;
        DB::connection()->beforeExecuting(function (string $query) use ($other, &$released): void {
            if (! $released && str_contains($query, 'from "ledgers"') && str_contains($query, 'for update')) {
                // A reconciler holding the ledger may still take its envelope:
                // deletion owns nothing beneath the ledger while it waits.
                $this->assertSame(100, (int) $other->query('SELECT id FROM envelopes WHERE id = 100 FOR SHARE NOWAIT')->fetchColumn());
                $this->assertSame(500, (int) $other->query('SELECT id FROM envelope_schedules WHERE id = 500 FOR UPDATE NOWAIT')->fetchColumn());
                $other->commit();
                $released = true;
            }
        });
        try {
            $this->deleteSchedule();
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
        $this->assertTrue($released);
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertNotNull(DB::table('envelope_schedules')->where('id', 500)->value('deleted_at'));
    }

    public function test_rows_committed_in_an_unlocked_ledger_force_a_fresh_attempt(): void
    {
        $other = $this->otherConnection();
        $injected = 0;
        DB::connection()->beforeExecuting(function (string $query) use ($other, &$injected): void {
            if ($injected === 0 && str_starts_with($query, 'select * from "envelopes"') && str_contains($query, 'for update')) {
                $injected++;
                // A reconciliation of ledger 2000 committed before the
                // envelope lock: it survives this attempt's rollback.
                $other->exec('INSERT INTO transactions VALUES (20000, 2000, 100, 500, NULL)');
            }
        });

        $this->deleteSchedule();

        $this->assertSame(1, $injected);
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertNotNull(DB::table('envelope_schedules')->where('id', 500)->value('deleted_at'));
    }

    public function test_closed_ledgers_keep_generated_rows_and_their_provenance(): void
    {
        DB::table('ledgers')->where('id', 2000)->update(['closed_at' => '2026-06-30 00:00:00']);
        DB::table('transactions')->insert(['id' => 20000, 'ledger_id' => 2000, 'envelope_id' => 100, 'schedule_id' => 500]);

        $this->deleteSchedule();

        $this->assertSame([20000], DB::table('transactions')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $this->assertNotNull(DB::table('envelope_schedules')->where('id', 500)->value('deleted_at'));
    }
}
