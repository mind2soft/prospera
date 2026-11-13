<?php

namespace Tests\Feature;

use App\Console\Commands\ListRanMigrations;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileKey;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountKeyRing;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Data-key lifecycle commands (docs/encryption.md).
 */
class AmountKeyCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Envelope $envelope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->envelope = $this->user->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e']);
    }

    /**
     * A profile with a ledger (starting balance), two transactions and a
     * budget assignment. Returns [profile, ledger, transactions, assignment].
     *
     * @return array{0: FinancialProfile, 1: Ledger, 2: list<Transaction>, 3: BudgetEnvelope}
     */
    private function seedProfile(string $name, int $base): array
    {
        $profile = FinancialProfile::createOwnedBy($this->user, $name);

        $ledger = Ledger::create([
            'profile_id' => $profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => $base,
        ]);

        $transactions = [];
        foreach ([-$base - 1, -$base - 2] as $i => $amount) {
            $transactions[] = $ledger->transactions()->create([
                'user_id' => $this->user->id,
                'envelope_id' => $this->envelope->id,
                'amount' => $amount,
                'event_at' => '2026-06-1'.$i.' 00:00:00',
            ]);
        }

        $budget = Budget::create([
            'profile_id' => $profile->id,
            'currency' => 'CAD',
            'effective_year' => 2026,
            'effective_month' => 6,
            'published_at' => now(),
        ]);

        $assignment = $budget->envelopes()->create(['envelope_id' => $this->envelope->id, 'amount' => -$base - 3, 'type' => 'fixed']);

        return [$profile, $ledger, $transactions, $assignment];
    }

    /**
     * Raw stored payloads of the profile's amounts, keyed "table#id".
     *
     * @return array<string, string>
     */
    private function rawPayloads(Ledger $ledger, array $transactions, BudgetEnvelope $assignment): array
    {
        $raw = fn (Model $model, string $column = 'amount'): string => (string) DB::table($model->getTable())->where('id', $model->getKey())->value($column);

        $payloads = ['ledgers#'.$ledger->id => $raw($ledger, 'starting_balance'), 'budget_envelopes#'.$assignment->id => $raw($assignment)];
        foreach ($transactions as $transaction) {
            $payloads['transactions#'.$transaction->id] = $raw($transaction);
        }

        return $payloads;
    }

    /**
     * @return list<int>
     */
    private function versions(array $payloads): array
    {
        return array_values(array_unique(array_map(fn (string $payload): int => AmountCipher::keyVersionOf($payload), $payloads)));
    }

    /**
     * @param  list<Transaction>  $transactions
     */
    private function assertValues(Ledger $ledger, array $transactions, BudgetEnvelope $assignment, int $base): void
    {
        app(AmountKeyRing::class)->forget();

        $this->assertSame($base, Ledger::query()->findOrFail($ledger->id)->starting_balance);
        $this->assertSame(-$base - 1, Transaction::query()->findOrFail($transactions[0]->id)->amount);
        $this->assertSame(-$base - 2, Transaction::query()->findOrFail($transactions[1]->id)->amount);
        $this->assertSame(-$base - 3, BudgetEnvelope::query()->findOrFail($assignment->id)->amount);
    }

    public function test_rotation_re_encrypts_one_profile_with_a_new_key_version(): void
    {
        [$profile, $ledger, $transactions, $assignment] = $this->seedProfile('Mine', 100);
        [$other, $otherLedger, $otherTransactions, $otherAssignment] = $this->seedProfile('Other', 500);
        $otherBefore = $this->rawPayloads($otherLedger, $otherTransactions, $otherAssignment);
        $updatedAt = DB::table('transactions')->where('id', $transactions[0]->id)->value('updated_at');

        $this->travel(1)->hours();
        $this->artisan('amounts:rotate-keys', ['--profile' => [$profile->id]])
            ->expectsOutputToContain('4 amount(s) re-encrypted')
            ->assertSuccessful();

        $this->assertSame([2], $this->versions($this->rawPayloads($ledger, $transactions, $assignment)));
        $this->assertValues($ledger, $transactions, $assignment, 100);
        $this->assertNotNull(FinancialProfileKey::query()->where('profile_id', $profile->id)->where('version', 1)->value('retired_at'));
        $this->assertNull(FinancialProfileKey::query()->where('profile_id', $profile->id)->where('version', 2)->value('retired_at'));
        $this->assertSame($updatedAt, DB::table('transactions')->where('id', $transactions[0]->id)->value('updated_at'));

        // The other profile is untouched.
        $this->assertSame($otherBefore, $this->rawPayloads($otherLedger, $otherTransactions, $otherAssignment));
        $this->assertSame(1, FinancialProfileKey::query()->where('profile_id', $other->id)->count());
    }

    public function test_rotation_of_all_profiles(): void
    {
        [, $ledger, $transactions, $assignment] = $this->seedProfile('Mine', 100);
        [, $otherLedger, $otherTransactions, $otherAssignment] = $this->seedProfile('Other', 500);

        $this->artisan('amounts:rotate-keys', ['--all' => true])->assertSuccessful();

        $this->assertSame([2], $this->versions($this->rawPayloads($ledger, $transactions, $assignment)));
        $this->assertSame([2], $this->versions($this->rawPayloads($otherLedger, $otherTransactions, $otherAssignment)));
        $this->assertValues($otherLedger, $otherTransactions, $otherAssignment, 500);
    }

    public function test_a_failed_rotation_rolls_back_entirely(): void
    {
        [$profile, $ledger, $transactions, $assignment] = $this->seedProfile('Mine', 100);

        // Corrupt the budget assignment: transactions are re-encrypted first,
        // then the rotation fails on this row.
        $payload = (string) DB::table('budget_envelopes')->where('id', $assignment->id)->value('amount');
        DB::table('budget_envelopes')->where('id', $assignment->id)->update(['amount' => substr($payload, 0, -1).(str_ends_with($payload, 'A') ? 'B' : 'A')]);
        $before = $this->rawPayloads($ledger, $transactions, $assignment);

        $this->artisan('amounts:rotate-keys', ['--profile' => [$profile->id]])
            ->expectsOutputToContain('rotation failed and was rolled back')
            ->assertFailed();

        $this->assertSame($before, $this->rawPayloads($ledger, $transactions, $assignment));
        $this->assertSame(1, FinancialProfileKey::query()->where('profile_id', $profile->id)->count());
        $this->assertNull(FinancialProfileKey::query()->where('profile_id', $profile->id)->value('retired_at'));
    }

    public function test_rotation_requires_explicit_targets(): void
    {
        $this->artisan('amounts:rotate-keys')->assertExitCode(2);
        $this->artisan('amounts:rotate-keys', ['--profile' => ['abc']])->assertExitCode(2);
        $this->artisan('amounts:rotate-keys', ['--profile' => ['1'], '--all' => true])->assertExitCode(2);
    }

    public function test_retired_keys_survive_their_grace_period_then_are_pruned(): void
    {
        [$profile, $ledger, $transactions, $assignment] = $this->seedProfile('Mine', 100);
        $ring = app(AmountKeyRing::class);
        $keyV1 = $ring->key($profile->id, 1);

        $this->artisan('amounts:rotate-keys', ['--profile' => [$profile->id]])->assertSuccessful();

        // A request that loaded key v1 before the rotation writes afterwards.
        $straggler = $transactions[0];
        $context = Transaction::amountContextFor('amount', (array) DB::table('transactions')->where('id', $straggler->id)->first(), $profile->id);
        DB::table('transactions')->where('id', $straggler->id)->update(['amount' => (new AmountCipher)->encrypt(-77700, $keyV1, $context)]);

        $this->artisan('amounts:prune-keys')->assertSuccessful();
        $this->assertSame(2, FinancialProfileKey::query()->where('profile_id', $profile->id)->count());
        $ring->forget();
        $this->assertSame(-777, Transaction::query()->findOrFail($straggler->id)->amount);

        $this->travel(25)->hours();
        $this->artisan('amounts:prune-keys')
            ->expectsOutputToContain('1 retired key version(s) deleted')
            ->assertSuccessful();

        $this->assertSame([2], FinancialProfileKey::query()->where('profile_id', $profile->id)->pluck('version')->all());
        $this->assertSame([2], $this->versions($this->rawPayloads($ledger, $transactions, $assignment)));
        $ring->forget();
        $this->assertSame(-777, Transaction::query()->findOrFail($straggler->id)->amount);
        $this->artisan('amounts:verify')->assertSuccessful();
    }

    public function test_pruning_validates_the_grace_option(): void
    {
        $this->artisan('amounts:prune-keys', ['--grace' => '-1'])->assertExitCode(2);
    }

    public function test_rewrapping_moves_keys_to_the_new_app_key(): void
    {
        [, $ledger, $transactions, $assignment] = $this->seedProfile('Mine', 100);
        $oldKey = (string) config('app.key');
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        $useKey = function (string $key, array $previous): void {
            config(['app.key' => $key, 'app.previous_keys' => $previous]);
            $this->app->forgetInstance('encrypter');
            Crypt::clearResolvedInstance('encrypter');
            app(AmountKeyRing::class)->forget();
        };

        $useKey($newKey, [$oldKey]);
        $this->artisan('amounts:rewrap-keys')->expectsOutputToContain('1 amount key(s) re-wrapped')->assertSuccessful();

        $useKey($newKey, []);
        $this->assertValues($ledger, $transactions, $assignment, 100);
        $this->artisan('amounts:verify')->assertSuccessful();
    }

    public function test_verify_reports_corrupted_amounts(): void
    {
        [, , $transactions] = $this->seedProfile('Mine', 100);

        $this->artisan('amounts:verify')->expectsOutput('All amounts decrypt.')->assertSuccessful();

        DB::table('transactions')->where('id', $transactions[1]->id)->update(['amount' => '-102']);
        app(AmountKeyRing::class)->forget();

        $this->artisan('amounts:verify')
            ->expectsOutputToContain("transactions #{$transactions[1]->id}")
            ->expectsOutputToContain('1 amount(s) failed verification.')
            ->assertFailed();
    }

    public function test_migrations_ran_lists_the_migrations_table(): void
    {
        $this->artisan('migrations:ran')
            ->expectsOutput('0001_01_01_000000_create_users_table')
            ->expectsOutput('2026_11_03_000001_encrypt_amounts_at_rest')
            ->assertSuccessful();

        Schema::drop('migrations');

        $this->artisan('migrations:ran')->assertExitCode(ListRanMigrations::NO_REPOSITORY);
    }

    public function test_rotation_pruning_and_verification_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn (Event $event): array => [$event->command, $event->expression]);

        $this->assertTrue($commands->contains(fn (array $e): bool => str_contains((string) $e[0], 'amounts:rotate-keys --all') && $e[1] === '0 0 1 * *'));
        $this->assertTrue($commands->contains(fn (array $e): bool => str_contains((string) $e[0], 'amounts:prune-keys') && $e[1] === '0 0 * * *'));
        $this->assertTrue($commands->contains(fn (array $e): bool => str_contains((string) $e[0], 'amounts:verify') && $e[1] === '0 0 * * *'));
    }
}
