<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\FinancialLockSetChanged;
use App\Http\Controllers\FinancialProfileInviteController;
use App\Http\Requests\Profile\StoreInviteRequest;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\User;
use App\Services\AccountDeletionLocks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

/** Real two-connection protocol tests; the release-gating PostgreSQL job executes these. */
final class PostgresFinancialLockProtocolTest extends TestCase
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
        // These tests create only a private, generated schema in a disposable
        // test database, never drop tables in an existing public schema.
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
            self::$skip = 'PostgreSQL is unavailable for the financial-lock barrier tests.';
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

        $this->schema = 'prospera_financial_locks_'.getmypid().'_'.++self::$sequence;
        config(['database.connections.financial_lock_testing' => [
            'driver' => 'pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: '5432',
            'database' => self::$database, 'username' => getenv('PGUSER') ?: get_current_user(),
            'password' => getenv('PGPASSWORD') ?: '', 'charset' => 'utf8', 'prefix' => '',
            'search_path' => $this->schema,
        ], 'database.default' => 'financial_lock_testing']);
        DB::purge('financial_lock_testing');
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        // Metadata-only fixture: neither the planner nor ledger destruction
        // reads an encrypted value, and no real financial row is involved.
        foreach ([
            'CREATE TABLE users (id bigint PRIMARY KEY, name text, email text, password text, email_verified_at timestamp)',
            'CREATE TABLE financial_profiles (id bigint PRIMARY KEY, name text)',
            'CREATE TABLE financial_profile_user (profile_id bigint REFERENCES financial_profiles(id), user_id bigint REFERENCES users(id), is_owner boolean, is_default boolean DEFAULT false, created_at timestamp, updated_at timestamp)',
            'CREATE TABLE financial_profile_invites (id bigint PRIMARY KEY, profile_id bigint REFERENCES financial_profiles(id) ON DELETE CASCADE, email text, invite_key text, invited_by bigint REFERENCES users(id), pending_user_id bigint REFERENCES users(id), role text, expires_at timestamp, created_at timestamp, updated_at timestamp)',
            'CREATE TABLE envelopes (id bigint PRIMARY KEY, user_id bigint REFERENCES users(id), name text)',
            'CREATE TABLE ledgers (id bigint PRIMARY KEY, profile_id bigint REFERENCES financial_profiles(id), name text)',
            'CREATE TABLE budgets (id bigint PRIMARY KEY, profile_id bigint REFERENCES financial_profiles(id), currency text)',
            'CREATE TABLE budget_envelopes (id bigserial PRIMARY KEY, budget_id bigint REFERENCES budgets(id), envelope_id bigint REFERENCES envelopes(id))',
            'CREATE TABLE transactions (id bigint PRIMARY KEY, ledger_id bigint REFERENCES ledgers(id), envelope_id bigint REFERENCES envelopes(id), deleted_at timestamp NULL)',
            "INSERT INTO users VALUES (1, 'Owner', 'owner@example.com', 'unused', '2026-01-01'), (2, 'Other', 'other@example.com', 'unused', '2026-01-01')",
            "INSERT INTO financial_profiles VALUES (10, 'Owned'), (20, 'External')",
            'INSERT INTO financial_profile_user (profile_id, user_id, is_owner, is_default) VALUES (10, 1, true, true), (20, 2, true, true)',
            "INSERT INTO envelopes VALUES (100, 1, 'Envelope')",
            "INSERT INTO ledgers VALUES (1000, 10, 'Owned'), (2000, 20, 'External')",
            "INSERT INTO budgets VALUES (100, 10, 'CAD'), (200, 20, 'CAD')",
            'INSERT INTO transactions VALUES (10000, 1000, 100, NULL)',
        ] as $sql) {
            DB::statement($sql);
        }
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            DB::purge('financial_lock_testing');
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

    private function assertBlocked(PDO $other, string $sql): void
    {
        try {
            $other->exec($sql);
            $this->fail('A concurrent writer bypassed the deletion root barrier.');
        } catch (PDOException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        }
    }

    public function test_account_barrier_blocks_new_roots_existing_budget_edits_and_profile_mutators(): void
    {
        $other = $this->otherConnection();
        DB::transaction(function () use ($other): void {
            $this->assertSame(1, app(AccountDeletionLocks::class)->lock(1)?->id);
            $this->assertBlocked($other, "INSERT INTO ledgers VALUES (3000, 10, 'Concurrent')");
            $this->assertBlocked($other, "UPDATE budgets SET currency = 'USD' WHERE id = 100");
            $this->assertBlocked($other, 'SELECT id FROM financial_profiles WHERE id = 10 FOR UPDATE');
            $this->assertBlocked($other, "INSERT INTO envelopes VALUES (300, 1, 'Concurrent')");
        });
        $this->assertSame('CAD', DB::table('budgets')->where('id', 100)->value('currency'));
    }

    public function test_an_external_reference_committed_during_discovery_requires_a_fresh_attempt(): void
    {
        $other = $this->otherConnection();
        $injected = false;
        DB::connection()->beforeExecuting(function (string $query) use ($other, &$injected): void {
            if (! $injected && str_starts_with($query, 'select "id" from "envelopes"')) {
                $injected = true;
                // User is already locked, but this new external profile
                // reference touches only existing budget/envelope FKs.
                $other->exec('INSERT INTO budget_envelopes (budget_id, envelope_id) VALUES (200, 100)');
            }
        });
        try {
            DB::transaction(fn () => app(AccountDeletionLocks::class)->lock(1));
            $this->fail('The first attempt missed an externally committed profile reference.');
        } catch (FinancialLockSetChanged) {
            $this->assertTrue($injected);
        }
        // Unlike an in-memory same-connection simulation, this row survives
        // rollback and must be visible to the rediscovery attempt.
        $this->assertSame(1, DB::table('budget_envelopes')->count());
        DB::transaction(function () use ($other): void {
            $this->assertSame(1, app(AccountDeletionLocks::class)->lock(1)?->id);
            $this->assertBlocked($other, 'SELECT id FROM ledgers WHERE id = 2000 FOR UPDATE');
        });
    }

    public function test_ledger_destruction_does_not_lock_children_before_waiting_on_the_ledger(): void
    {
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->exec('SELECT id FROM ledgers WHERE id = 1000 FOR UPDATE');
        $released = false;
        DB::connection()->beforeExecuting(function (string $query) use ($other, &$released): void {
            if (! $released && ((str_contains($query, 'from "ledgers"') && str_contains($query, 'for update'))
                || str_starts_with($query, 'delete from "ledgers"'))) {
                // This NOWAIT would fail under the former child-first delete:
                // it would already own the transaction row while waiting here.
                $this->assertSame(10000, (int) $other->query('SELECT id FROM transactions WHERE id = 10000 FOR UPDATE NOWAIT')->fetchColumn());
                $other->commit();
                $released = true;
            }
        });
        try {
            Ledger::query()->findOrFail(1000)->deleteWithTransactions();
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
        $this->assertTrue($released);
        $this->assertSame(0, DB::table('ledgers')->where('id', 1000)->count());
        $this->assertSame(0, DB::table('transactions')->count());
    }

    public function test_invite_replacement_waits_on_profile_before_locking_the_previous_invite(): void
    {
        DB::table('financial_profiles')->insert(['id' => 30, 'name' => 'Inviting profile']);
        DB::table('financial_profile_invites')->insert([
            'id' => 300, 'profile_id' => 30, 'email' => 'other@example.com',
            'invite_key' => FinancialProfileInvite::keyHash('replace-me'), 'invited_by' => 1,
            'expires_at' => '2999-01-01',
        ]);
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->exec('SELECT id FROM financial_profiles WHERE id = 30 FOR UPDATE');
        $released = false;
        DB::connection()->beforeExecuting(function (string $query) use ($other, &$released): void {
            if (! $released && ((str_contains($query, 'from "financial_profiles"') && str_contains($query, 'for update'))
                || str_starts_with($query, 'insert into "financial_profile_invites"'))) {
                $this->assertSame(300, (int) $other->query('SELECT id FROM financial_profile_invites WHERE id = 300 FOR UPDATE NOWAIT')->fetchColumn());
                $other->exec('DELETE FROM financial_profiles WHERE id = 30');
                $other->commit();
                $released = true;
            }
        });
        $user = User::query()->findOrFail(1);
        $request = \Mockery::mock(StoreInviteRequest::class)->makePartial();
        $request->shouldReceive('validated')->once()->andReturn(['email' => 'other@example.com']);
        $request->setUserResolver(fn () => $user);
        try {
            app(FinancialProfileInviteController::class)->store($request, FinancialProfile::query()->findOrFail(30));
            $this->fail('A deleted profile must not receive a replacement invite.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(FinancialProfile::class, $exception->getModel());
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
        $this->assertTrue($released);
        $this->assertSame(0, DB::table('financial_profile_invites')->count());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function inviteGrantPaths(): iterable
    {
        yield 'authenticated accept endpoint' => [false, false];
        yield 'reserved verification completion' => [true, false];
        yield 'accept versus profile deletion' => [false, true];
        yield 'completion versus profile deletion' => [true, true];
    }

    #[DataProvider('inviteGrantPaths')]
    public function test_invite_grants_wait_on_the_profile_without_holding_its_invite(bool $reserved, bool $deleteProfile): void
    {
        DB::table('financial_profiles')->insert(['id' => 30, 'name' => 'Inviting profile']);
        DB::table('financial_profile_invites')->insert([
            'id' => 300, 'profile_id' => 30, 'email' => 'other@example.com',
            'invite_key' => FinancialProfileInvite::keyHash('barrier-invite'), 'invited_by' => 1,
            'pending_user_id' => $reserved ? 2 : null, 'role' => 'collaborator', 'expires_at' => '2999-01-01',
        ]);
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->exec('SELECT id FROM financial_profiles WHERE id = 30 FOR UPDATE');
        $released = false;
        DB::connection()->beforeExecuting(function (string $query) use ($other, &$released, $deleteProfile): void {
            if (! $released && ((str_contains($query, 'from "financial_profiles"') && str_contains($query, 'for update'))
                || str_starts_with($query, 'insert into "financial_profile_user"'))) {
                // A deleter holding the profile must never wait for an invite
                // already held by an acceptor that is waiting for this profile.
                $this->assertSame(300, (int) $other->query('SELECT id FROM financial_profile_invites WHERE id = 300 FOR UPDATE NOWAIT')->fetchColumn());
                if ($deleteProfile) {
                    $other->exec('DELETE FROM financial_profiles WHERE id = 30');
                }
                $other->commit();
                $released = true;
            }
        });
        try {
            $user = User::query()->findOrFail(2);
            if ($reserved) {
                FinancialProfileInvite::completePendingFor($user);
            } else {
                $request = Request::create('/api/invites/barrier-invite/accept', 'POST');
                $request->setUserResolver(fn () => $user);
                try {
                    $response = app(FinancialProfileInviteController::class)->accept($request, 'barrier-invite');
                    $this->assertFalse($deleteProfile);
                    $this->assertSame(200, $response->status());
                } catch (HttpException $exception) {
                    $this->assertTrue($deleteProfile);
                    $this->assertSame(404, $exception->getStatusCode());
                }
            }
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
        $this->assertTrue($released);
        $this->assertSame($deleteProfile ? 0 : 1, DB::table('financial_profile_user')->where('profile_id', 30)->where('user_id', 2)->count());
        $this->assertSame(0, DB::table('financial_profile_invites')->count());
    }
}
