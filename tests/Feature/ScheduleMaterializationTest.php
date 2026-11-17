<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\FinancialLockSetChanged;
use App\Mail\ContributorTransaction;
use App\Models\Budget;
use App\Models\Envelope;
use App\Models\EnvelopeSchedule;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountKeyManager;
use App\Security\Amounts\AmountKeyRing;
use App\Services\Schedules\ScheduleDeletion;
use App\Services\Schedules\ScheduleReconciler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Schedule materialization and reconciliation (issue #15,
 * docs/scheduled-transactions.md): occurrences land in open ledgers for
 * every month covered by a published budget assigning the envelope —
 * the whole month at once, future dates included — and every trigger
 * converges the ledger to that expected set.
 */
final class ScheduleMaterializationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private FinancialProfile $profile;

    private Envelope $rent;

    private Envelope $salary;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 6, 10)->setTime(9, 30));

        $this->owner = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->owner, isDefault: true);
        $this->rent = $this->owner->envelopes()->create(['name' => 'Rent', 'type' => 'expense']);
        $this->salary = $this->owner->envelopes()->create(['name' => 'Salary', 'type' => 'revenue']);
        $this->owner->envelopes()->create(['name' => 'Food', 'type' => 'expense']);
        $this->owner->envelopes()->create(['name' => 'Gas', 'type' => 'expense']);
        $this->ledger = $this->ledger();
        $this->budget(2026, 6, [$this->rent, $this->salary]);
    }

    private function ledger(string $start = '2026-01-01', string $end = '2026-12-31', ?FinancialProfile $profile = null): Ledger
    {
        $ledger = Ledger::create([
            'profile_id' => ($profile ?? $this->profile)->id, 'name' => substr($start, 0, 4),
            'start_date' => $start, 'end_date' => $end, 'starting_balance' => 0,
        ]);
        $ledger->permissions()->create(['user_id' => $this->owner->id, 'permission' => 'owner']);

        return $ledger;
    }

    /** @param list<Envelope> $envelopes */
    private function budget(int $year, int $month, array $envelopes, bool $published = true): Budget
    {
        $budget = Budget::create([
            'profile_id' => $this->profile->id, 'currency' => 'CAD',
            'effective_year' => $published ? $year : 0, 'effective_month' => $published ? $month : 0,
            'published_at' => $published ? now() : null,
        ]);
        $budget->permissions()->create(['user_id' => $this->owner->id, 'permission' => 'owner']);
        foreach ($envelopes as $envelope) {
            $budget->envelopes()->create([
                'envelope_id' => $envelope->id, 'type' => 'fixed',
                'amount' => $envelope->type === 'expense' ? -100000 : 100000,
            ]);
        }

        return $budget;
    }

    private function url(?EnvelopeSchedule $schedule = null, ?Envelope $envelope = null): string
    {
        return '/api/envelopes/'.($envelope ?? $this->rent)->id.'/schedules'.($schedule === null ? '' : '/'.$schedule->id);
    }

    /** @param array<string, mixed> $attributes */
    private function createSchedule(array $attributes = []): EnvelopeSchedule
    {
        $id = $this->actingAs($this->owner)->postJson($this->url(), $attributes + [
            'period' => 'weekly', 'start_date' => '2026-05-01', 'weekdays' => [1], 'amount' => 12345,
        ])->assertCreated()->json('schedule.id');

        return EnvelopeSchedule::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $attributes */
    private function modelSchedule(array $attributes = []): EnvelopeSchedule
    {
        return $this->rent->schedules()->create($attributes + [
            'amount' => -12345, 'period' => 'monthly', 'start_date' => '2025-01-01', 'day_of_month' => 15,
        ]);
    }

    /** @return array<string, int> generated minor-unit amounts keyed by date */
    private function generated(?Ledger $ledger = null): array
    {
        return Transaction::withTrashed()
            ->where('ledger_id', ($ledger ?? $this->ledger)->id)
            ->whereNotNull('schedule_id')
            ->orderBy('event_at')
            ->get()
            ->mapWithKeys(fn (Transaction $row): array => [$row->event_at->toDateString() => $row->amountMinorUnits()])
            ->all();
    }

    public function test_a_new_schedule_fills_every_covered_occurrence_of_the_whole_month_and_nothing_else(): void
    {
        $schedule = $this->createSchedule();

        // June is covered: every Monday, the past ones and those after
        // today (June 10) alike. May and July have no published budget.
        $this->assertSame([
            '2026-06-01' => -12345, '2026-06-08' => -12345, '2026-06-15' => -12345,
            '2026-06-22' => -12345, '2026-06-29' => -12345,
        ], $this->generated());

        $row = Transaction::query()->where('schedule_id', $schedule->id)->orderBy('event_at')->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertSame('00:00:00', $row->event_at->format('H:i:s'));
        $this->assertSame(-123.45, $row->amount);
        $this->assertSame($this->rent->id, $row->envelope_id);
        $this->assertTrue(AmountCipher::looksEncrypted($row->getRawOriginal('amount')));

        $this->getJson("/api/ledgers/{$this->ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30")
            ->assertOk()
            ->assertJsonCount(5, 'transactions')
            ->assertJsonPath('transactions.0.scheduled', true)
            ->assertJsonPath('transactions.0.amount', -123.45)
            ->assertJsonPath('transactions.0.user.id', null)
            ->assertJsonPath('transactions.0.user.name', 'System');

        Mail::assertNotQueued(ContributorTransaction::class);
    }

    public function test_closed_ledgers_and_profiles_without_coverage_are_never_touched(): void
    {
        $closed = $this->ledger();
        $closed->update(['closed_at' => now()]);
        $otherProfile = FinancialProfile::createOwnedBy($this->owner, 'Other');
        $uncovered = $this->ledger(profile: $otherProfile);

        $this->createSchedule();

        $this->assertCount(5, $this->generated());
        $this->assertSame([], $this->generated($closed));
        $this->assertSame([], $this->generated($uncovered));
    }

    public function test_edits_update_rows_in_place_move_dates_and_preserve_manual_rows(): void
    {
        $schedule = $this->createSchedule();
        $ids = Transaction::query()->where('schedule_id', $schedule->id)->orderBy('id')->pluck('id')->all();
        $manual = $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", [
            'envelope_id' => $this->rent->id, 'amount' => 123.45, 'event_at' => '2026-06-15',
        ])->assertCreated()->json('transaction.id');

        $this->patchJson($this->url($schedule), ['amount' => 1])->assertOk();
        $this->assertSame(array_fill_keys(array_keys($this->generated()), -1), $this->generated());
        $this->assertSame($ids, Transaction::query()->where('schedule_id', $schedule->id)->orderBy('id')->pluck('id')->all());

        $this->patchJson($this->url($schedule), ['period' => 'monthly', 'day_of_month' => 15])->assertOk();
        $this->assertSame(['2026-06-15' => -1], $this->generated());

        // The manual entry on the same day and envelope is independent.
        $this->assertSame(-12345, Transaction::query()->findOrFail($manual)->amountMinorUnits());
        $this->assertNull(Transaction::query()->findOrFail($manual)->schedule_id);
    }

    public function test_deletion_removes_open_rows_keeps_closed_provenance_and_reopening_cleans_up(): void
    {
        $closed = $this->ledger();
        $schedule = $this->createSchedule();
        $this->assertCount(5, $this->generated($closed));
        $this->postJson("/api/ledgers/{$closed->id}/close")->assertOk();

        $this->deleteJson($this->url($schedule))->assertNoContent();

        $this->assertSoftDeleted($schedule);
        $this->assertSame([], $this->generated());
        $this->assertCount(5, $this->generated($closed));
        $this->assertSame(5, Transaction::query()->where('ledger_id', $closed->id)->where('schedule_id', $schedule->id)->count());

        $this->postJson("/api/ledgers/{$closed->id}/reopen")->assertOk();
        $this->assertSame([], $this->generated($closed));
    }

    public function test_schedules_expose_their_generated_entry_count_in_open_ledgers_only(): void
    {
        $closed = $this->ledger();
        $other = $this->ledger();
        $created = $this->actingAs($this->owner)->postJson($this->url(), [
            'period' => 'weekly', 'start_date' => '2026-05-01', 'weekdays' => [1], 'amount' => 12345,
        ])->assertCreated()->assertJsonPath('schedule.open_ledger_entries', 0);
        $schedule = EnvelopeSchedule::query()->findOrFail($created->json('schedule.id'));
        $quiet = $this->createSchedule(['period' => 'once', 'start_date' => '2026-07-01', 'weekdays' => []]);
        $this->postJson("/api/ledgers/{$closed->id}/close")->assertOk();
        // A manual row of the same envelope is not a generated entry.
        $this->postJson("/api/ledgers/{$other->id}/transactions", [
            'envelope_id' => $this->rent->id, 'amount' => 10, 'event_at' => '2026-06-01',
        ])->assertCreated();

        // Five Mondays in June in each of the two open ledgers; the closed
        // ledger's rows are kept as history and never rewritten.
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('schedules.0.id', $schedule->id)
            ->assertJsonPath('schedules.0.open_ledger_entries', 10)
            ->assertJsonPath('schedules.1.id', $quiet->id)
            ->assertJsonPath('schedules.1.open_ledger_entries', 0);
        $this->patchJson($this->url($schedule), ['reminder_days' => 2])->assertOk()
            ->assertJsonPath('schedule.open_ledger_entries', 10);

        $this->postJson("/api/ledgers/{$other->id}/close")->assertOk();
        $this->getJson($this->url())->assertJsonPath('schedules.0.open_ledger_entries', 5);
    }

    public function test_reopening_applies_edits_made_while_the_ledger_was_closed(): void
    {
        $schedule = $this->createSchedule();
        $this->postJson("/api/ledgers/{$this->ledger->id}/close")->assertOk();

        $this->patchJson($this->url($schedule), ['amount' => 500])->assertOk();
        $this->assertSame([-12345], array_values(array_unique($this->generated())));

        $this->postJson("/api/ledgers/{$this->ledger->id}/reopen")->assertOk();
        $this->assertSame([-500], array_values(array_unique($this->generated())));
    }

    public function test_budget_publish_replacement_and_deletion_reconcile_the_profile_ledgers(): void
    {
        Budget::query()->delete();
        $this->createSchedule();
        $this->assertSame([], $this->generated());

        $draft = $this->budget(2026, 6, [$this->rent], published: false);
        $this->postJson("/api/budgets/{$draft->id}/publish", ['month' => '2026-06'])->assertOk();
        $this->assertCount(5, $this->generated());

        // A replacement without the envelope removes the coverage in full.
        $replacement = $this->budget(2026, 6, [$this->salary], published: false);
        $this->postJson("/api/budgets/{$replacement->id}/publish", ['month' => '2026-06'])->assertOk();
        $this->assertSame([], $this->generated());

        $again = $this->budget(2026, 6, [$this->rent], published: false);
        $this->postJson("/api/budgets/{$again->id}/publish", ['month' => '2026-06'])->assertOk();
        $this->assertCount(5, $this->generated());

        $this->deleteJson("/api/budgets/{$again->id}")->assertNoContent();
        $this->assertSame([], $this->generated());
    }

    public function test_creating_a_ledger_materializes_its_covered_occurrences(): void
    {
        $this->modelSchedule();
        $this->assertSame([], $this->generated());

        $id = $this->actingAs($this->owner)->postJson('/api/ledgers', [
            'profile_id' => $this->profile->id, 'name' => 'Second', 'start_date' => '2026-06-01',
            'end_date' => '2026-06-30', 'starting_balance' => 0, 'starting_balance_type' => 'cash',
        ])->assertCreated()->json('ledger.id');

        $this->assertSame(['2026-06-15' => -12345], $this->generated(Ledger::query()->findOrFail($id)));
    }

    public function test_daily_sync_catches_up_idempotently_including_expired_open_ledgers(): void
    {
        $expired = $this->ledger('2025-01-01', '2025-12-31');
        $this->budget(2025, 12, [$this->rent]);
        // Written without the API: no job ran (lost queue, downtime).
        $schedule = $this->modelSchedule();

        $this->artisan('schedules:sync')->expectsOutputToContain('2 inserted, 0 updated, 0 deleted')->assertSuccessful();
        $this->assertSame(['2026-06-15' => -12345], $this->generated());
        $this->assertSame(['2025-12-15' => -12345], $this->generated($expired));
        $ids = Transaction::query()->whereNotNull('schedule_id')->orderBy('id')->pluck('id')->all();

        $this->travel(40)->days();
        $this->artisan('schedules:sync')->expectsOutputToContain('0 inserted, 0 updated, 0 deleted')->assertSuccessful();
        $this->assertSame($ids, Transaction::query()->whereNotNull('schedule_id')->orderBy('id')->pluck('id')->all());

        // A lost row and an unreconciled edit converge on the next run.
        Transaction::query()->whereKey($ids[0])->forceDelete();
        $schedule->fill(['amount' => -777])->save();
        $this->artisan('schedules:sync')->expectsOutputToContain('1 inserted, 1 updated, 0 deleted')->assertSuccessful();
        $this->assertSame(['2026-06-15' => -777], $this->generated());
        $this->assertSame(['2025-12-15' => -777], $this->generated($expired));
    }

    public function test_daily_sync_reports_a_failing_ledger_and_still_reconciles_the_others(): void
    {
        $other = $this->ledger();
        $schedule = $this->modelSchedule();
        $this->artisan('schedules:sync')->assertSuccessful();
        DB::table('transactions')->where('ledger_id', $this->ledger->id)->update(['amount' => 'tampered']);
        $schedule->fill(['amount' => -1])->save();

        $this->artisan('schedules:sync')
            ->expectsOutputToContain("Ledger {$this->ledger->id}: scheduled transactions could not be reconciled")
            ->assertFailed();
        $this->assertSame(['2026-06-15' => -1], $this->generated($other));
    }

    public function test_generated_rows_are_read_only_for_everyone(): void
    {
        $this->createSchedule();
        $row = Transaction::query()->whereNotNull('schedule_id')->firstOrFail();

        $this->deleteJson("/api/transactions/{$row->id}")->assertUnprocessable()->assertJsonValidationErrors('transaction');
        $this->postJson("/api/transactions/{$row->id}/restore")->assertUnprocessable()->assertJsonValidationErrors('transaction');
        $this->assertModelExists($row);
        $this->assertNull($row->fresh()?->deleted_at);

        $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", [
            'envelope_id' => $this->rent->id, 'amount' => 1, 'event_at' => '2026-06-02', 'schedule_id' => $row->schedule_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('schedule_id');

        $this->expectException(LogicException::class);
        $row->forceFill(['schedule_id' => null])->save();
    }

    public function test_provenance_cannot_change_through_bulk_writes_and_generated_rows_are_unique(): void
    {
        $schedule = $this->modelSchedule();
        app(ScheduleReconciler::class)->reconcileLedger($this->ledger->id);
        $row = Transaction::query()->whereNotNull('schedule_id')->firstOrFail();

        try {
            Transaction::query()->whereKey($row->id)->update(['schedule_id' => null]);
            $this->fail('Bulk writes changed generated-row provenance.');
        } catch (LogicException) {
            $this->assertSame($schedule->id, $row->fresh()?->schedule_id);
        }

        // Manual duplicates (no provenance) stay allowed…
        foreach ([1, 2] as $ignored) {
            $this->ledger->transactions()->create(['envelope_id' => $this->rent->id, 'amount' => -1, 'event_at' => '2026-06-15']);
        }
        // …but one schedule occurrence exists once per ledger.
        $this->expectException(QueryException::class);
        (new Transaction)->forceFill([
            'ledger_id' => $this->ledger->id, 'envelope_id' => $this->rent->id,
            'schedule_id' => $schedule->id, 'event_at' => '2026-06-15 00:00:00',
        ])->setAmountMinorUnits(-1)->save();
    }

    public function test_the_provenance_migration_refuses_to_roll_back_over_generated_rows(): void
    {
        $this->modelSchedule();
        app(ScheduleReconciler::class)->reconcileLedger($this->ledger->id);
        $migration = require database_path('migrations/2026_11_17_000003_add_schedule_provenance_to_transactions.php');

        try {
            $migration->down();
            $this->fail('Rolling back dropped generated-row provenance.');
        } catch (RuntimeException) {
            $this->assertTrue(Schema::hasColumn('transactions', 'schedule_id'));
            $this->assertCount(1, $this->generated());
        }
    }

    public function test_reconciliation_follows_the_documented_lock_order(): void
    {
        $this->modelSchedule();
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        app(ScheduleReconciler::class)->reconcileLedger($this->ledger->id);

        $positions = array_map(fn (string $prefix): int => $this->position($queries, $prefix), [
            'select * from "ledgers"',
            'select "id", "effective_year", "effective_month", "published_at" from "budgets"',
            'select "id", "type" from "envelopes"',
            'select * from "envelope_schedules"',
            'insert into "transactions"',
        ]);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringContainsString('order by "id" asc', $queries[$positions[1]]);
        $this->assertStringContainsString('order by "id" asc', $queries[$positions[2]]);
        $this->assertStringNotContainsString('profiles', implode("\n", array_slice($queries, $positions[0])));
    }

    public function test_reconciliation_encrypts_with_fresh_profile_keys_after_waiting(): void
    {
        $this->modelSchedule();
        $stale = new AmountKeyRing(new AmountCipher);
        $stale->activeKey($this->profile->id);
        $manager = app(AmountKeyManager::class);
        $manager->rotate($this->profile->id);
        $this->travel(25)->hours();
        $manager->prune($this->profile->id, now()->subHours(24));
        $this->app->instance(AmountKeyRing::class, $stale);

        app(ScheduleReconciler::class)->reconcileLedger($this->ledger->id);

        $payload = Transaction::query()->whereNotNull('schedule_id')->sole()->getRawOriginal('amount');
        $this->assertIsString($payload);
        $this->assertSame(2, AmountCipher::keyVersionOf($payload));
    }

    public function test_schedule_deletion_locks_ledgers_before_the_envelope(): void
    {
        $schedule = $this->createSchedule();
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $this->deleteJson($this->url($schedule))->assertNoContent();

        // Route binding and request authorization read the envelope first,
        // without a lock; the locked re-read follows the ledger roots.
        $ledgers = $this->position($queries, 'select "id", "closed_at" from "ledgers"');
        $envelope = $this->position($queries, 'select * from "envelopes"', $ledgers);
        $schedule = $this->position($queries, 'select * from "envelope_schedules"', $envelope);
        $delete = $this->position($queries, 'delete from "transactions"');
        $this->assertTrue($ledgers < $envelope && $envelope < $schedule && $schedule < $delete);
    }

    public function test_schedule_deletion_rediscovers_a_ledger_set_that_grew_beneath_the_envelope(): void
    {
        $schedule = $this->createSchedule();
        $late = $this->ledger();
        $attempts = 0;
        DB::connection()->beforeExecuting(function (string $query) use ($late, $schedule, &$attempts): void {
            if (str_starts_with($query, 'select * from "envelope_schedules"') && ++$attempts === 1) {
                // A reconciliation committed rows in a ledger this attempt
                // did not lock.
                (new Transaction)->forceFill([
                    'ledger_id' => $late->id, 'envelope_id' => $this->rent->id,
                    'schedule_id' => $schedule->id, 'event_at' => '2026-06-01 00:00:00',
                ])->setAmountMinorUnits(-12345)->save();
            }
        });

        $this->deleteJson($this->url($schedule))->assertNoContent();

        $this->assertSame(2, $attempts);
        $this->assertSoftDeleted($schedule);
        $this->assertSame(0, Transaction::withTrashed()->where('schedule_id', $schedule->id)->count());
    }

    public function test_exhausted_schedule_deletion_retries_change_nothing(): void
    {
        $schedule = $this->createSchedule();
        $attempts = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempts): void {
            if (str_starts_with($query, 'select * from "envelope_schedules"')) {
                $attempts++;
                throw new FinancialLockSetChanged;
            }
        });

        try {
            app(ScheduleDeletion::class)->delete($this->rent->id, $schedule->id, fn (): bool => true);
            $this->fail('Deletion proceeded without a stable ledger set.');
        } catch (FinancialLockSetChanged) {
            $this->assertSame(3, $attempts);
        }
        $this->assertNotSoftDeleted($schedule);
        $this->assertCount(5, $this->generated());
    }

    /** @param list<string> $queries */
    private function position(array $queries, string $prefix, int $after = -1): int
    {
        foreach ($queries as $index => $query) {
            if ($index > $after && str_starts_with($query, $prefix)) {
                return $index;
            }
        }
        $this->fail('Expected SQL prefix not found: '.$prefix);
    }
}
