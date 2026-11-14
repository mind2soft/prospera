<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileKey;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountDecryptionException;
use App\Security\Amounts\AmountKeyRing;
use App\Security\Amounts\DataKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Encryption at rest of money amounts (issue #4, docs/encryption.md).
 */
class AmountEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FinancialProfile $profile;

    private Ledger $ledger;

    private Envelope $rent;

    private Envelope $salary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->user, 'Family');
        $this->ledger = $this->createLedger($this->profile);
        $this->rent = $this->user->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e']);
        $this->salary = $this->user->envelopes()->create(['name' => 'Salary', 'type' => 'revenue', 'color' => '#10b981']);
        $this->user->envelopes()->create(['name' => 'Food', 'type' => 'expense', 'color' => '#10b981']);
        $this->user->envelopes()->create(['name' => 'Utilities', 'type' => 'expense', 'color' => '#10b981']);
    }

    private function createLedger(FinancialProfile $profile, int $startingBalance = 5000): Ledger
    {
        $ledger = Ledger::create([
            'profile_id' => $profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => $startingBalance,
        ]);

        $ledger->permissions()->create(['user_id' => $this->user->id, 'permission' => 'owner']);

        return $ledger;
    }

    private function createBudget(FinancialProfile $profile, bool $published = true): Budget
    {
        $budget = Budget::create([
            'profile_id' => $profile->id,
            'currency' => 'CAD',
            'effective_year' => 2026,
            'effective_month' => 6,
            'published_at' => $published ? now() : null,
        ]);

        $budget->permissions()->create(['user_id' => $this->user->id, 'permission' => BudgetPermission::PERMISSION_OWNER]);

        return $budget;
    }

    private function transaction(Ledger $ledger, Envelope $envelope, int|float $amount, string $date = '2026-06-15'): Transaction
    {
        return $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'amount' => $amount,
            'event_at' => $date.' 00:00:00',
        ]);
    }

    private function rawAmount(Model $model, string $column = 'amount'): string
    {
        return (string) DB::table($model->getTable())->where('id', $model->getKey())->value($column);
    }

    private function setRawAmount(Model $model, string $payload, string $column = 'amount'): void
    {
        DB::table($model->getTable())->where('id', $model->getKey())->update([$column => $payload]);
    }

    /**
     * A fresh app key, as Laravel's key:generate writes it.
     */
    private function newAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * @param  list<string>  $previousKeys
     */
    private function useAppKey(string $key, array $previousKeys = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        app(AmountKeyRing::class)->forget();
    }

    // ------------------------------------------------------------------
    // Keys
    // ------------------------------------------------------------------

    public function test_every_new_profile_gets_one_wrapped_key(): void
    {
        $owned = FinancialProfile::createOwnedBy($this->user, 'Other');
        $default = User::factory()->withDefaultProfile()->create()->profiles()->firstOrFail();

        foreach ([$owned, $default] as $profile) {
            $keys = FinancialProfileKey::query()->where('profile_id', $profile->id)->get();

            $this->assertCount(1, $keys);
            $this->assertSame(1, $keys[0]->version);
            $this->assertNull($keys[0]->retired_at);
            $this->assertArrayNotHasKey('wrapped_key', $keys[0]->toArray());
        }
    }

    public function test_wrapped_keys_are_bound_to_their_profile_and_version(): void
    {
        $key = DataKey::generate($this->profile->id, 1);
        $wrapped = AmountKeyRing::wrap($key);

        $this->assertSame($key->bytes(), AmountKeyRing::unwrap($this->profile->id, 1, $wrapped)->bytes());
        $this->assertStringNotContainsString(base64_encode($key->bytes()), $wrapped);

        foreach ([[$this->profile->id + 1, 1], [$this->profile->id, 2]] as [$profileId, $version]) {
            try {
                AmountKeyRing::unwrap($profileId, $version, $wrapped);
                $this->fail('A wrapped key unwrapped for another profile/version.');
            } catch (AmountDecryptionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(AmountDecryptionException::class);
        AmountKeyRing::unwrap($this->profile->id, 1, substr($wrapped, 0, -4).'AAAA');
    }

    public function test_keys_wrapped_with_a_previous_app_key_need_app_previous_keys(): void
    {
        $this->transaction($this->ledger, $this->rent, -1234);
        $oldKey = (string) config('app.key');

        $this->useAppKey($this->newAppKey(), [$oldKey]);
        $this->assertSame(-1234, Transaction::query()->sole()->amount);

        $this->useAppKey((string) config('app.key'));
        $this->expectException(AmountDecryptionException::class);
        Transaction::query()->sole()->amount;
    }

    public function test_the_active_key_is_the_highest_non_retired_version(): void
    {
        $ring = app(AmountKeyRing::class);
        $ring->createKey($this->profile->id);
        $ring->createKey($this->profile->id);
        FinancialProfileKey::query()->where('profile_id', $this->profile->id)->where('version', 3)->update(['retired_at' => now()]);
        $ring->forget();

        $this->assertSame(2, $ring->activeKey($this->profile->id)->version);
        $this->assertSame(3, $ring->key($this->profile->id, 3)->version);

        $this->expectException(AmountDecryptionException::class);
        $ring->key($this->profile->id, 4);
    }

    public function test_a_profile_without_an_active_key_cannot_store_amounts(): void
    {
        FinancialProfileKey::query()->where('profile_id', $this->profile->id)->update(['retired_at' => now()]);
        app(AmountKeyRing::class)->forget();

        $this->expectException(RuntimeException::class);
        $this->transaction($this->ledger, $this->rent, -10);
    }

    public function test_keys_are_memoized_per_request(): void
    {
        $ring = app(AmountKeyRing::class);
        $ring->forget();
        $ring->activeKey($this->profile->id);

        DB::enableQueryLog();
        $ring->activeKey($this->profile->id);
        $ring->key($this->profile->id, 1);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_a_rolled_back_transaction_drops_the_memoized_keys(): void
    {
        $ring = app(AmountKeyRing::class);
        $ring->activeKey($this->profile->id);

        try {
            DB::transaction(function (): void {
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        DB::enableQueryLog();
        $ring->activeKey($this->profile->id);
        $this->assertNotEmpty(DB::getQueryLog());
    }

    public function test_deleting_a_profile_deletes_its_keys(): void
    {
        $solo = FinancialProfile::createOwnedBy($this->user, 'Solo');
        $this->createLedger($solo);

        $solo->deleteWithResources();

        $this->assertDatabaseMissing('financial_profile_keys', ['profile_id' => $solo->id]);
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    public function test_recorded_transactions_are_stored_encrypted(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/ledgers/{$this->ledger->id}/transactions", [
            'envelope_id' => $this->rent->id,
            'amount' => 4242,
            'event_at' => '2026-06-15',
        ])->assertCreated();

        $this->assertSame(-4242, $response->json('transaction.amount'));

        $transaction = Transaction::query()->sole();
        $this->assertEncryptedAmount(Transaction::class, ['id' => $transaction->id], 'amount', -4242);
        $context = Transaction::amountContextFor('amount', $transaction->getRawOriginal(), $this->profile->id);
        app(AmountKeyRing::class)->rememberLedgerProfiles([$this->ledger->id], $this->profile->id);
        $this->assertSame(-424200, app(AmountKeyRing::class)->decrypt($this->rawAmount($transaction), $context));
        $this->assertStringNotContainsString('4242', $this->rawAmount($transaction));
    }

    public function test_transaction_unit_migration_rolls_back_a_failed_chunk_and_can_be_retried(): void
    {
        $ring = app(AmountKeyRing::class);
        $ring->rememberLedgerProfiles([$this->ledger->id], $this->profile->id);
        $key = $ring->activeKey($this->profile->id);
        $attributes = [
            'ledger_id' => $this->ledger->id,
            'envelope_id' => $this->rent->id,
            'event_at' => '2026-06-15 00:00:00',
        ];
        $context = Transaction::amountContextFor('amount', $attributes, $this->profile->id);
        $oldPayload = app(AmountCipher::class)->encrypt(42, $key, $context);
        $rows = [];

        for ($i = 0; $i < 501; $i++) {
            $rows[] = $attributes + [
                'user_id' => $this->user->id,
                'amount' => $i === 500 ? 'tampered' : $oldPayload,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('transactions')->insert($rows);

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_12_000001_store_transaction_amounts_in_minor_units.php');

        try {
            $migration->up();
            $this->fail('The migration accepted a malformed amount in its second chunk.');
        } catch (AmountDecryptionException) {
            $this->assertSame(501, DB::table('transactions')->count());
            $this->assertSame(500, DB::table('transactions')->where('amount', $oldPayload)->count());
            $this->assertSame(1, DB::table('transactions')->where('amount', 'tampered')->count());
        }

        DB::table('transactions')->where('amount', 'tampered')->update(['amount' => $oldPayload]);
        $migration->up();

        $this->assertSame(501, DB::table('transactions')->where('amount', '!=', $oldPayload)->count());
        $converted = (string) DB::table('transactions')->value('amount');
        $this->assertSame(4200, $ring->decrypt($converted, $context));

        $migration->down();
        $rolledBack = (string) DB::table('transactions')->value('amount');
        $this->assertSame(42, $ring->decrypt($rolledBack, $context));
    }

    public function test_budget_assignments_are_stored_encrypted(): void
    {
        $budget = $this->createBudget($this->profile, published: false);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $this->rent->id, 'type' => 'fixed', 'amount' => 1300,
        ])->assertCreated();
        $this->assertEncryptedAmount(BudgetEnvelope::class, ['budget_id' => $budget->id], 'amount', -1300);

        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$this->rent->id}", [
            'type' => 'variable', 'amount' => 250, 'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('envelope.amount', -250);
        $this->assertEncryptedAmount(BudgetEnvelope::class, ['budget_id' => $budget->id], 'amount', -250);
    }

    public function test_ledger_starting_balances_are_stored_encrypted(): void
    {
        $this->actingAs($this->user)->postJson('/api/ledgers', [
            'profile_id' => $this->profile->id,
            'name' => 'Debt',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 777,
            'starting_balance_type' => 'debt',
        ])->assertCreated()->assertJsonPath('ledger.starting_balance', -777);

        $this->assertEncryptedAmount(Ledger::class, ['name' => 'Debt'], 'starting_balance', -777);
    }

    public function test_equal_amounts_produce_different_ciphertexts(): void
    {
        $first = $this->transaction($this->ledger, $this->rent, -100);
        $second = $this->transaction($this->ledger, $this->rent, -100);

        $this->assertNotSame($this->rawAmount($first), $this->rawAmount($second));
    }

    public function test_amounts_are_encrypted_even_without_model_events(): void
    {
        $quiet = new Transaction([
            'ledger_id' => $this->ledger->id,
            'user_id' => $this->user->id,
            'envelope_id' => $this->rent->id,
            'amount' => -55,
            'event_at' => '2026-06-15 00:00:00',
        ]);
        $quiet->saveQuietly();

        Model::withoutEvents(fn () => $this->transaction($this->ledger, $this->salary, 66));

        $this->assertEncryptedAmount(Transaction::class, ['id' => $quiet->id], 'amount', -55);
        $this->assertEncryptedAmount(Transaction::class, ['envelope_id' => $this->salary->id], 'amount', 66);
    }

    public function test_updating_other_columns_leaves_the_ciphertext_untouched(): void
    {
        $raw = $this->rawAmount($this->ledger, 'starting_balance');

        $this->ledger->update(['closed_at' => now()]);

        $this->assertSame($raw, $this->rawAmount($this->ledger, 'starting_balance'));
        $this->assertSame(5000, $this->ledger->fresh()?->starting_balance);
    }

    public function test_changing_the_context_requires_assigning_the_amount(): void
    {
        $transaction = $this->transaction($this->ledger, $this->rent, -321);
        $raw = $this->rawAmount($transaction);

        $transaction = Transaction::query()->findOrFail($transaction->id);
        $transaction->event_at = now()->setDate(2026, 7, 1)->startOfDay();
        $transaction->envelope_id = $this->salary->id;
        $this->assertSame(-321, $transaction->amount); // still readable before saving

        try {
            $transaction->save();
            $this->fail('A context change re-encrypted the stored amount implicitly.');
        } catch (LogicException) {
            $this->assertSame($raw, $this->rawAmount($transaction));
        }

        $transaction->amount = $transaction->amount;
        $transaction->save();

        $this->assertNotSame($raw, $this->rawAmount($transaction));
        $this->assertEncryptedAmount(Transaction::class, ['id' => $transaction->id, 'envelope_id' => $this->salary->id], 'amount', -321);
    }

    public function test_assigning_a_ciphertext_or_an_amount_with_more_than_two_decimal_places_is_rejected(): void
    {
        $transaction = $this->transaction($this->ledger, $this->rent, -1);

        foreach ([$this->rawAmount($transaction), '1.234', '', null, '1e3'] as $value) {
            try {
                $transaction->amount = $value;
                $this->fail('An invalid transaction amount was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $transaction->amount = '-12.34';
        $this->assertSame(-12.34, $transaction->amount);
    }

    public function test_saving_without_an_amount_fails(): void
    {
        $this->expectException(LogicException::class);

        $this->ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $this->rent->id,
            'event_at' => '2026-06-15 00:00:00',
        ]);
    }

    public function test_replicas_are_encrypted_afresh_in_their_own_context(): void
    {
        $budget = $this->createBudget($this->profile);
        $other = $this->createBudget($this->profile, published: false);
        $assignment = $budget->envelopes()->create(['envelope_id' => $this->rent->id, 'amount' => -10, 'type' => 'fixed']);

        $copy = $assignment->replicate();
        $copy->budget_id = $other->id;
        $copy->save();

        $this->assertEncryptedAmount(BudgetEnvelope::class, ['budget_id' => $other->id], 'amount', -10);
        $this->assertNotSame($this->rawAmount($assignment), $this->rawAmount($copy));
    }

    public function test_a_raw_ciphertext_copied_into_another_row_is_rejected_on_save(): void
    {
        $budget = $this->createBudget($this->profile);
        $other = $this->createBudget($this->profile, published: false);
        $assignment = $budget->envelopes()->create(['envelope_id' => $this->rent->id, 'amount' => -10, 'type' => 'fixed']);

        $copy = new BudgetEnvelope;
        $copy->setRawAttributes(['budget_id' => $other->id, 'envelope_id' => $this->rent->id, 'type' => 'fixed', 'amount' => $this->rawAmount($assignment)]);

        $this->expectException(AmountDecryptionException::class);
        $copy->save();
    }

    public function test_bulk_eloquent_writes_cannot_store_plaintext_or_do_sql_arithmetic(): void
    {
        $transaction = $this->transaction($this->ledger, $this->rent, -10);
        $raw = $this->rawAmount($transaction);
        $row = ['ledger_id' => $this->ledger->id, 'user_id' => $this->user->id, 'envelope_id' => $this->rent->id, 'event_at' => '2026-06-15 00:00:00'];

        $attempts = [
            'update' => fn () => Transaction::query()->whereKey($transaction->id)->update(['amount' => 5]),
            'qualified update' => fn () => Transaction::query()->whereKey($transaction->id)->update(['transactions.amount' => '5']),
            'relation update' => fn () => $this->ledger->transactions()->update(['amount' => 5]),
            'model increment' => fn () => $transaction->increment('amount', 5),
            'builder decrement' => fn () => Transaction::query()->decrement('amount'),
            'incrementEach' => fn () => Transaction::query()->incrementEach(['amount' => 1]),
            'upsert' => fn () => Transaction::query()->upsert([$row + ['id' => $transaction->id, 'amount' => 5]], ['id'], ['amount']),
            'insert' => fn () => Transaction::query()->insert($row + ['amount' => 5]),
            'insertGetId' => fn () => Transaction::query()->insertGetId($row + ['amount' => 5]),
            'insertOrIgnore' => fn () => Transaction::query()->insertOrIgnore([$row + ['amount' => 5]]),
            'fillAndInsert' => fn () => Transaction::query()->fillAndInsert([$row + ['amount' => 5]]),
            'insertUsing' => fn () => Transaction::query()->insertUsing(['ledger_id', 'amount'], Transaction::query()->select('ledger_id', 'amount')),
            'ledger update' => fn () => Ledger::query()->whereKey($this->ledger->id)->update(['starting_balance' => 0]),
            'budget assignment update' => fn () => BudgetEnvelope::query()->update(['amount' => 0]),
            'upper-case column' => fn () => Transaction::query()->update(['AMOUNT' => 5]),
            'quoted column' => fn () => Transaction::query()->update(['"transactions"."amount"' => 5]),
            'updateOrInsert' => fn () => Transaction::query()->updateOrInsert(['id' => $transaction->id], ['amount' => 5]),
            'updateOrInsert callback' => fn () => Transaction::query()->updateOrInsert(['id' => $transaction->id], fn (bool $exists): array => ['amount' => 5]),
            'updateOrInsert insert' => fn () => Transaction::query()->updateOrInsert($row + ['amount' => 5]),
            'insertOrIgnoreReturning' => fn () => Transaction::query()->insertOrIgnoreReturning([$row + ['amount' => 5]]),
            'mismatched row columns' => fn () => Transaction::query()->insert([$row + ['amount' => $raw], ['a' => 5] + $row]),
            'mismatched insertOrIgnore columns' => fn () => Transaction::query()->insertOrIgnore([$row + ['amount' => $raw], $row + ['user_id' => 5]]),
            'updateFrom' => fn () => Transaction::query()->updateFrom(['amount' => 5]),
            'context change' => fn () => Transaction::query()->whereKey($transaction->id)->update(['envelope_id' => $this->salary->id]),
            'context change via updateOrInsert' => fn () => Transaction::query()->updateOrInsert(['id' => $transaction->id], ['event_at' => '2026-07-01 00:00:00']),
            'touch amount' => fn () => Transaction::query()->whereKey($transaction->id)->touch('amount'),
            'touch context' => fn () => Transaction::query()->whereKey($transaction->id)->touch(['updated_at', 'event_at']),
            'touch profile' => fn () => Ledger::query()->whereKey($this->ledger->id)->touch('profile_id'),
            'flag is one-shot' => function () use ($transaction): void {
                $query = Transaction::query()->whereKey($transaction->id);
                $query->forModelSave()->update(['user_id' => null]);
                $query->update(['envelope_id' => $this->salary->id]);
            },
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $this->fail("{$name} wrote an encrypted column.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame($raw, $this->rawAmount($transaction));

        // Writes that leave the amounts alone are unaffected.
        Transaction::query()->whereKey($transaction->id)->update(['user_id' => null]);
        $this->assertNull(Transaction::query()->findOrFail($transaction->id)->user_id);
        $this->assertSame(1, Transaction::query()->whereKey($transaction->id)->touch());
        $this->assertSame(-10, Transaction::query()->findOrFail($transaction->id)->amount);
    }

    public function test_ledgers_and_budgets_cannot_move_to_another_profile(): void
    {
        $other = FinancialProfile::createOwnedBy($this->user, 'Other');
        $budget = $this->createBudget($this->profile);

        foreach ([$this->ledger, $budget] as $resource) {
            $moves = [
                'save' => fn () => $resource->update(['profile_id' => $other->id]),
                'builder' => fn () => $resource->newQuery()->whereKey($resource->getKey())->update(['profile_id' => $other->id]),
                'builder upsert' => fn () => $resource->newQuery()->upsert([['id' => $resource->getKey(), 'profile_id' => $other->id]], ['id']),
            ];

            foreach ($moves as $name => $move) {
                try {
                    $move();
                    $this->fail($resource::class." moved to another profile ({$name}).");
                } catch (LogicException) {
                    $this->assertSame($this->profile->id, $resource->newQuery()->findOrFail($resource->getKey())->getAttribute('profile_id'));
                }
            }
        }

        // Other bulk writes are unaffected.
        Budget::query()->whereKey($budget->id)->update(['currency' => 'EUR']);
        $this->assertSame('EUR', $budget->fresh()?->currency);
    }

    public function test_decrypted_amounts_are_memoized_per_model(): void
    {
        $transaction = Transaction::query()->findOrFail($this->transaction($this->ledger, $this->rent, -321)->id);

        $real = app(AmountKeyRing::class);
        $ring = Mockery::mock($real);
        $ring->shouldReceive('decrypt')->once()->andReturnUsing(
            fn (string $payload, AmountContext $context): int => $real->decrypt($payload, $context),
        );
        $this->app->instance(AmountKeyRing::class, $ring);

        $this->assertSame(-321, $transaction->amount);
        $this->assertSame(-321, $transaction->amount);
        $this->assertSame(-321, $transaction->amount);
    }

    /**
     * The memo must not weaken the context-change guard: after the amount
     * was read (and memoized), changing any bound attribute still refuses
     * an implicit re-encryption, and the amount still reads from the
     * original context.
     */
    public function test_the_context_change_guard_holds_with_memoized_amounts(): void
    {
        $otherLedger = $this->createLedger($this->profile);
        $created = $this->transaction($this->ledger, $this->rent, -321);
        $raw = $this->rawAmount($created);

        $changes = [
            'ledger_id' => $otherLedger->id,
            'envelope_id' => $this->salary->id,
            'event_at' => '2026-07-01 00:00:00',
        ];

        foreach ($changes as $attribute => $value) {
            $transaction = Transaction::query()->findOrFail($created->id);
            $this->assertSame(-321, $transaction->amount); // memoized

            $transaction->setAttribute($attribute, $value);
            $this->assertSame(-321, $transaction->amount);

            try {
                $transaction->save();
                $this->fail("Changing {$attribute} re-encrypted the memoized amount implicitly.");
            } catch (LogicException) {
                $this->assertSame($raw, $this->rawAmount($created));
            }
        }
    }

    public function test_a_memoized_amount_is_not_reused_for_another_payload(): void
    {
        $mine = $this->transaction($this->ledger, $this->rent, -10);
        $other = $this->transaction($this->ledger, $this->salary, 99999);

        $transaction = Transaction::query()->findOrFail($mine->id);
        $this->assertSame(-10, $transaction->amount);

        // Another row's ciphertext set raw: decrypted afresh in this row's
        // context — and rejected — instead of reading the memoized -10.
        $transaction->setRawAttributes(['amount' => $this->rawAmount($other)] + $transaction->getAttributes());

        $this->expectException(AmountDecryptionException::class);
        $transaction->amount;
    }

    public function test_serialized_models_do_not_carry_memoized_amounts(): void
    {
        $transaction = Transaction::query()->findOrFail($this->transaction($this->ledger, $this->rent, -987654)->id);
        $this->assertSame(-987654, $transaction->amount);

        $this->assertStringNotContainsString('-987654', serialize($transaction));
        $this->assertSame(-987654, unserialize(serialize($transaction))->amount);
    }

    public function test_a_time_change_within_the_same_day_keeps_the_context(): void
    {
        $transaction = $this->transaction($this->ledger, $this->rent, -40);
        $raw = $this->rawAmount($transaction);

        $transaction->event_at = now()->setDate(2026, 6, 15)->setTime(13, 30);
        $transaction->save();

        $this->assertSame($raw, $this->rawAmount($transaction));
        $this->assertSame(-40, Transaction::query()->findOrFail($transaction->id)->amount);
    }

    public function test_cloned_budgets_get_fresh_ciphertexts(): void
    {
        $budget = $this->createBudget($this->profile);
        $source = $budget->envelopes()->create(['envelope_id' => $this->rent->id, 'amount' => -1300, 'type' => 'fixed']);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/clone")->assertCreated();

        $draft = Budget::query()->whereNull('published_at')->sole();
        $copy = BudgetEnvelope::query()->where('budget_id', $draft->id)->sole();

        $this->assertSame(-1300, $copy->amount);
        $this->assertNotSame($this->rawAmount($source), $this->rawAmount($copy));
    }

    // ------------------------------------------------------------------
    // Integrity: tampering and swapping fail loudly
    // ------------------------------------------------------------------

    public function test_a_tampered_ciphertext_fails_instead_of_reading_as_zero(): void
    {
        $transaction = $this->transaction($this->ledger, $this->rent, -10);
        $raw = $this->rawAmount($transaction);
        $last = $raw[strlen($raw) - 1];
        $this->setRawAmount($transaction, substr($raw, 0, -1).($last === 'A' ? 'B' : 'A'));

        $this->actingAs($this->user)
            ->getJson("/api/ledgers/{$this->ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30")
            ->assertServerError();
    }

    public function test_legacy_plaintext_amounts_fail(): void
    {
        $transaction = $this->transaction($this->ledger, $this->rent, -10);
        $this->setRawAmount($transaction, '-10');

        $this->expectException(AmountDecryptionException::class);
        Transaction::query()->findOrFail($transaction->id)->amount;
    }

    public function test_swapping_ciphertexts_between_rows_is_detected(): void
    {
        $rent = $this->transaction($this->ledger, $this->rent, -10);
        $salary = $this->transaction($this->ledger, $this->salary, 99999);

        $rentRaw = $this->rawAmount($rent);
        $this->setRawAmount($rent, $this->rawAmount($salary));
        $this->setRawAmount($salary, $rentRaw);

        $this->actingAs($this->user)
            ->getJson("/api/ledgers/{$this->ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30")
            ->assertServerError();
    }

    public function test_swapping_ciphertexts_between_profiles_is_detected(): void
    {
        $theirs = $this->createLedger(FinancialProfile::createOwnedBy($this->user, 'Other'));
        $mine = $this->transaction($this->ledger, $this->rent, -10);
        $other = $this->transaction($theirs, $this->rent, -99999);

        $this->setRawAmount($mine, $this->rawAmount($other));

        $this->expectException(AmountDecryptionException::class);
        Transaction::query()->findOrFail($mine->id)->amount;
    }

    public function test_swapping_ciphertexts_between_tables_is_detected(): void
    {
        $budget = $this->createBudget($this->profile);
        $assignment = $budget->envelopes()->create(['envelope_id' => $this->rent->id, 'amount' => -10, 'type' => 'fixed']);
        $transaction = $this->transaction($this->ledger, $this->rent, -99999);

        $this->setRawAmount($transaction, $this->rawAmount($assignment));
        $this->setRawAmount($this->ledger, $this->rawAmount($assignment), 'starting_balance');

        foreach ([fn () => Transaction::query()->findOrFail($transaction->id)->amount, fn () => Ledger::query()->findOrFail($this->ledger->id)->starting_balance] as $read) {
            try {
                $read();
                $this->fail('A ciphertext from another table decrypted.');
            } catch (AmountDecryptionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------------
    // Aggregates computed in PHP
    // ------------------------------------------------------------------

    public function test_ledger_transactions_report_remaining_allocations(): void
    {
        $budget = $this->createBudget($this->profile);
        $budget->envelopes()->create(['envelope_id' => $this->rent->id, 'amount' => -1000, 'type' => 'fixed']);
        $this->transaction($this->ledger, $this->rent, -100.25, '2026-06-03');
        $this->transaction($this->ledger, $this->rent, -250, '2026-06-17');
        $this->transaction($this->ledger, $this->rent, -999, '2026-07-01'); // outside the range
        $this->transaction($this->ledger, $this->salary, 3000, '2026-06-05'); // unallocated

        $rows = collect($this->actingAs($this->user)
            ->getJson("/api/ledgers/{$this->ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30")
            ->assertOk()
            ->json('transactions'));

        $this->assertCount(3, $rows);
        $this->assertSame([-649.75, -649.75], $rows->where('envelope_id', $this->rent->id)->pluck('remaining_allocated')->all());
        $this->assertNull($rows->firstWhere('envelope_id', $this->salary->id)['remaining_allocated']);
        $this->assertSame([-100.25, 3000, -250], $rows->pluck('amount')->all());
    }

    public function test_ledger_transaction_queries_do_not_grow_with_the_row_count(): void
    {
        $count = function (): int {
            app(AmountKeyRing::class)->forget();
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($this->user)
                ->getJson("/api/ledgers/{$this->ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30")
                ->assertOk();

            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->transaction($this->ledger, $this->rent, -1);
        $few = $count();

        for ($i = 0; $i < 20; $i++) {
            $this->transaction($this->ledger, $this->rent, -$i - 2);
        }

        $this->assertSame($few, $count());
    }

    public function test_raw_amount_columns_never_hold_plaintext(): void
    {
        $budget = $this->createBudget($this->profile);
        $budget->envelopes()->create(['envelope_id' => $this->rent->id, 'amount' => -10, 'type' => 'fixed']);
        $this->transaction($this->ledger, $this->rent, -20);

        foreach ([['transactions', 'amount'], ['budget_envelopes', 'amount'], ['ledgers', 'starting_balance']] as [$table, $column]) {
            foreach (DB::table($table)->pluck($column) as $raw) {
                $this->assertTrue(AmountCipher::looksEncrypted($raw), "{$table}.{$column} holds a non-ciphertext value.");
            }
        }
    }
}
