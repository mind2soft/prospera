<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * The dashboard layout revision barrier tests of issue #55: two concurrent
 * first saves of the same (user, profile) layout must serialize on the
 * `dashboard_layouts` revision row, and the losing writer's transaction
 * must survive its own statement-level insert conflict so the revision
 * check under the lock can decide who wins.
 *
 * SQLite cannot take row locks (`FOR UPDATE` is a no-op there) and its
 * unique-violation handling does not reproduce PostgreSQL's aborted
 * transaction (25P02) — these tests prove the protocol on real PostgreSQL
 * with two independent connections. They are skipped when PostgreSQL is
 * unavailable — the test environment that runs them is #84's
 * release-gating setup (mirrors PostgresLedgerSerializationTest).
 */
class PostgresDashboardLayoutRaceTest extends TestCase
{
    private static ?string $skip = null;

    private static ?\PDO $admin = null;

    /**
     * The barrier tests own this database: it must never be the database of
     * another test environment — set PGDATABASE to point them elsewhere.
     */
    private static string $database = 'prospera_dashboard_testing';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! extension_loaded('pdo_pgsql')) {
            self::$skip = 'The pdo_pgsql extension is not loaded.';

            return;
        }

        $host = getenv('PGHOST') ?: '127.0.0.1';
        $port = getenv('PGPORT') ?: '5432';
        $user = getenv('PGUSER') ?: get_current_user();
        $password = getenv('PGPASSWORD') ?: '';
        self::$database = getenv('PGDATABASE') ?: self::$database;

        try {
            // Connect through the maintenance database to make sure the
            // testing database exists.
            self::$admin = new \PDO(
                "pgsql:host=$host;port=$port;dbname=postgres", $user, $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            $databaseCheck = self::$admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $databaseCheck->execute([self::$database]);
            $exists = $databaseCheck->fetchColumn();

            if (! $exists) {
                self::$admin->exec('CREATE DATABASE "'.self::$database.'"');
            }
        } catch (Throwable $error) {
            self::$admin = null;
            self::$skip = 'PostgreSQL is not reachable at '.$host.':'.$port.
                ' — the concurrent-first-save barrier needs it (issue #84 provides the environment): '.
                $error->getMessage();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skip !== null) {
            $this->markTestSkipped(self::$skip);
        }

        config(['database.connections.pgsql_testing' => [
            'driver' => 'pgsql',
            'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: '5432',
            'database' => self::$database,
            'username' => getenv('PGUSER') ?: get_current_user(),
            'password' => getenv('PGPASSWORD') ?: '',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ]]);

        $connection = DB::connection('pgsql_testing');

        // A clean schema per test: the barrier tests only need the layout
        // revision table exactly as the migration creates it.
        $connection->statement('DROP TABLE IF EXISTS dashboard_layouts CASCADE');
        $connection->statement(<<<'SQL'
            CREATE TABLE dashboard_layouts (
                id bigserial PRIMARY KEY,
                user_id bigint NOT NULL,
                financial_profile_id bigint NOT NULL,
                revision integer NOT NULL DEFAULT 1,
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL,
                CONSTRAINT dashboard_layouts_user_id_financial_profile_id_unique
                    UNIQUE (user_id, financial_profile_id)
            )
        SQL);
    }

    /**
     * A connection bound to a short lock timeout, for the side expected to
     * block.
     */
    private function connection(): \PDO
    {
        $config = config('database.connections.pgsql_testing');

        $pdo = new \PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        // Bounded waits instead of sleep/retry loops: a blocked lock fails
        // fast and deterministically.
        $pdo->exec("SET lock_timeout = '200ms'");

        return $pdo;
    }

    /**
     * The statement-level first-write creation the controller issues
     * (lockLayout's insertOrIgnore).
     */
    private function insertLayoutIgnoringConflict(\PDO $connection, int $userId, int $profileId): void
    {
        $statement = $connection->prepare(<<<'SQL'
            INSERT INTO dashboard_layouts
                (user_id, financial_profile_id, revision, created_at, updated_at)
            VALUES (?, ?, 1, now(), now())
            ON CONFLICT (user_id, financial_profile_id) DO NOTHING
        SQL);
        $statement->execute([$userId, $profileId]);
    }

    /**
     * The controller's locked revision read (lockLayout's final step).
     */
    private function lockLayoutRevision(\PDO $connection, int $userId, int $profileId): int
    {
        $statement = $connection->prepare(<<<'SQL'
            SELECT revision FROM dashboard_layouts
            WHERE user_id = ? AND financial_profile_id = ?
            FOR UPDATE
        SQL);
        $statement->execute([$userId, $profileId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * The loser of a concurrent first write must survive its own insert: a
     * plain INSERT that hits the unique constraint aborts the whole
     * transaction on PostgreSQL (25P02), so the locked revision read — the
     * step that arbitrates who wins — would fail with a 500 instead of a
     * clean 409 (issue #55). The conflict-free insert keeps the
     * transaction usable.
     */
    public function test_a_losing_first_write_can_still_lock_and_read_the_revision(): void
    {
        // The winner's first write is committed: the revision row exists at
        // its initial revision.
        DB::connection('pgsql_testing')->table('dashboard_layouts')->insert([
            'user_id' => 7,
            'financial_profile_id' => 3,
            'revision' => 1,
        ]);

        // The loser's transaction: the statement-level no-op insert, then
        // the locked revision read — both in one usable transaction.
        $loser = $this->connection();
        $loser->beginTransaction();

        $this->insertLayoutIgnoringConflict($loser, 7, 3);

        $this->assertSame(1, $this->lockLayoutRevision($loser, 7, 3));

        $loser->rollBack();
    }

    public function test_a_plain_insert_losing_the_race_would_abort_the_transaction(): void
    {
        // Documents why the insert must be conflict-free: without ON
        // CONFLICT, the losing first write aborts its transaction and the
        // locked revision read can never arbitrate (issue #55).
        DB::connection('pgsql_testing')->table('dashboard_layouts')->insert([
            'user_id' => 7,
            'financial_profile_id' => 3,
            'revision' => 1,
        ]);

        $loser = $this->connection();
        $loser->beginTransaction();

        try {
            $plain = $loser->prepare(
                'INSERT INTO dashboard_layouts (user_id, financial_profile_id, revision, created_at, updated_at) VALUES (?, ?, 1, now(), now())',
            );
            $plain->execute([7, 3]);
            $this->fail('A plain insert into a taken layout row should conflict.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1); // unique constraint violated
        }

        // The transaction is now aborted on the PostgreSQL connection: even
        // the locking read is refused until a rollback.
        try {
            $this->lockLayoutRevision($loser, 7, 3);
            $this->fail('The aborted transaction should refuse the locked read.');
        } catch (\PDOException $error) {
            $this->assertStringContainsStringIgnoringCase(
                'aborted',
                $error->getMessage(),
            );
        }

        $loser->rollBack();
    }
}
