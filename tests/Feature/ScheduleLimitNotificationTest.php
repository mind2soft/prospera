<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CheckEnvelopeLimit;
use App\Mail\EnvelopeLimitExceeded;
use App\Models\Budget;
use App\Models\Envelope;
use App\Models\EnvelopeSchedule;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use App\Services\EnvelopeLimitChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * "Envelope limit exceeded" for batches of generated rows (issues #15 and
 * #52): ascending-ID prefixes in exact minor units find the crossing even
 * inside one atomic reconciliation, and only spending that grew from the
 * changed rows on notifies.
 */
final class ScheduleLimitNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private FinancialProfile $profile;

    private Envelope $rent;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 6, 10)->startOfDay());
        $this->owner = User::factory()->create();
        $this->profile = FinancialProfile::createOwnedBy($this->owner, isDefault: true);
        $this->rent = $this->owner->envelopes()->create(['name' => 'Rent', 'type' => 'expense']);
        $this->ledger = Ledger::create([
            'profile_id' => $this->profile->id, 'name' => '2026',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'starting_balance' => 0,
        ]);
        $this->ledger->permissions()->create(['user_id' => $this->owner->id, 'permission' => 'owner']);
        $this->actingAs($this->owner);
    }

    /** A published June budget allocating 100.00 on the envelope. */
    private function publishBudget(): Budget
    {
        $budget = Budget::create([
            'profile_id' => $this->profile->id, 'currency' => 'CAD',
            'effective_year' => 2026, 'effective_month' => 6, 'published_at' => now(),
        ]);
        $budget->permissions()->create(['user_id' => $this->owner->id, 'permission' => 'owner']);
        $budget->envelopes()->create(['envelope_id' => $this->rent->id, 'amount' => -100, 'type' => 'fixed']);

        return $budget;
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(array $attributes): EnvelopeSchedule
    {
        $id = $this->postJson("/api/envelopes/{$this->rent->id}/schedules", $attributes)->assertCreated()->json('schedule.id');

        return EnvelopeSchedule::query()->findOrFail($id);
    }

    private function recordManual(float $amount, string $date): void
    {
        $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", [
            'envelope_id' => $this->rent->id, 'amount' => $amount, 'event_at' => $date,
        ])->assertCreated();
    }

    private function claims(): int
    {
        return DB::table('envelope_limit_notifications')->count();
    }

    public function test_an_atomic_batch_that_crosses_on_a_middle_row_notifies_once(): void
    {
        $this->publishBudget();

        // Three Mondays of 60.00 against 100.00: within, then over.
        $this->schedule(['period' => 'weekly', 'weekdays' => [1], 'start_date' => '2026-06-01', 'end_date' => '2026-06-15', 'amount' => 6000]);

        Mail::assertQueued(EnvelopeLimitExceeded::class, 1);
        Mail::assertQueued(EnvelopeLimitExceeded::class, fn (EnvelopeLimitExceeded $mail): bool => $mail->recipientUserId === $this->owner->id
            && $mail->envelopeId === $this->rent->id && $mail->ledgerId === $this->ledger->id);
        $this->assertSame(1, $this->claims());
    }

    public function test_a_crossing_that_moves_onto_a_later_manual_row_notifies(): void
    {
        $this->publishBudget();
        $schedule = $this->schedule(['period' => 'once', 'start_date' => '2026-06-01', 'amount' => 4000]);
        $this->recordManual(50, '2026-06-20');
        Mail::assertNothingQueued(); // 90.00 of 100.00

        $this->patchJson("/api/envelopes/{$this->rent->id}/schedules/{$schedule->id}", ['amount' => 6000])->assertOk();

        Mail::assertQueued(EnvelopeLimitExceeded::class, 1);
        $this->assertSame(1, $this->claims());

        // Already claimed for this budget: a further increase stays silent.
        $this->patchJson("/api/envelopes/{$this->rent->id}/schedules/{$schedule->id}", ['amount' => 9000])->assertOk();
        Mail::assertQueued(EnvelopeLimitExceeded::class, 1);
    }

    public function test_spending_already_over_before_the_change_or_decreasing_never_notifies(): void
    {
        // Over before any budget existed: nothing was checked then.
        $this->recordManual(120, '2026-06-02');
        $this->publishBudget();

        $schedule = $this->schedule(['period' => 'once', 'start_date' => '2026-06-05', 'amount' => 1000]);
        $this->patchJson("/api/envelopes/{$this->rent->id}/schedules/{$schedule->id}", ['amount' => 500])->assertOk();
        $this->deleteJson("/api/envelopes/{$this->rent->id}/schedules/{$schedule->id}")->assertNoContent();

        Mail::assertNothingQueued();
        $this->assertSame(0, $this->claims());
    }

    public function test_revenue_rows_are_never_checked(): void
    {
        $salary = $this->owner->envelopes()->create(['name' => 'Salary', 'type' => 'revenue']);
        $budget = $this->publishBudget();
        $budget->envelopes()->create(['envelope_id' => $salary->id, 'amount' => 1, 'type' => 'fixed']);

        $this->postJson("/api/envelopes/{$salary->id}/schedules", [
            'period' => 'once', 'start_date' => '2026-06-05', 'amount' => 999999,
        ])->assertCreated();

        Mail::assertNothingQueued();
        $this->assertSame(0, $this->claims());
    }

    public function test_mails_wait_for_the_outer_commit_and_a_rollback_releases_the_claim(): void
    {
        $this->publishBudget();
        $row = $this->ledger->transactions()->create(['envelope_id' => $this->rent->id, 'amount' => -150, 'event_at' => '2026-06-03']);

        try {
            DB::transaction(function () use ($row): void {
                app(EnvelopeLimitChecker::class)->checkGroup($this->ledger->id, $this->rent->id, '2026-06', $row->id);
                Mail::assertNothingQueued();

                throw new RuntimeException('outer rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        Mail::assertNothingQueued();
        $this->assertSame(0, $this->claims());

        (new CheckEnvelopeLimit($row->id))->handle();
        Mail::assertQueued(EnvelopeLimitExceeded::class, 1);
    }
}
