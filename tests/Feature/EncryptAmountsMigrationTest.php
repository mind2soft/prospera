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
 */
class EncryptAmountsMigrationTest extends TestCase
{
    /** @var array<string, array<int, int>> "table.column" => [id => amount] */
    private array $plaintext = [];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        $this->assertFalse(Schema::hasTable('financial_profile_keys'));
        $this->seedPlaintext();
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

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));

        $this->assertFalse(Schema::hasTable('financial_profile_keys'));
        $this->assertSame($this->plaintext, array_map(
            fn (array $values): array => array_map(fn ($value): int => (int) $value, $values),
            $this->rawValues(),
        ));
        foreach (array_keys($this->plaintext) as $target) {
            [$table, $column] = explode('.', $target);
            $this->assertSame('integer', Schema::getColumnType($table, $column), $target);
        }
    }

    public function test_it_refuses_to_run_inside_a_transaction_on_sqlite(): void
    {
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
    }
}
