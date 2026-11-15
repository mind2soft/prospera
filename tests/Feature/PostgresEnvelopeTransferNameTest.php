<?php

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * The PostgreSQL envelope-transfer tests of issue #81: a transferred
 * envelope's collision-resolved name must fit the schema's varchar(60)
 * `name` column, and a concurrent claim of the chosen name must be retried
 * through the unique-violation path.
 *
 * SQLite does not enforce varchar lengths, so the very failure mode of
 * #81 — PostgreSQL rejecting an over-long name and rolling back the
 * enclosing transfer, member removal or account deletion — cannot be
 * reproduced there. These tests prove the resolution against a real
 * varchar(60) column and exercise the PostgreSQL unique-violation
 * (SQLSTATE 23505) branch of the retry. They are skipped when PostgreSQL
 * is unavailable — the test environment that runs them is #84's
 * release-gating setup.
 */
class PostgresEnvelopeTransferNameTest extends TestCase
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
                ' — the barrier tests need it (issue #84 provides the environment): '.
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

        DB::purge('pgsql_testing');
        config(['database.default' => 'pgsql_testing']);

        $connection = DB::connection('pgsql_testing');

        // A clean schema per test: the transfer path only touches users,
        // envelopes (varchar(60) name, unique per owner) and the share rows.
        $connection->statement('DROP TABLE IF EXISTS envelope_permissions CASCADE');
        $connection->statement('DROP TABLE IF EXISTS envelopes CASCADE');
        $connection->statement('DROP TABLE IF EXISTS users CASCADE');
        $connection->statement(<<<'SQL'
            CREATE TABLE users (
                id bigserial PRIMARY KEY,
                name varchar(255) NOT NULL,
                email varchar(255) NOT NULL UNIQUE,
                email_verified_at timestamp(0) NULL,
                password varchar(255) NOT NULL,
                remember_token varchar(100) NULL,
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL
            )
        SQL);
        $connection->statement(<<<'SQL'
            CREATE TABLE envelopes (
                id bigserial PRIMARY KEY,
                user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                name varchar(60) NOT NULL,
                type varchar(10) NOT NULL,
                color varchar(7) NOT NULL DEFAULT '#6366f1',
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL,
                UNIQUE (user_id, name)
            )
        SQL);
        $connection->statement(<<<'SQL'
            CREATE TABLE envelope_permissions (
                id bigserial PRIMARY KEY,
                envelope_id bigint NOT NULL REFERENCES envelopes(id) ON DELETE CASCADE,
                user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                permission varchar(20) NOT NULL,
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL,
                UNIQUE (envelope_id, user_id)
            )
        SQL);
    }

    private function seedUser(string $name, string $email): User
    {
        $connection = DB::connection('pgsql_testing');

        $connection->insert(
            'INSERT INTO users (name, email, password, created_at, updated_at) VALUES (?, ?, ?, now(), now())',
            [$name, $email, 'password'],
        );

        return User::query()->where('email', $email)->sole();
    }

    /** A 60-character name must survive a collision on real PostgreSQL. */
    public function test_a_max_length_collision_stays_within_the_name_limit_on_explicit_transfer(): void
    {
        $name = str_repeat('A', 60);
        $owner = $this->seedUser('Owner', 'owner@example.com');
        $target = $this->seedUser('Target', 'target@example.com');

        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $owner->id]);
        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $target->id]);

        $envelope = Envelope::query()->where('user_id', $owner->id)->sole();

        // On PostgreSQL the save itself rejects a > 60-character name:
        // succeeding here proves the resolution stayed within the limit.
        $envelope->transferOwnershipTo($target);
        $envelope->refresh();

        $this->assertSame($target->id, $envelope->user_id);
        $this->assertSame(60, mb_strlen($envelope->name));
        $this->assertNotSame($name, $envelope->name);
    }

    /** The same guarantee for a multibyte name truncation. */
    public function test_a_multibyte_max_length_collision_stays_within_the_name_limit(): void
    {
        $name = str_repeat('é', 60);
        $owner = $this->seedUser('Owner', 'owner@example.com');
        $target = $this->seedUser('Target', 'target@example.com');

        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $owner->id]);
        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $target->id]);

        $envelope = Envelope::query()->where('user_id', $owner->id)->sole();

        $envelope->transferTo($target);
        $envelope->refresh();

        $this->assertSame(60, mb_strlen($envelope->name));
    }

    /**
     * A concurrent transfer claiming the chosen name surfaces as a
     * PostgreSQL unique violation (SQLSTATE 23505) on the save: the
     * transfer must retry the resolution against the new state instead of
     * failing the enclosing operation. The claimant commits through a
     * second connection the moment the save runs, so it survives the
     * failed attempt's rollback and the retry must re-resolve around it.
     */
    public function test_a_concurrent_claim_of_the_chosen_name_is_retried_on_the_unique_violation(): void
    {
        $target = $this->seedUser('Target', 'target@example.com');
        $entrant = $this->seedUser('Entrant', 'entrant@example.com');

        $envelope = Envelope::create(['name' => 'Groceries', 'type' => 'expense', 'user_id' => $entrant->id]);

        $config = config('database.connections.pgsql_testing');
        $claimant = new \PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $claimed = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$claimed, $claimant, $target): void {
            if ($claimed || ! str_starts_with($query, 'update "envelopes"')) {
                return;
            }

            // A concurrent transfer claims the original name the moment the
            // save runs, forcing a unique violation on this attempt.
            $claimed = true;

            $statement = $claimant->prepare('INSERT INTO envelopes (user_id, name, type) VALUES (?, ?, ?)');
            $statement->execute([$target->id, 'Groceries', 'expense']);
        });

        $envelope->transferTo($target);
        $envelope->refresh();

        $this->assertTrue($claimed);
        $this->assertSame($target->id, $envelope->user_id);
        $this->assertSame('Groceries (imported)', $envelope->name);
    }
}
