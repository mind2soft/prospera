<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * The PostgreSQL concurrent-close barrier tests of issue #80: close/reopen
 * and transaction writes must serialize on the ledger's row lock.
 *
 * SQLite cannot take or block row locks (`FOR UPDATE` is a no-op there), so
 * these tests prove the protocol on real PostgreSQL with two independent
 * connections. They are skipped when PostgreSQL is unavailable — the test
 * environment that runs them is #84's release-gating setup.
 *
 * The connections mirror the two protocol sides with raw SQL (the code
 * under test issues the same statements):
 *
 * - writer: `SELECT … FOR UPDATE` on the ledger row, then the read-only
 *   recheck, then the envelope/transaction locks (issue #80's lock order).
 * - closer: `SELECT … FOR UPDATE` on the same row, then `closed_at` is
 *   set and committed.
 */
class PostgresLedgerSerializationTest extends TestCase
{
    private static ?string $skip = null;

    private static ?\PDO $admin = null;

    /**
     * The barrier tests own this database: it must never be the database of
     * another test environment — set PGDATABASE to point them elsewhere.
     */
    private static string $database = 'prospera_barrier_testing';

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
                ' — the concurrent-close barrier needs it (issue #84 provides the environment): '.
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

        // A clean schema per test: the barrier tests only need the ledger
        // table and its primary key sequence.
        $connection->statement('DROP TABLE IF EXISTS ledgers CASCADE');
        $connection->statement(<<<'SQL'
            CREATE TABLE ledgers (
                id bigserial PRIMARY KEY,
                profile_id bigint NOT NULL,
                name varchar(255) NOT NULL,
                closed_at timestamp(0) NULL,
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL
            )
        SQL);
    }

    private function seedLedger(): int
    {
        $connection = DB::connection('pgsql_testing');

        $connection->insert(
            'INSERT INTO ledgers (profile_id, name, created_at, updated_at) VALUES (?, ?, now(), now())',
            [1, '2026'],
        );

        return (int) $connection->selectOne('SELECT id FROM ledgers')->id;
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

    private function lockLedgerForUpdate(\PDO $connection, int $ledgerId): void
    {
        $connection->beginTransaction();
        $statement = $connection->prepare('SELECT id FROM ledgers WHERE id = ? FOR UPDATE');
        $statement->execute([$ledgerId]);
    }

    private function closeLedger(\PDO $connection, int $ledgerId): void
    {
        $statement = $connection->prepare('UPDATE ledgers SET closed_at = now() WHERE id = ?');
        $statement->execute([$ledgerId]);
        $connection->commit();
    }

    /** The lock is taken: the second writer blocks, so writes serialize. */
    public function test_a_write_blocks_a_concurrent_lock_taker_until_it_commits(): void
    {
        $ledgerId = $this->seedLedger();

        // An in-flight write holds the ledger's serialization lock.
        $writer = $this->connection();
        $this->lockLedgerForUpdate($writer, $ledgerId);

        // A concurrent close waits: it cannot even read the row for update.
        // The failed lock aborts the closer's transaction — roll it back
        // before reusing the connection.
        $closer = $this->connection();
        try {
            $this->lockLedgerForUpdate($closer, $ledgerId);
            $this->fail('The close acquired the ledger lock while a write held it.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1); // blocked by the writer's lock
            $closer->rollBack();
        }

        // Once the write commits, the close proceeds on the same lock.
        $writer->commit();

        $this->lockLedgerForUpdate($closer, $ledgerId);
        $this->closeLedger($closer, $ledgerId);

        $this->assertNotNull(
            DB::connection('pgsql_testing')
                ->selectOne('SELECT closed_at FROM ledgers WHERE id = ?', [$ledgerId])->closed_at,
        );
    }

    /**
     * A close committed first is visible to the writer's locked re-read:
     * the writer sees closed_at and must reject the documented read-only
     * validation error instead of writing.
     */
    public function test_a_write_sees_a_close_committed_first(): void
    {
        $ledgerId = $this->seedLedger();

        $closer = $this->connection();
        $this->lockLedgerForUpdate($closer, $ledgerId);
        $this->closeLedger($closer, $ledgerId);

        // The writer's locked read (the controller's re-read under the
        // serialization lock) observes the committed close.
        $writer = $this->connection();
        $this->lockLedgerForUpdate($writer, $ledgerId);

        $closedAt = $writer->prepare('SELECT closed_at FROM ledgers WHERE id = ?');
        $closedAt->execute([$ledgerId]);
        $this->assertNotNull($closedAt->fetchColumn(), 'The locked re-read must see the committed close.');

        $writer->rollBack();
    }

    /**
     * The reverse interleaving of the first test: a close that holds the
     * lock commits before the writer's lock is granted, so the write cannot
     * interleave with the close and then outlive it.
     */
    public function test_a_close_blocks_a_concurrent_write_until_it_commits(): void
    {
        $ledgerId = $this->seedLedger();

        $closer = $this->connection();
        $this->lockLedgerForUpdate($closer, $ledgerId);

        $writer = $this->connection();
        try {
            $this->lockLedgerForUpdate($writer, $ledgerId);
            $this->fail('The writer acquired the ledger lock while the close held it.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1); // blocked by the close's lock
            $writer->rollBack();
        }

        $this->closeLedger($closer, $ledgerId);

        // With the close committed, the writer's locked re-read rejects.
        $this->lockLedgerForUpdate($writer, $ledgerId);
        $closedAt = $writer->prepare('SELECT closed_at FROM ledgers WHERE id = ?');
        $closedAt->execute([$ledgerId]);
        $this->assertNotNull($closedAt->fetchColumn());

        $writer->rollBack();
    }
}
