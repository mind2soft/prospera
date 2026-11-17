<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Envelope;
use App\Models\EnvelopeAmountKey;
use App\Models\EnvelopeSchedule;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileKey;
use App\Models\Ledger;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountDecryptionException;
use App\Security\Amounts\AmountKeyHealthCheck;
use App\Security\Amounts\AmountKeyRing;
use App\Security\Amounts\AmountKeyScope;
use App\Services\Schedules\ScheduleOccurrenceResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class EnvelopeScheduleStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Envelope $envelope;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->user, isDefault: true);
        $this->envelope = $this->user->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#123456']);
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(array $attributes = []): EnvelopeSchedule
    {
        return $this->envelope->schedules()->create($attributes + [
            'amount' => -12345, 'start_date' => '2026-01-01', 'period' => 'monthly', 'day_of_month' => 31,
        ]);
    }

    private function raw(EnvelopeSchedule $schedule): string
    {
        return (string) DB::table('envelope_schedules')->where('id', $schedule->id)->value('amount');
    }

    public function test_storage_uses_lazy_envelope_keys_and_integer_minor_units(): void
    {
        $this->assertSame(0, EnvelopeAmountKey::query()->count());
        $schedule = $this->schedule();
        $this->assertSame(1, EnvelopeAmountKey::query()->count());
        $this->assertTrue(AmountCipher::looksEncrypted($this->raw($schedule)));
        $this->assertSame(-12345, $schedule->fresh()?->amount);
        $this->assertArrayNotHasKey('amount', $schedule->toArray());
        $this->assertArrayNotHasKey('wrapped_key', $this->envelope->amountKeys()->sole()->toArray());
        $this->assertSame($this->envelope->id, $schedule->envelope?->id);
        $this->assertSame($this->envelope->id, $this->envelope->amountKeys()->sole()->envelope?->id);
        $this->assertSame(['2026-02-28', '2026-03-31'], (new ScheduleOccurrenceResolver)->resolve($schedule->fresh()->rule(), '2026-02-01', '2026-03-31'));

        $another = $this->schedule();
        $this->assertSame(1, EnvelopeAmountKey::query()->count());
        $this->assertNotSame($this->raw($schedule), $this->raw($another));
    }

    public function test_quiet_and_eventless_saves_still_encrypt(): void
    {
        $schedule = new EnvelopeSchedule([
            'envelope_id' => $this->envelope->id, 'amount' => -99, 'start_date' => '2026-03-01', 'period' => 'once',
        ]);
        $schedule->saveQuietly();
        $other = Model::withoutEvents(fn () => $this->schedule());

        $this->assertTrue(AmountCipher::looksEncrypted($this->raw($schedule)));
        $this->assertTrue(AmountCipher::looksEncrypted($this->raw($other)));
        $this->assertSame(-99, $schedule->fresh()?->amount);
    }

    public function test_existing_encrypted_models_still_serialize_with_their_original_units(): void
    {
        $ledger = Ledger::create([
            'profile_id' => $this->profile->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'starting_balance' => 500,
        ]);
        $transaction = $ledger->transactions()->create([
            'envelope_id' => $this->envelope->id, 'event_at' => '2026-01-01', 'amount' => -12.34,
        ]);
        $budget = Budget::create(['profile_id' => $this->profile->id, 'currency' => 'CAD', 'effective_year' => 2026, 'effective_month' => 1]);
        $assignment = $budget->envelopes()->create(['envelope_id' => $this->envelope->id, 'amount' => -100, 'type' => 'fixed']);

        $this->assertSame(500, $ledger->fresh()->toArray()['starting_balance']);
        $this->assertSame(-12.34, $transaction->fresh()->toArray()['amount']);
        $this->assertSame(-100, $assignment->fresh()->toArray()['amount']);
    }

    public function test_weekdays_are_normalized_and_metadata_saves_do_not_reencrypt(): void
    {
        $schedule = $this->schedule(['period' => 'biweekly', 'weekdays' => [4, 1, 4], 'day_of_month' => null]);
        $this->assertSame([1, 4], $schedule->fresh()?->weekdays);
        $raw = $this->raw($schedule);
        $schedule->update(['reminder_days' => 0, 'end_date' => '2026-12-31']);
        $this->assertSame($raw, $this->raw($schedule));
        $this->assertSame(0, $schedule->fresh()?->reminder_days);
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('invalidSchedules')]
    public function test_invalid_schedules_cannot_write_amounts_or_create_keys(array $attributes): void
    {
        try {
            $this->schedule($attributes);
            $this->fail('Invalid schedule persisted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, EnvelopeSchedule::withTrashed()->count());
            $this->assertSame(0, EnvelopeAmountKey::query()->count());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSchedules(): iterable
    {
        yield 'unknown period' => [['period' => 'daily']];
        yield 'overflowing start date' => [['start_date' => '2026-02-30']];
        yield 'timestamp, not a calendar date' => [['start_date' => '2026-01-01 00:00:00']];
        yield 'end before start' => [['end_date' => '2025-12-31']];
        yield 'monthly day missing' => [['day_of_month' => null]];
        yield 'invalid month' => [['period' => 'yearly', 'month' => 13]];
        yield 'invalid weekdays' => [['period' => 'weekly', 'day_of_month' => null, 'weekdays' => [0]]];
        yield 'missing weekdays' => [['period' => 'biweekly', 'day_of_month' => null]];
        yield 'fractional day' => [['day_of_month' => 1.5]];
        yield 'negative reminder' => [['reminder_days' => -1]];
        yield 'fractional reminder' => [['reminder_days' => 1.5]];
        yield 'oversized reminder' => [['reminder_days' => 367]];
        yield 'oversized month reminder' => [['reminder_days' => 13, 'reminder_unit' => 'month']];
        yield 'unknown reminder unit' => [['reminder_unit' => 'weeks']];
        yield 'expense with positive amount' => [['amount' => 12345]];
        yield 'fractional amount' => [['amount' => -12.34]];
        yield 'plaintext disguised as ciphertext' => [['amount' => 'pa1.1.fake']];
    }

    public function test_revenue_schedules_have_positive_amounts(): void
    {
        $this->envelope->update(['type' => 'revenue']);
        $schedule = $this->schedule(['amount' => 12345]);
        $this->assertSame(12345, $schedule->fresh()?->amount);

        $this->expectException(InvalidArgumentException::class);
        $this->schedule(['amount' => -1]);
    }

    public function test_schedule_envelope_is_immutable_and_bulk_money_writes_are_guarded(): void
    {
        $schedule = $this->schedule();
        $other = $this->user->envelopes()->create(['name' => 'Other', 'type' => 'expense']);
        $attempts = [
            fn () => $schedule->update(['envelope_id' => $other->id]),
            fn () => EnvelopeSchedule::query()->whereKey($schedule->id)->update(['envelope_id' => $other->id]),
            fn () => EnvelopeSchedule::query()->update(['amount' => -1]),
            fn () => EnvelopeSchedule::query()->update(['start_date' => '2026-02-01']),
            fn () => EnvelopeSchedule::query()->increment('amount'),
            fn () => EnvelopeSchedule::query()->insert(['envelope_id' => $other->id, 'amount' => -1]),
            fn () => EnvelopeSchedule::query()->upsert([['id' => $schedule->id, 'amount' => -1]], ['id']),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('An unsafe schedule write succeeded.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(-12345, $schedule->fresh()?->amount);
        $this->assertSame(1, EnvelopeAmountKey::query()->count());
    }

    public function test_changing_start_date_requires_explicit_amount_rebinding(): void
    {
        $schedule = $this->schedule()->fresh();
        $raw = $this->raw($schedule);
        $schedule->start_date = '2026-02-01';
        try {
            $schedule->save();
            $this->fail('Start date changed without rebinding the amount.');
        } catch (LogicException) {
            $this->assertSame($raw, $this->raw($schedule));
        }

        $schedule->amount = $schedule->amount;
        $schedule->save();
        $this->assertNotSame($raw, $this->raw($schedule));
        $this->assertSame(-12345, $schedule->fresh()?->amount);
    }

    public function test_ciphertexts_cannot_move_between_envelopes(): void
    {
        $schedule = $this->schedule();
        $other = $this->user->envelopes()->create(['name' => 'Other', 'type' => 'expense']);
        $copy = $other->schedules()->create(['amount' => -99, 'period' => 'monthly', 'start_date' => '2026-01-01', 'day_of_month' => 31]);
        DB::table('envelope_schedules')->where('id', $copy->id)->update(['amount' => $this->raw($schedule)]);
        app(AmountKeyRing::class)->forget();

        $this->expectException(AmountDecryptionException::class);
        $copy->fresh()->amount;
    }

    public function test_soft_deleted_schedules_participate_in_rotation_and_pruning(): void
    {
        $active = $this->schedule();
        $deleted = $this->schedule(['amount' => -99]);
        $deleted->delete();
        $oldKey = app(AmountKeyRing::class)->key($this->envelope->id, 1, AmountKeyScope::Envelope);
        $profileKey = FinancialProfileKey::query()->sole()->wrapped_key;
        $updatedAt = $active->fresh()->updated_at;
        $this->assertSame(1, $this->envelope->schedules()->count());
        $this->assertSame(2, $this->envelope->schedules()->withTrashed()->count());

        $this->artisan('amounts:rotate-keys', ['--envelope' => [$this->envelope->id]])
            ->expectsOutputToContain('2 amount(s) re-encrypted')->assertSuccessful();
        $this->assertSame(2, AmountCipher::keyVersionOf($this->raw($active)));
        $this->assertSame(2, AmountCipher::keyVersionOf($this->raw($deleted)));
        $this->assertSame($updatedAt->toDateTimeString(), $active->fresh()->updated_at->toDateTimeString());
        $this->assertSame($profileKey, FinancialProfileKey::query()->sole()->wrapped_key);

        // Simulate a request that had loaded the previous key before rotation.
        $context = EnvelopeSchedule::amountContextFor('amount', $deleted->getAttributes(), $this->envelope->id);
        DB::table('envelope_schedules')->where('id', $deleted->id)->update(['amount' => (new AmountCipher)->encrypt(-777, $oldKey, $context)]);
        $this->artisan('amounts:prune-keys')->assertSuccessful();
        $this->assertSame(2, EnvelopeAmountKey::query()->count());
        $this->travel(25)->hours();
        $this->artisan('amounts:prune-keys')->expectsOutputToContain('1 retired key version(s) deleted')->assertSuccessful();
        $this->assertSame([2], EnvelopeAmountKey::query()->pluck('version')->all());
        $this->assertSame(-777, EnvelopeSchedule::withTrashed()->findOrFail($deleted->id)->amount);
        $this->artisan('amounts:verify')->assertSuccessful();
    }

    public function test_rotation_of_all_keys_includes_envelopes_without_initializing_unused_ones(): void
    {
        $schedule = $this->schedule();
        $this->user->envelopes()->create(['name' => 'Unused', 'type' => 'expense']);
        $this->artisan('amounts:rotate-keys', ['--all' => true])->assertSuccessful();
        $this->assertSame(2, AmountCipher::keyVersionOf($this->raw($schedule)));
        $this->assertSame(2, FinancialProfileKey::query()->count());
        $this->assertSame(2, EnvelopeAmountKey::query()->count());
    }

    public function test_failed_envelope_rotation_rolls_back_every_row_and_key(): void
    {
        $first = $this->schedule();
        $bad = $this->schedule();
        DB::table('envelope_schedules')->where('id', $bad->id)->update(['amount' => 'corrupted']);
        $raw = $this->raw($first);
        $this->artisan('amounts:rotate-keys', ['--envelope' => [$this->envelope->id]])
            ->expectsOutputToContain('rotation failed and was rolled back')->assertFailed();
        $this->assertSame($raw, $this->raw($first));
        $this->assertSame(1, EnvelopeAmountKey::query()->count());
        $this->assertNull(EnvelopeAmountKey::query()->sole()->retired_at);
        $this->assertSame(-12345, $first->fresh()?->amount);
    }

    public function test_verification_covers_deleted_schedules_and_supports_explicit_scope_selection(): void
    {
        $schedule = $this->schedule();
        $schedule->delete();
        DB::table('envelope_schedules')->where('id', $schedule->id)->update(['amount' => 'corrupted']);
        $this->artisan('amounts:verify', ['--profile' => [$this->profile->id]])->assertSuccessful();
        $this->artisan('amounts:verify', ['--envelope' => [$this->envelope->id]])
            ->expectsOutputToContain("envelope_schedules #{$schedule->id}")->assertFailed();
        $this->artisan('amounts:verify')->assertFailed();
        $this->artisan('amounts:verify', ['--envelope' => ['invalid']])->assertExitCode(2);
        $this->artisan('amounts:rotate-keys', ['--envelope' => ['invalid']])->assertExitCode(2);
        $this->artisan('amounts:rotate-keys', ['--envelope' => [$this->envelope->id], '--all' => true])->assertExitCode(2);
        $this->artisan('amounts:rotate-keys', ['--envelope' => ['99999999999999999999999999']])->assertExitCode(2);
    }

    public function test_rewrap_preserves_both_scopes_when_the_previous_app_key_is_removed(): void
    {
        $schedule = $this->schedule();
        $oldKey = (string) config('app.key');
        $newKey = 'base64:'.base64_encode(random_bytes(32));
        $this->useAppKey($newKey, [$oldKey]);
        $this->artisan('amounts:rewrap-keys')->expectsOutputToContain('2 amount key(s) re-wrapped')->assertSuccessful();
        $this->useAppKey($newKey, []);
        $this->assertSame(-12345, $schedule->fresh()?->amount);
        $this->artisan('amounts:verify')->assertSuccessful();
    }

    /** @param list<string> $previous */
    private function useAppKey(string $key, array $previous = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        app(AmountKeyRing::class)->forget();
    }

    public function test_a_missing_key_is_reported_and_never_silently_replaced(): void
    {
        $this->schedule();
        EnvelopeAmountKey::query()->delete();
        app(AmountKeyRing::class)->forget();
        $this->artisan('amounts:verify')->expectsOutputToContain('envelope_amount_keys')->assertFailed();
        try {
            $this->schedule();
            $this->fail('A missing key was replaced.');
        } catch (AmountDecryptionException) {
            $this->assertSame(0, EnvelopeAmountKey::query()->count());
            $this->assertSame(1, EnvelopeSchedule::withTrashed()->count());
        }
    }

    public function test_failed_schedule_creation_rolls_back_its_new_key_and_clears_the_memo(): void
    {
        try {
            DB::transaction(function (): void {
                $this->schedule();
                throw new RuntimeException('Simulated outer transaction failure.');
            });
        } catch (RuntimeException) {
            $this->assertSame(0, EnvelopeAmountKey::query()->count());
            $this->assertSame(0, EnvelopeSchedule::withTrashed()->count());
        }
        $this->assertSame(-12345, $this->schedule()->fresh()?->amount);
    }

    /** @return iterable<string, array{bool}> */
    public static function scheduleDeletionStates(): iterable
    {
        yield 'active schedule' => [false];
        yield 'soft-deleted schedule' => [true];
    }

    #[DataProvider('scheduleDeletionStates')]
    public function test_rotation_never_replaces_missing_keys_even_with_a_primed_memo(bool $deleted): void
    {
        $schedule = $this->schedule();
        if ($deleted) {
            $schedule->delete();
        }
        $raw = $this->raw($schedule);
        app(AmountKeyRing::class)->activeKey($this->envelope->id, AmountKeyScope::Envelope);
        EnvelopeAmountKey::query()->delete();

        $this->artisan('amounts:rotate-keys', ['--envelope' => [$this->envelope->id]])
            ->expectsOutputToContain('rotation failed and was rolled back')->assertFailed();
        $this->assertSame(0, EnvelopeAmountKey::query()->count());
        $this->assertSame($raw, $this->raw($schedule));
        $this->assertSame(1, EnvelopeSchedule::withTrashed()->count());
        $this->artisan('amounts:verify')->assertFailed();
    }

    public function test_rotation_cannot_reuse_the_identity_of_a_lost_later_key_version(): void
    {
        $schedule = $this->schedule();
        $this->artisan('amounts:rotate-keys', ['--envelope' => [$this->envelope->id]])->assertSuccessful();
        $raw = $this->raw($schedule);
        $this->assertSame(2, AmountCipher::keyVersionOf($raw));

        // Reproduce a damaged key inventory: v2 was lost and v1 appears
        // active. Merely comparing the ciphertext version with the freshly
        // created v2 would silently commit replacement material.
        EnvelopeAmountKey::query()->where('version', 2)->delete();
        EnvelopeAmountKey::query()->where('version', 1)->update(['retired_at' => null]);
        app(AmountKeyRing::class)->forget();
        $this->artisan('amounts:rotate-keys', ['--envelope' => [$this->envelope->id]])
            ->expectsOutputToContain('rotation failed and was rolled back')->assertFailed();
        $this->assertSame([1], EnvelopeAmountKey::query()->pluck('version')->all());
        $this->assertNull(EnvelopeAmountKey::query()->sole()->retired_at);
        $this->assertSame($raw, $this->raw($schedule));
    }

    public function test_one_missing_key_does_not_skip_later_envelopes_in_the_rotation_batch(): void
    {
        $this->schedule();
        EnvelopeAmountKey::query()->delete();
        $other = $this->user->envelopes()->create(['name' => 'Other', 'type' => 'expense']);
        $schedule = $other->schedules()->create(['amount' => -99, 'period' => 'once', 'start_date' => '2026-01-01']);
        $this->artisan('amounts:rotate-keys', ['--envelope' => [$this->envelope->id, $other->id]])
            ->expectsOutputToContain("Envelope {$this->envelope->id}: rotation failed and was rolled back")
            ->expectsOutputToContain("Envelope {$other->id}: rotated, 1 amount(s) re-encrypted.")
            ->assertFailed();
        $this->assertSame(0, EnvelopeAmountKey::query()->where('envelope_id', $this->envelope->id)->count());
        $this->assertSame(2, AmountCipher::keyVersionOf($this->raw($schedule)));
        $this->assertSame(-99, $schedule->fresh()?->amount);
    }

    public function test_profile_deletion_and_envelope_transfer_do_not_strand_schedule_amounts(): void
    {
        $schedule = $this->schedule();
        $raw = $this->raw($schedule);
        $wrapped = EnvelopeAmountKey::query()->sole()->wrapped_key;
        $this->profile->delete();
        $target = User::factory()->create();
        $this->envelope->transferOwnershipTo($target);
        app(AmountKeyRing::class)->forget();
        $this->assertSame($target->id, $this->envelope->fresh()?->user_id);
        $this->assertSame($wrapped, EnvelopeAmountKey::query()->sole()->wrapped_key);
        $this->assertSame($raw, $this->raw($schedule));
        $this->assertSame(-12345, $schedule->fresh()?->amount);
        $this->artisan('amounts:verify')->assertSuccessful();
    }

    public function test_wrapped_keys_and_key_memos_are_isolated_by_scope(): void
    {
        $schedule = $this->schedule();
        $ring = app(AmountKeyRing::class);
        // Force identical numeric ids to exercise scope separation rather than
        // accidentally passing only because the ids differ.
        $id = $this->envelope->id;
        if (! FinancialProfile::query()->whereKey($id)->exists()) {
            $profile = new FinancialProfile(['name' => 'Matching key id']);
            $profile->id = $id;
            $profile->save();
        }
        $profileKey = $ring->activeKey($id);
        $envelopeKey = $ring->activeKey($id, AmountKeyScope::Envelope);
        $this->assertNotSame($profileKey->bytes(), $envelopeKey->bytes());
        $this->assertSame(AmountKeyScope::Profile, $profileKey->scope);
        $this->assertSame(AmountKeyScope::Envelope, $envelopeKey->scope);
        $wrapped = AmountKeyRing::wrap($envelopeKey);
        $this->assertSame($envelopeKey->bytes(), AmountKeyRing::unwrap($id, 1, $wrapped, AmountKeyScope::Envelope)->bytes());

        try {
            AmountKeyRing::unwrap($id, 1, $wrapped);
            $this->fail('An envelope key was accepted as a profile key.');
        } catch (AmountDecryptionException) {
            $this->addToAssertionCount(1);
        }
        $legacy = Crypt::encryptString(json_encode(['p' => $id, 'v' => 1, 'k' => base64_encode($profileKey->bytes())], JSON_THROW_ON_ERROR));
        $this->assertSame($profileKey->bytes(), AmountKeyRing::unwrap($id, 1, $legacy)->bytes());
        try {
            AmountKeyRing::unwrap($id, 1, $legacy, AmountKeyScope::Envelope);
            $this->fail('A profile key was accepted as an envelope key.');
        } catch (AmountDecryptionException) {
            $this->addToAssertionCount(1);
        }
        $ring->forget($id, AmountKeyScope::Envelope);
        $this->assertSame($profileKey, $ring->activeKey($id));
        $this->assertSame(-12345, $schedule->fresh()?->amount);
    }

    public function test_app_key_health_check_covers_an_envelope_only_database(): void
    {
        $this->schedule();
        $this->profile->delete();
        app(AmountKeyHealthCheck::class)->assertAppKeyCanUnwrap();
        $this->useAppKey('base64:'.base64_encode(random_bytes(32)));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');
        app(AmountKeyHealthCheck::class)->assertAppKeyCanUnwrap();
    }

    public function test_active_schedules_block_envelope_deletion_and_direction_changes_in_both_locales(): void
    {
        $this->schedule();
        foreach (['en', 'fr'] as $locale) {
            $this->user->update(['locale' => $locale]);
            $this->actingAs($this->user)->deleteJson("/envelopes/{$this->envelope->id}")
                ->assertUnprocessable()->assertJsonValidationErrors('envelope');
            $this->actingAs($this->user)->putJson("/envelopes/{$this->envelope->id}", [
                'name' => 'Rent', 'type' => 'revenue', 'color' => '#123456',
            ])->assertUnprocessable()->assertJsonValidationErrors('type');
        }
        $this->assertSame('expense', $this->envelope->fresh()?->type);
        $this->assertSame(1, Envelope::referenced()->count());
    }

    public function test_deleted_schedules_are_purged_before_direction_change_and_cascade_on_envelope_deletion(): void
    {
        $this->schedule()->delete();
        $this->actingAs($this->user)->putJson("/envelopes/{$this->envelope->id}", [
            'name' => 'Rent', 'type' => 'revenue', 'color' => '#123456',
        ])->assertRedirect();
        $this->assertSame(0, EnvelopeSchedule::withTrashed()->count());
        $schedule = $this->schedule(['amount' => 99]);
        $schedule->delete();
        $this->actingAs($this->user)->deleteJson("/envelopes/{$this->envelope->id}")->assertRedirect();
        $this->assertSame(0, EnvelopeSchedule::withTrashed()->count());
        $this->assertSame(0, EnvelopeAmountKey::query()->count());
    }

    public function test_account_cascade_removes_its_envelope_schedules_and_keys(): void
    {
        $this->schedule();
        $this->user->delete();
        $this->assertSame(0, EnvelopeSchedule::withTrashed()->count());
        $this->assertSame(0, EnvelopeAmountKey::query()->count());
    }

    public function test_new_migration_is_reversible_without_touching_existing_profile_data(): void
    {
        $key = FinancialProfileKey::query()->sole()->wrapped_key;
        // Reverse order, like migrate:rollback: the later provenance and
        // reminder FKs depend on envelope_schedules (PostgreSQL enforces it).
        $reminders = require database_path('migrations/2026_11_17_000004_create_schedule_reminders_table.php');
        $provenance = require database_path('migrations/2026_11_17_000003_add_schedule_provenance_to_transactions.php');
        $migration = require database_path('migrations/2026_11_17_000001_create_envelope_schedules_table.php');
        $reminders->down();
        $this->assertFalse(Schema::hasTable('schedule_reminders'));
        $provenance->down();
        $this->assertFalse(Schema::hasColumn('transactions', 'schedule_id'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('envelope_schedules'));
        $this->assertFalse(Schema::hasTable('envelope_amount_keys'));
        $this->assertSame($key, FinancialProfileKey::query()->sole()->wrapped_key);
        $migration->up();
        $provenance->up();
        $reminders->up();
        $this->assertTrue(Schema::hasColumn('transactions', 'schedule_id'));
        $this->assertTrue(Schema::hasTable('schedule_reminders'));
        $this->assertSame(-12345, $this->schedule()->fresh()?->amount);
    }
}
