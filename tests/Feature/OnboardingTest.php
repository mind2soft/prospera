<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_dashboard_visit_shows_onboarding_once_and_help_can_be_revisited(): void
    {
        $user = User::factory()->create(['onboarding_seen_at' => null]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/onboarding');
        $this->actingAs($user)->get('/onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Onboarding'));

        $this->assertNotNull($user->fresh()->onboarding_seen_at);
        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
        $this->actingAs($user)->get('/onboarding')->assertOk();
    }

    public function test_each_help_topic_is_available_and_unknown_topics_are_not(): void
    {
        $user = User::factory()->create();

        foreach (['envelopes', 'ledgers', 'transactions', 'budgets'] as $topic) {
            $this->actingAs($user)->get("/help/{$topic}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Help')
                    ->where('topic', $topic));
        }

        $this->actingAs($user)->get('/help/unknown')->assertNotFound();
    }

    public function test_progress_is_profile_scoped_and_reports_remaining_setup(): void
    {
        $user = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($user, 'Home');
        $envelopes = collect([
            ['name' => 'Paycheck', 'type' => Envelope::DIRECTION_REVENUE],
            ['name' => 'Housing', 'type' => Envelope::DIRECTION_EXPENSE],
            ['name' => 'Food', 'type' => Envelope::DIRECTION_EXPENSE],
            ['name' => 'Travel', 'type' => Envelope::DIRECTION_EXPENSE],
        ])->map(fn (array $attributes): Envelope => $user->envelopes()->create($attributes + ['color' => '#10b981']));
        $ledger = $this->createLedger($profile, $user);

        foreach ($envelopes->take(2) as $envelope) {
            $this->createTransaction(
                $ledger,
                $envelope,
                now()->subDays(3)->subHour(),
                100,
            );
        }

        $this->actingAs($user)->getJson('/api/onboarding?profile_id='.$profile->id)
            ->assertOk()
            ->assertJsonPath('progress.can_create_ledger', true)
            ->assertJsonPath('progress.history_days_remaining', 27)
            ->assertJsonPath('progress.envelopes_with_transactions', 2)
            ->assertJsonPath('progress.envelopes_missing_transactions', 2)
            ->assertJsonPath('progress.first_budget_created', false)
            ->assertJsonPath('progress.can_create_budget', false);
    }

    public function test_setup_progress_is_private_to_profile_owners(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($owner, 'Home');
        $profile->members()->attach($member->id, ['is_owner' => false]);

        $this->actingAs($member)->getJson('/api/onboarding?profile_id='.$profile->id)
            ->assertForbidden();
    }

    public function test_backdated_event_dates_do_not_shortcut_the_history_period(): void
    {
        $user = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($user, 'Home');
        $envelope = $user->envelopes()->create([
            'name' => 'Paycheck',
            'type' => Envelope::DIRECTION_REVENUE,
            'color' => '#10b981',
        ]);
        $ledger = $this->createLedger($profile, $user);
        $this->createTransaction($ledger, $envelope, now()->subDays(90), 100, now());

        $this->actingAs($user)->getJson('/api/onboarding?profile_id='.$profile->id)
            ->assertOk()
            ->assertJsonPath('progress.history_days', 0)
            ->assertJsonPath('progress.history_days_remaining', 30)
            ->assertJsonPath('progress.can_create_budget', false);
    }

    public function test_budget_history_requires_thirty_full_elapsed_days(): void
    {
        $user = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($user, 'Home');
        $envelope = $user->envelopes()->create([
            'name' => 'Paycheck',
            'type' => Envelope::DIRECTION_REVENUE,
            'color' => '#10b981',
        ]);
        $ledger = $this->createLedger($profile, $user);
        $recordedAt = now()->subDays(30)->addHour();
        $transaction = $this->createTransaction(
            $ledger,
            $envelope,
            now()->subDays(31),
            100,
            $recordedAt,
        );

        $this->actingAs($user)->getJson('/api/onboarding?profile_id='.$profile->id)
            ->assertOk()
            ->assertJsonPath('progress.history_days', 29)
            ->assertJsonPath('progress.history_days_remaining', 1)
            ->assertJsonPath('progress.can_create_budget', false);

        $pastThirtyDays = now()->subDays(30)->subHour();
        $transaction->forceFill([
            'created_at' => $pastThirtyDays,
            'updated_at' => $pastThirtyDays,
        ])->saveQuietly();

        $this->actingAs($user)->getJson('/api/onboarding?profile_id='.$profile->id)
            ->assertOk()
            ->assertJsonPath('progress.history_days', 30)
            ->assertJsonPath('progress.history_days_remaining', 0)
            ->assertJsonPath('progress.can_create_budget', true);
    }

    public function test_a_ledger_cannot_be_created_before_the_envelope_minimum(): void
    {
        $user = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($user, 'Home');

        $this->actingAs($user)->postJson('/api/ledgers', [
            'profile_id' => $profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
            'starting_balance_type' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('profile_id');

        $this->assertSame(0, Ledger::query()->count());
    }

    public function test_the_first_budget_requires_history_and_seeds_signed_recent_totals(): void
    {
        $user = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($user, 'Home');
        $envelopes = collect([
            ['name' => 'Paycheck', 'type' => Envelope::DIRECTION_REVENUE],
            ['name' => 'Housing', 'type' => Envelope::DIRECTION_EXPENSE],
            ['name' => 'Food', 'type' => Envelope::DIRECTION_EXPENSE],
            ['name' => 'Travel', 'type' => Envelope::DIRECTION_EXPENSE],
        ])->map(fn (array $attributes): Envelope => $user->envelopes()->create($attributes + ['color' => '#10b981']));
        $ledger = $this->createLedger($profile, $user);

        $this->actingAs($user)->postJson('/api/budgets', [
            'profile_id' => $profile->id,
            'currency' => 'CAD',
        ])->assertUnprocessable()->assertJsonValidationErrors('budget');

        foreach ($envelopes as $index => $envelope) {
            $this->createTransaction($ledger, $envelope, now()->subDays($index === 0 ? 31 : 4), $index === 0 ? 2400 : -($index * 125));
            if ($index > 0) {
                $this->createTransaction($ledger, $envelope, now()->subDays(2), -50.5);
            } else {
                $this->createTransaction($ledger, $envelope, now()->subDays(4), 50.5);
            }
        }

        $response = $this->actingAs($user)->postJson('/api/budgets', [
            'profile_id' => $profile->id,
            'currency' => 'CAD',
        ])->assertCreated();

        $budget = Budget::query()->findOrFail($response->json('budget.id'));
        $amounts = $budget->envelopes()->get()->mapWithKeys(fn ($assignment): array => [
            $assignment->envelope_id => $assignment->amount,
        ]);

        $this->assertSame(51, $amounts[$envelopes[0]->id]);
        $this->assertSame(-176, $amounts[$envelopes[1]->id]);
        $this->assertSame(-301, $amounts[$envelopes[2]->id]);
        $this->assertSame(-426, $amounts[$envelopes[3]->id]);
        $this->assertSame(4, $amounts->count());
        $this->assertNotNull($profile->fresh()->initial_budget_created_at);

        $budget->delete();
        $this->actingAs($user)->postJson('/api/budgets', [
            'profile_id' => $profile->id,
            'currency' => 'CAD',
        ])->assertCreated();
    }

    private function createLedger(FinancialProfile $profile, User $owner): Ledger
    {
        $ledger = Ledger::create([
            'profile_id' => $profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
        ]);
        $ledger->permissions()->create(['user_id' => $owner->id, 'permission' => 'owner']);

        return $ledger;
    }

    private function createTransaction(
        Ledger $ledger,
        Envelope $envelope,
        CarbonInterface $eventAt,
        int|float $amount,
        ?CarbonInterface $recordedAt = null,
    ): Transaction {
        $transaction = Transaction::create([
            'ledger_id' => $ledger->id,
            'envelope_id' => $envelope->id,
            'amount' => $amount,
            'event_at' => $eventAt,
        ]);
        $recordedAt ??= $eventAt;
        $transaction->forceFill([
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ])->saveQuietly();

        return $transaction;
    }
}
