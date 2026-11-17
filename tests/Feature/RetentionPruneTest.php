<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReconcileScheduleLedgers;
use App\Models\Budget;
use App\Models\ContactSubmission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The retention prune (issue #61, docs/retention.md): ledgers are deleted
 * 7 years after their end date, published budgets 7 years past their
 * effective month and drafts 7 years after their creation, exactly as the
 * privacy policy promises.
 */
class RetentionPruneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->user, 'Prune test');
    }

    /**
     * A ledger with an owner share row, one envelope, a transaction and a
     * pending (soft-deleted) transaction to prove bulk deletion includes
     * both.
     */
    private function createLedger(array $attributes = []): Ledger
    {
        $ledger = Ledger::create(array_merge([
            'profile_id' => $this->profile->id,
            'name' => 'Old ledger',
            'start_date' => '2019-01-01 00:00:00',
            'end_date' => '2019-12-31 00:00:00',
            'starting_balance' => 1000,
        ], $attributes));

        $ledger->permissions()->create([
            'user_id' => $this->user->id,
            'permission' => 'owner',
        ]);

        $envelope = $this->user->envelopes()->create([
            'name' => 'Salary'.uniqid(), 'type' => 'revenue', 'color' => '#10b981',
        ]);

        $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'amount' => 10000,
            'event_at' => '2019-06-15 00:00:00',
        ]);

        $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'amount' => 20000,
            'event_at' => '2019-06-16 00:00:00',
            'deleted_at' => now(), // pending deletion, 3-minute undo window
        ]);

        return $ledger;
    }

    /**
     * A published (default) or draft budget with an assignment and its
     * owner share row.
     */
    private function createBudget(array $attributes = []): Budget
    {
        $budget = Budget::create(array_merge([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => 2019,
            'effective_month' => 1,
            'published_at' => now(),
        ], $attributes));

        $budget->permissions()->create([
            'user_id' => $this->user->id,
            'permission' => 'owner',
        ]);

        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent'.uniqid(), 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -200, 'type' => 'fixed',
        ]);

        return $budget;
    }

    private function runPrune(): void
    {
        $this->artisan('retention:prune')->assertExitCode(0);
    }

    // -- Ledgers ---------------------------------------------------------

    public function test_a_ledger_is_kept_through_the_seventh_anniversary_and_deleted_the_day_after(): void
    {
        $ledger = $this->createLedger(); // ends 2019-12-31

        // 2026-12-31 = exactly 7 years after the end date: still kept.
        CarbonImmutable::setTestNow('2026-12-31 12:00:00');
        $this->runPrune();
        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseHas('transactions', ['ledger_id' => $ledger->id]);

        // 2027-01-01 = "the start of 2027": deleted with everything tied to it.
        CarbonImmutable::setTestNow('2027-01-01 00:05:00');
        $this->runPrune();
        $this->assertDatabaseMissing('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_the_boundary_survives_a_leap_day_end_date(): void
    {
        $ledger = $this->createLedger([
            'start_date' => '2028-01-01 00:00:00',
            'end_date' => '2028-02-29 00:00:00', // leap day
        ]);

        // 2035-02-28: the cutoff is 2028-02-28, the leap-day end is later.
        CarbonImmutable::setTestNow('2035-02-28 12:00:00');
        $this->runPrune();
        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id]);

        // 2035-03-01: deleted.
        CarbonImmutable::setTestNow('2035-03-01 00:05:00');
        $this->runPrune();
        $this->assertDatabaseMissing('ledgers', ['id' => $ledger->id]);
    }

    public function test_closed_and_open_ledgers_share_the_rule(): void
    {
        $open = $this->createLedger(['name' => 'Open']);
        $closed = $this->createLedger(['name' => 'Closed', 'closed_at' => now()]);

        CarbonImmutable::setTestNow('2035-01-01 00:05:00'); // end 2019-12-31 long past
        $this->runPrune();

        $this->assertDatabaseMissing('ledgers', ['id' => $open->id]);
        $this->assertDatabaseMissing('ledgers', ['id' => $closed->id]);
    }

    public function test_ledgers_of_shared_profiles_are_pruned_too(): void
    {
        $member = User::factory()->create();
        $this->profile->members()->attach($member->id, ['is_owner' => false]);

        $ledger = $this->createLedger(['name' => 'Shared']);

        CarbonImmutable::setTestNow('2027-01-01 00:05:00');
        $this->runPrune();

        $this->assertDatabaseMissing('ledgers', ['id' => $ledger->id]);
        // The shared profile itself is untouched: retention deletes the
        // period's records, not the profile or its surviving resources.
        $this->assertDatabaseHas('financial_profiles', ['id' => $this->profile->id]);
        $this->assertDatabaseHas('financial_profile_user', ['profile_id' => $this->profile->id, 'user_id' => $member->id]);
    }

    public function test_deleting_a_ledger_leaves_no_collateral_damage(): void
    {
        $ledger = $this->createLedger();

        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $schedule = $envelope->schedules()->create([
            'amount' => -50000, 'start_date' => '2026-01-01', 'period' => 'monthly', 'day_of_month' => 1,
        ]);
        ContactSubmission::create([
            'email' => 'a@b.c', 'subject' => 'Hi', 'message' => 'Hello', 'ip_address' => '127.0.0.1',
        ]);

        CarbonImmutable::setTestNow('2035-01-01 00:05:00');
        $this->runPrune();

        $this->assertDatabaseMissing('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseHas('financial_profiles', ['id' => $this->profile->id]);
        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id]);
        $this->assertDatabaseHas('envelope_schedules', ['id' => $schedule->id]);
        $this->assertDatabaseHas('contact_submissions', ['email' => 'a@b.c']);
        // The profile's amount keys stay: the profile itself is not deleted.
        $this->assertDatabaseHas('financial_profile_keys', ['profile_id' => $this->profile->id]);
    }

    public function test_a_ledger_rechecked_as_ineligible_under_the_lock_is_not_deleted(): void
    {
        $ledger = $this->createLedger();

        CarbonImmutable::setTestNow('2027-01-01 00:05:00');

        // Simulate a concurrent move of the end date between candidate
        // selection and the under-lock re-check.
        Ledger::retrieved(function (): void {
            DB::table('ledgers')->update(['end_date' => '2030-06-30 00:00:00']);
        });

        $this->runPrune();

        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseHas('transactions', ['ledger_id' => $ledger->id]);
    }

    public function test_a_failing_ledger_does_not_block_subsequent_eligible_work(): void
    {
        $first = $this->createLedger(['name' => 'First']);
        $second = $this->createLedger(['name' => 'Second']);

        CarbonImmutable::setTestNow('2027-01-01 00:05:00');

        // Make the first ledger's deletion blow up once.
        $blocking = true;
        Ledger::deleting(function (Ledger $ledger) use ($first, &$blocking): bool {
            if ($ledger->id === $first->id && $blocking) {
                $blocking = false;

                throw new RuntimeException('injected failure');
            }

            return true;
        });

        $this->artisan('retention:prune')->assertExitCode(1); // failures reported

        $this->assertDatabaseHas('ledgers', ['id' => $first->id]); // retried next run
        $this->assertDatabaseMissing('ledgers', ['id' => $second->id]); // not silently skipped
        $this->assertDatabaseCount('transactions', 2); // the first ledger's rows remain

        // The next run retries the failed ledger and succeeds.
        $this->runPrune();
        $this->assertDatabaseMissing('ledgers', ['id' => $first->id]);
    }

    public function test_a_failing_budget_does_not_block_subsequent_eligible_work(): void
    {
        $first = $this->createBudget();
        $second = $this->createBudget();

        CarbonImmutable::setTestNow('2026-02-01 00:05:00'); // effective 2019-01 is 7+ years past

        // Make the first budget's deletion blow up once.
        $blocking = true;
        Budget::deleting(function (Budget $budget) use ($first, &$blocking): bool {
            if ($budget->id === $first->id && $blocking) {
                $blocking = false;

                throw new RuntimeException('injected failure');
            }

            return true;
        });

        $this->artisan('retention:prune')->assertExitCode(1); // failures reported

        $this->assertDatabaseHas('budgets', ['id' => $first->id]); // retried next run
        $this->assertDatabaseMissing('budgets', ['id' => $second->id]); // not silently skipped

        // The next run retries the failed budget and succeeds.
        $this->runPrune();
        $this->assertDatabaseMissing('budgets', ['id' => $first->id]);
    }

    public function test_a_budget_rechecked_as_ineligible_under_the_lock_is_not_deleted(): void
    {
        $budget = $this->createBudget();

        CarbonImmutable::setTestNow('2026-02-01 00:05:00');

        // Simulate a concurrent republish for a recent month between
        // candidate selection and the under-lock re-check.
        Budget::retrieved(function (): void {
            DB::table('budgets')->update(['effective_year' => 2030, 'effective_month' => 6]);
        });

        $this->runPrune();

        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    // -- Budgets ---------------------------------------------------------

    public function test_a_published_budget_is_kept_through_the_seventh_anniversary_of_its_month(): void
    {
        $budget = $this->createBudget(); // effective January 2019, month ends 2019-01-31

        // 2026-01-31 = exactly 7 years past the month end: still kept.
        CarbonImmutable::setTestNow('2026-01-31 12:00:00');
        $this->runPrune();
        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);

        // 2026-02-01: deleted, with its shares and assignments.
        CarbonImmutable::setTestNow('2026-02-01 00:05:00');
        $this->runPrune();
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
        $this->assertDatabaseMissing('budget_envelopes', ['budget_id' => $budget->id]);
        $this->assertDatabaseMissing('budget_permissions', ['budget_id' => $budget->id]);
    }

    public function test_a_published_budget_of_a_leap_month_is_kept_to_the_end_of_february(): void
    {
        $budget = $this->createBudget(['effective_year' => 2028, 'effective_month' => 2]);

        // 2035-02-28: the cutoff is 2028-02-28, the leap month's end (29th) is later.
        CarbonImmutable::setTestNow('2035-02-28 12:00:00');
        $this->runPrune();
        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);

        CarbonImmutable::setTestNow('2035-03-01 00:05:00');
        $this->runPrune();
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_a_draft_budget_expires_seven_years_after_its_creation(): void
    {
        $budget = $this->createBudget(['published_at' => null]);
        DB::table('budgets')->where('id', $budget->id)->update(['created_at' => '2019-01-01 00:00:00']);

        // Created 2019-01-01: kept through 2026-01-01, deleted on 2026-01-02.
        CarbonImmutable::setTestNow('2026-01-01 12:00:00');
        $this->runPrune();
        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);

        CarbonImmutable::setTestNow('2026-01-02 00:05:00');
        $this->runPrune();
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_a_recently_published_budget_is_kept_even_when_created_long_ago(): void
    {
        // Draft created in 2019, published for January 2026: the published
        // rule (7 years past the effective month) governs, not the creation.
        $budget = $this->createBudget([
            'effective_year' => 2026,
            'effective_month' => 1,
            'published_at' => '2026-01-05 09:00:00',
        ]);
        DB::table('budgets')->where('id', $budget->id)->update(['created_at' => '2019-01-01 00:00:00']);

        CarbonImmutable::setTestNow('2026-02-01 00:05:00');
        $this->runPrune();

        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    public function test_deleting_a_published_budget_queues_schedule_reconciliation_for_its_profile(): void
    {
        Queue::fake();
        $this->createBudget();

        CarbonImmutable::setTestNow('2026-02-01 00:05:00');
        $this->runPrune();

        Queue::assertPushed(ReconcileScheduleLedgers::class);
    }

    public function test_deleting_a_draft_budget_queues_no_reconciliation(): void
    {
        Queue::fake();
        $this->createBudget(['published_at' => null]);
        DB::table('budgets')->whereNull('published_at')->update(['created_at' => '2019-01-01 00:00:00']);

        CarbonImmutable::setTestNow('2026-01-02 00:05:00');
        $this->runPrune();

        Queue::assertNotPushed(ReconcileScheduleLedgers::class);
    }

    public function test_nothing_is_due_under_the_default_window(): void
    {
        $this->createLedger();
        $this->createBudget(['effective_year' => 2026, 'effective_month' => 11]);

        CarbonImmutable::setTestNow('2026-11-17 12:00:00'); // nothing is 7 years past
        $this->artisan('retention:prune')->assertExitCode(0);

        $this->assertDatabaseCount('ledgers', 1);
        $this->assertDatabaseCount('budgets', 1);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_the_run_is_idempotent(): void
    {
        $this->createLedger();
        $this->createBudget();

        CarbonImmutable::setTestNow('2035-01-01 00:05:00');
        $this->runPrune();
        $this->runPrune(); // catch-up / re-run: nothing left, no error

        $this->assertDatabaseCount('ledgers', 0);
        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_dry_run_writes_nothing_and_reports_candidates(): void
    {
        $ledger = $this->createLedger();
        $budget = $this->createBudget();

        CarbonImmutable::setTestNow('2035-01-01 00:05:00');

        $this->artisan('retention:prune', ['--dry-run' => true])
            ->expectsOutputToContain('1 ledger(s) and 1 budget(s) would be deleted')
            ->assertExitCode(0);

        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
        $this->assertDatabaseCount('transactions', 2);
    }
}
