<?php

namespace Tests\Feature;

use App\Models\BudgetEnvelope;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountKeyRing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The encryption-at-rest migration converts existing plaintext amounts and
 * is fully reversible (production rollbacks run migrate:rollback).
 *
 * The class deliberately does not use RefreshDatabase: the SQLite down()
 * refuses to run inside a transaction, and RefreshDatabase would hold one
 * open across the rollback loop. On an in-memory SQLite database every test
 * therefore starts from an empty, discarded database. On a persistent test
 * database (PostgreSQL, the release-gating environment of #84) the same
 * steps commit, so setUp resets the schema to a pristine full state and
 * tearDown restores it — the suite must never inherit this class's
 * partially rolled-back schema.
 */
class EncryptAmountsMigrationTest extends TestCase
{
    /** @var array<string, array<int, int>> "table.column" => [id => amount] */
    private array $plaintext = [];

    /**
     * Whether the test database survives across tests. In-memory SQLite
     * databases are discarded with each application instance; persistent
     * databases (PostgreSQL) keep every committed change.
     */
    private static function persistent(): bool
    {
        return config('database.connections.'.config('database.default').'.database') !== ':memory:';
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::persistent()) {
            // A pristine schema regardless of what previous tests (or this
            // class's own tearDown) left behind.
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        Artisan::call('migrate', ['--force' => true]);

        // Roll back the encryption migration itself — not whatever migration
        // happens to be the newest. Rolling back one migration at a time
        // until the encryption tables are gone keeps this test agnostic of
        // migrations added after it. Bounded: a stuck down() must fail the
        // test, not hang it.
        $this->rollbackUntilKeysAreGone();

        $this->seedPlaintext();
    }

    protected function tearDown(): void
    {
        if (self::persistent()) {
            // Undo the partial rollback: the tests that run after this class
            // expect the full committed schema.
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    private function rollbackUntilKeysAreGone(): void
    {
        for ($i = 0; Schema::hasTable('financial_profile_keys'); $i++) {
            if ($i > 50) {
                $this->fail('migrate:rollback never removed the encryption tables.');
            }

            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        }
    }

    private function seedPlaintext(): void
    {
        $now = now();
        $userId = DB::table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $envelopeId = DB::table('envelopes')->insertGetId(['user_id' => $userId, 'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e', 'created_at' => $now, 'updated_at' => $now]);
        $otherEnvelopeId = DB::table('envelopes')->insertGetId(['user_id' => $userId, 'name' => 'Salary', 'type' => 'revenue', 'color' => '#10b981', 'created_at' => $now, 'updated_at' => $now]);

        foreach ([[0, [-42, 0]], [-500, [999999999, -999999999]]] as $p => [$balance, $amounts]) {
            $profileId = DB::table('financial_profiles')->insertGetId(['name' => "P{$p}", 'is_default' => false, 'created_at' => $now, 'updated_at' => $now]);

            $ledgerId = DB::table('ledgers')->insertGetId([
                'profile_id' => $profileId, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
                'starting_balance' => $balance, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->plaintext['ledgers.starting_balance'][$ledgerId] = $balance;

            foreach ($amounts as $i => $amount) {
                $id = DB::table('transactions')->insertGetId([
                    'ledger_id' => $ledgerId, 'user_id' => $userId, 'envelope_id' => $i === 0 ? $envelopeId : $otherEnvelopeId,
                    'amount' => $amount, 'event_at' => '2026-06-1'.$i.' 00:00:00', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->plaintext['transactions.amount'][$id] = $amount;
            }

            $budgetId = DB::table('budgets')->insertGetId([
                'profile_id' => $profileId, 'currency' => 'CAD', 'effective_year' => 2026, 'effective_month' => 6,
                'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = DB::table('budget_envelopes')->insertGetId([
                'budget_id' => $budgetId, 'envelope_id' => $envelopeId, 'amount' => -1300 - $p, 'type' => 'fixed',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->plaintext['budget_envelopes.amount'][$id] = -1300 - $p;
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rawValues(): array
    {
        $values = [];

        foreach (array_keys($this->plaintext) as $target) {
            [$table, $column] = explode('.', $target);
            $values[$target] = DB::table($table)->orderBy('id')->pluck($column, 'id')->all();
        }

        return $values;
    }

    public function test_up_encrypts_existing_amounts_and_down_restores_them(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        // One key per existing profile.
        $this->assertSame([1, 1], DB::table('financial_profile_keys')->orderBy('profile_id')->pluck('version')->map(fn ($v): int => (int) $v)->all());

        foreach ($this->rawValues() as $target => $values) {
            $this->assertSame(array_keys($this->plaintext[$target]), array_keys($values), $target);

            foreach ($values as $raw) {
                $this->assertTrue(AmountCipher::looksEncrypted($raw), "{$target} was not encrypted.");
            }
        }

        app(AmountKeyRing::class)->forget();
        foreach ($this->plaintext['transactions.amount'] as $id => $amount) {
            $this->assertSame($amount, Transaction::query()->findOrFail($id)->amount);
        }
        foreach ($this->plaintext['budget_envelopes.amount'] as $id => $amount) {
            $this->assertSame($amount, BudgetEnvelope::query()->findOrFail($id)->amount);
        }
        foreach ($this->plaintext['ledgers.starting_balance'] as $id => $amount) {
            $this->assertSame($amount, Ledger::query()->findOrFail($id)->starting_balance);
        }
        $this->assertSame(0, Artisan::call('amounts:verify'));

        foreach (array_keys($this->plaintext) as $target) {
            [$table, $column] = explode('.', $target);
            $this->assertFalse(collect(Schema::getColumns($table))->firstWhere('name', $column)['nullable'], "{$target} is nullable.");
        }

        // Roll back the encryption migration itself — not whatever migration
        // happens to be the newest (see setUp).
        $this->rollbackUntilKeysAreGone();
        $this->assertSame($this->plaintext, array_map(
            fn (array $values): array => array_map(fn ($value): int => (int) $value, $values),
            $this->rawValues(),
        ));
        foreach (array_keys($this->plaintext) as $target) {
            [$table, $column] = explode('.', $target);
            // The restored column type is the platform's name for the
            // original `integer` columns: "integer" on SQLite, "int4" on
            // PostgreSQL.
            $this->assertSame(
                DB::getDriverName() === 'pgsql' ? 'int4' : 'integer',
                Schema::getColumnType($table, $column),
                $target,
            );
        }
    }

    /**
     * SQLite rebuilds the amount tables (a table rebuild would cascade-delete
     * child rows), so the migration refuses to run inside a transaction
     * there. PostgreSQL executes every direction atomically in one
     * transaction instead: the same scenario asserts that running the
     * migration inside a transaction succeeds and is fully undoable.
     */
    public function test_the_transaction_boundary_matches_the_driver(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::beginTransaction();

            try {
                Artisan::call('migrate', ['--force' => true]);
                $this->fail('The migration ran inside a transaction.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('must not run inside a transaction', $e->getMessage());
            } finally {
                DB::rollBack();
            }

            $this->assertFalse(Schema::hasTable('financial_profile_keys'));
            $this->assertSame($this->plaintext['transactions.amount'], array_map(
                fn ($value): int => (int) $value,
                DB::table('transactions')->orderBy('id')->pluck('amount', 'id')->all(),
            ));

            return;
        }

        // On PostgreSQL the migration is atomic: inside a transaction it
        // encrypts everything, and rolling that transaction back restores
        // the exact plaintext state — nothing leaks out half-applied.
        DB::beginTransaction();

        try {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

            $this->assertTrue(Schema::hasTable('financial_profile_keys'));
            foreach (array_keys($this->rawValues()) as $target) {
                [$table, $column] = explode('.', $target);

                foreach (DB::table($table)->orderBy('id')->pluck($column, 'id')->all() as $raw) {
                    $this->assertTrue(AmountCipher::looksEncrypted($raw), "{$target} was not encrypted.");
                }
            }
        } finally {
            DB::rollBack();
        }

        $this->assertFalse(Schema::hasTable('financial_profile_keys'), 'The rolled-back migration left its keys behind.');
        $this->assertSame($this->plaintext['transactions.amount'], array_map(
            fn ($value): int => (int) $value,
            DB::table('transactions')->orderBy('id')->pluck('amount', 'id')->all(),
        ));
        $this->assertSame($this->plaintext['budget_envelopes.amount'], array_map(
            fn ($value): int => (int) $value,
            DB::table('budget_envelopes')->orderBy('id')->pluck('amount', 'id')->all(),
        ));
        $this->assertSame($this->plaintext['ledgers.starting_balance'], array_map(
            fn ($value): int => (int) $value,
            DB::table('ledgers')->orderBy('id')->pluck('starting_balance', 'id')->all(),
        ));
    }
}
