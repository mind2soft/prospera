<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CountsQueries;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;

    private User $user;

    private User $member;

    private User $stranger;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->member = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->user, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);
    }

    private function createBudget(User $user, array $attributes = []): Budget
    {
        $budget = Budget::create(array_merge([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => 0,
            'effective_month' => 0,
        ], $attributes));

        $budget->permissions()->create([
            'user_id' => $user->id,
            'permission' => BudgetPermission::PERMISSION_OWNER,
        ]);

        return $budget;
    }

    // ------------------------------------------------------------------
    // List & create
    // ------------------------------------------------------------------

    public function test_index_returns_the_profile_budgets_newest_first(): void
    {
        $old = $this->createBudget($this->user, [
            'published_at' => now()->subDay(),
            'effective_year' => now()->subDay()->year,
            'effective_month' => now()->subDay()->month,
        ]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        $draft = $this->createBudget($this->user);
        $draft->forceFill(['created_at' => now()])->save();

        $response = $this->actingAs($this->user)->getJson('/api/budgets?profile_id='.$this->profile->id);

        $response->assertOk();
        $data = $response->json('budgets');
        $this->assertCount(2, $data);
        $this->assertEquals($draft->id, $data[0]['id']);
        $this->assertEquals($old->id, $data[1]['id']);
        $this->assertEquals('owner', $data[0]['permission']);
        $this->assertNull($data[0]['published_at']);
    }

    public function test_index_excludes_other_profiles_budgets(): void
    {
        $this->createBudget($this->user);

        // Another profile's budget (the member is not involved).
        $otherProfile = FinancialProfile::createOwnedBy($this->stranger, 'Stranger');
        $budget = Budget::create([
            'profile_id' => $otherProfile->id,
            'currency' => 'USD',
            'effective_year' => 0,
            'effective_month' => 0,
        ]);
        $budget->permissions()->create(['user_id' => $this->stranger->id, 'permission' => 'owner']);

        $response = $this->actingAs($this->user)->getJson('/api/budgets?profile_id='.$this->profile->id);

        $response->assertOk();
        $data = $response->json('budgets');
        $this->assertCount(1, $data);
        $this->assertNotNull($data[0]['id']);
        $this->assertNotEquals($budget->id, $data[0]['id']);
    }

    public function test_index_loads_only_the_callers_share_rows(): void
    {
        $shared = $this->createBudget($this->user, [
            'published_at' => now()->subMonth(),
            'effective_year' => now()->subMonth()->year,
            'effective_month' => now()->subMonth()->month,
        ]);
        $shared->permissions()->create(['user_id' => $this->member->id, 'permission' => ResourcePermissions::CONTRIBUTOR]);
        $this->createBudget($this->user);

        $queries = $this->queriesDuring(function () use ($shared): void {
            $budgets = collect($this->actingAs($this->member)->getJson('/api/budgets?profile_id='.$this->profile->id)
                ->assertOk()
                ->json('budgets'))
                ->keyBy('id');

            $this->assertSame(ResourcePermissions::CONTRIBUTOR, $budgets[$shared->id]['permission']);
            $this->assertSame(ResourcePermissions::VIEWER, $budgets->except($shared->id)->first()['permission']);
        });

        $permissionQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "budget_permissions"')));
        $this->assertCount(1, $permissionQueries);
        $this->assertStringContainsString('"user_id" = ?', $permissionQueries[0]);
    }

    public function test_guests_cannot_access_the_api(): void
    {
        $this->getJson('/api/budgets')->assertUnauthorized();
    }

    public function test_store_creates_a_draft_with_owner_share(): void
    {
        $this->createFirstBudgetReadiness();

        $response = $this->actingAs($this->user)->postJson('/api/budgets', [
            'profile_id' => $this->profile->id,
            'currency' => 'cad',
        ]);

        $response->assertCreated();
        $budget = Budget::firstOrFail();
        $this->assertEquals($this->profile->id, $budget->profile_id);
        $this->assertEquals('CAD', $budget->currency);
        $this->assertEquals(0, $budget->effective_year);
        $this->assertEquals(0, $budget->effective_month);
        $this->assertNull($budget->published_at);
        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $budget->id,
            'user_id' => $this->user->id,
            'permission' => 'owner',
        ]);
        $this->assertSame(4, $budget->envelopes()->count());
    }

    private function createFirstBudgetReadiness(): void
    {
        $envelopes = collect([
            ['name' => 'Setup salary', 'type' => 'revenue'],
            ['name' => 'Setup housing', 'type' => 'expense'],
            ['name' => 'Setup food', 'type' => 'expense'],
            ['name' => 'Setup travel', 'type' => 'expense'],
        ])->map(fn (array $attributes): Envelope => $this->user->envelopes()->create($attributes + ['color' => '#10b981']));
        $ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
        ]);
        $ledger->permissions()->create(['user_id' => $this->user->id, 'permission' => 'owner']);

        foreach ($envelopes as $index => $envelope) {
            $historyDate = now()->subDays(31);
            $historyTransaction = Transaction::create([
                'ledger_id' => $ledger->id,
                'envelope_id' => $envelope->id,
                'amount' => $index === 0 ? 1000 : -100,
                'event_at' => $historyDate,
            ]);
            $historyTransaction->forceFill([
                'created_at' => $historyDate,
                'updated_at' => $historyDate,
            ])->saveQuietly();

            $recentDate = now()->subDays(10);
            $recentTransaction = Transaction::create([
                'ledger_id' => $ledger->id,
                'envelope_id' => $envelope->id,
                'amount' => $index === 0 ? 1000 : -100,
                'event_at' => $recentDate,
            ]);
            $recentTransaction->forceFill([
                'created_at' => $recentDate,
                'updated_at' => $recentDate,
            ])->saveQuietly();
        }
    }

    public function test_store_rejects_budgets_in_profiles_the_user_is_not_a_member_of(): void
    {
        $otherProfile = FinancialProfile::createOwnedBy($this->stranger, 'Stranger');

        $this->actingAs($this->user)->postJson('/api/budgets', [
            'profile_id' => $otherProfile->id,
            'currency' => 'CAD',
        ])->assertNotFound();

        $this->actingAs($this->user)->postJson('/api/budgets', [
            'profile_id' => 99999,
            'currency' => 'CAD',
        ])->assertNotFound();
    }

    public function test_store_requires_a_three_letter_currency(): void
    {
        $this->actingAs($this->user)->postJson('/api/budgets', [
            'profile_id' => $this->profile->id,
            'currency' => 'CA',
        ])->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->actingAs($this->user)->postJson('/api/budgets', ['currency' => 'CAD'])
            ->assertUnprocessable()->assertJsonValidationErrors('profile_id');
    }

    public function test_store_authorizes_the_profile_before_validating_the_payload(): void
    {
        // CQ-R2-3: collaborators get a 403, outsiders a 404 — even with an
        // invalid payload; a missing profile is a validation error.
        $this->actingAs($this->member)->postJson('/api/budgets', ['profile_id' => $this->profile->id, 'currency' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->stranger)->postJson('/api/budgets', ['profile_id' => $this->profile->id])
            ->assertNotFound();
        $this->actingAs($this->user)->postJson('/api/budgets', ['currency' => 'CAD'])
            ->assertUnprocessable()->assertJsonValidationErrors('profile_id');
        $this->assertSame(0, Budget::query()->count());
    }

    public function test_store_rejects_a_second_draft_per_profile(): void
    {
        $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson('/api/budgets', [
            'profile_id' => $this->profile->id,
            'currency' => 'USD',
        ])->assertUnprocessable()->assertJsonValidationErrors('budget');
    }

    // ------------------------------------------------------------------
    // Show & authorization
    // ------------------------------------------------------------------

    public function test_show_returns_envelopes_and_shares_for_owner(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Groceries', 'type' => 'expense', 'color' => '#10b981',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -800, 'type' => 'variable',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/budgets/{$budget->id}");

        $response->assertOk()
            ->assertJsonPath('budget.id', $budget->id)
            ->assertJsonPath('budget.envelopes.0.name', 'Groceries')
            ->assertJsonPath('budget.envelopes.0.amount', -800)
            ->assertJsonPath('budget.shares.0.permission', 'owner');
    }

    public function test_users_outside_the_profile_get_a_404(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->stranger)->getJson("/api/budgets/{$budget->id}")
            ->assertNotFound();
    }

    public function test_profile_members_have_implicit_read_only_visibility(): void
    {
        $budget = $this->createBudget($this->user);

        // Members can view without a share row...
        $this->actingAs($this->member)->getJson("/api/budgets/{$budget->id}")
            ->assertOk()->assertJsonPath('budget.permission', 'viewer');

        // ...but cannot mutate.
        $this->actingAs($this->member)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => 1, 'type' => 'fixed', 'amount' => 100,
        ])->assertForbidden();
        $this->actingAs($this->member)->postJson("/api/budgets/{$budget->id}/publish")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_owner_can_delete_a_budget(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
        $this->assertDatabaseMissing('budget_permissions', ['budget_id' => $budget->id]);
        $this->assertDatabaseMissing('budget_envelopes', ['budget_id' => $budget->id]);
    }

    public function test_only_the_owner_can_delete_a_budget(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->member)->deleteJson("/api/budgets/{$budget->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    // ------------------------------------------------------------------
    // Envelope assignments
    // ------------------------------------------------------------------

    public function test_add_envelope_stores_signed_amounts(): void
    {
        $budget = $this->createBudget($this->user);
        $expense = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $revenue = $this->user->envelopes()->create([
            'name' => 'Salary', 'type' => 'revenue', 'color' => '#10b981',
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $expense->id, 'type' => 'fixed', 'amount' => 1300,
        ])->assertCreated();

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $revenue->id, 'type' => 'variable', 'amount' => 3000,
        ])->assertCreated();

        $this->assertEncryptedAmount(BudgetEnvelope::class, ['envelope_id' => $expense->id], 'amount', -1300);
        $this->assertDatabaseHas('budget_envelopes', [
            'envelope_id' => $expense->id, 'type' => 'fixed',
        ]);
        $this->assertEncryptedAmount(BudgetEnvelope::class, ['envelope_id' => $revenue->id], 'amount', 3000);
        $this->assertDatabaseHas('budget_envelopes', [
            'envelope_id' => $revenue->id, 'type' => 'variable',
        ]);
    }

    public function test_add_envelope_rejects_metadata_only_envelopes_of_profile_members(): void
    {
        $budget = $this->createBudget($this->user);
        $membersEnvelope = $this->member->envelopes()->create([
            'name' => 'Partner Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $membersEnvelope->id, 'type' => 'fixed', 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('envelope_id');
    }

    public function test_add_envelope_allows_profile_members_envelopes_shared_as_contributor(): void
    {
        $budget = $this->createBudget($this->user);
        $membersEnvelope = $this->member->envelopes()->create([
            'name' => 'Partner Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $membersEnvelope->permissions()->create([
            'user_id' => $this->user->id, 'permission' => ResourcePermissions::CONTRIBUTOR,
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $membersEnvelope->id, 'type' => 'fixed', 'amount' => 100,
        ])->assertCreated();
    }

    public function test_profile_collaborators_cannot_create_budgets(): void
    {
        $this->actingAs($this->member)->postJson('/api/budgets', [
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
        ])->assertForbidden();
    }

    public function test_add_envelope_rejects_amounts_that_are_not_positive_integers(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        foreach ([-100, 0, 10.5, 'abc'] as $amount) {
            $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
                'envelope_id' => $envelope->id, 'type' => 'fixed', 'amount' => $amount,
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
    }

    public function test_add_envelope_rejects_invisible_envelopes(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->stranger->envelopes()->create([
            'name' => 'Theirs', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $envelope->id, 'type' => 'fixed', 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('envelope_id');
    }

    public function test_add_envelope_rejects_duplicates(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $envelope->id, 'type' => 'fixed', 'amount' => 100,
        ])->assertCreated();

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $envelope->id, 'type' => 'fixed', 'amount' => 200,
        ])->assertUnprocessable()->assertJsonValidationErrors('envelope_id');
    }

    public function test_envelopes_cannot_be_modified_once_published(): void
    {
        $budget = $this->createBudget($this->user, [
            'published_at' => now(),
            'effective_year' => now()->year,
            'effective_month' => now()->month,
        ]);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $envelope->id, 'type' => 'fixed', 'amount' => 100,
        ])->assertUnprocessable();

        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}", [
            'amount' => 200,
        ])->assertUnprocessable();

        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}")
            ->assertUnprocessable();
    }

    /**
     * Simulate a publish committing right after the request's route binding
     * read the budget as a draft (RES-8): the first retrieval of the budget
     * marks it published in the database behind the loaded model's back.
     */
    private function publishConcurrentlyAfterRouteBinding(Budget $budget): void
    {
        $fired = false;

        Budget::retrieved(function (Budget $retrieved) use ($budget, &$fired): void {
            if ($fired || $retrieved->id !== $budget->id) {
                return;
            }

            $fired = true;

            DB::table('budgets')->where('id', $budget->id)->update([
                'published_at' => now(),
                'effective_year' => now()->year,
                'effective_month' => now()->month,
            ]);
        });
    }

    public function test_assignment_edits_re_check_the_draft_state_under_a_lock(): void
    {
        $budget = $this->createBudget($this->user);
        $rent = $this->user->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e']);
        $food = $this->user->envelopes()->create(['name' => 'Food', 'type' => 'expense', 'color' => '#f43f5e']);
        $budget->envelopes()->create(['envelope_id' => $rent->id, 'amount' => -100, 'type' => 'fixed']);

        $this->publishConcurrentlyAfterRouteBinding($budget);
        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $food->id, 'type' => 'fixed', 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('budget');
        $this->assertDatabaseMissing('budget_envelopes', ['envelope_id' => $food->id]);

        DB::table('budgets')->where('id', $budget->id)->update(['published_at' => null, 'effective_year' => 0, 'effective_month' => 0]);
        Budget::flushEventListeners();
        $this->publishConcurrentlyAfterRouteBinding($budget);
        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$rent->id}", [
            'amount' => 999, 'expected_revision' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('budget');
        $this->assertEncryptedAmount(BudgetEnvelope::class, ['envelope_id' => $rent->id], 'amount', -100);

        DB::table('budgets')->where('id', $budget->id)->update(['published_at' => null, 'effective_year' => 0, 'effective_month' => 0]);
        Budget::flushEventListeners();
        $this->publishConcurrentlyAfterRouteBinding($budget);
        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}/envelopes/{$rent->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('budget');
        $this->assertDatabaseHas('budget_envelopes', ['envelope_id' => $rent->id]);
    }

    public function test_a_concurrent_publish_is_not_run_twice(): void
    {
        $budget = $this->createBudget($this->user);

        $this->publishConcurrentlyAfterRouteBinding($budget);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors('budget');
    }

    public function test_a_duplicate_add_losing_the_race_is_a_validation_error(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e']);

        // A concurrent request inserts the same assignment between the
        // duplicate check and the insert.
        BudgetEnvelope::creating(function (BudgetEnvelope $assignment) use ($budget, $envelope): void {
            DB::table('budget_envelopes')->insert([
                'budget_id' => $budget->id,
                'envelope_id' => $envelope->id,
                'amount' => 'concurrent',
                'type' => 'fixed',
            ]);
        });

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/envelopes", [
            'envelope_id' => $envelope->id, 'type' => 'fixed', 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('envelope_id');
    }

    public function test_update_envelope_amount(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);

        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}", [
            'amount' => 250,
            'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('envelope.amount', -250)
            ->assertJsonPath('envelope.revision', 2);

        $this->assertEncryptedAmount(BudgetEnvelope::class, ['envelope_id' => $envelope->id], 'amount', -250);
    }

    public function test_update_envelope_requires_the_revision_the_edit_was_made_against(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);

        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}", [
            'amount' => 250,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
    }

    /**
     * Two writers editing against the same revision (two tabs, or a stale
     * autosave racing a newer edit): the first wins, the second is refused
     * without changing data instead of silently overwriting it (#78).
     */
    public function test_update_envelope_refuses_a_stale_revision(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $assignment = $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);

        // The first writer lands: the assignment now lives at revision 2.
        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}", [
            'amount' => 250, 'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('envelope.revision', 2);

        // The second writer still edits against revision 1: refused, with
        // the current assignment in the response so it can reconcile.
        $response = $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}", [
            'amount' => 999, 'expected_revision' => 1,
        ]);
        $response->assertStatus(409)
            ->assertJsonPath('envelope.amount', -250)
            ->assertJsonPath('envelope.revision', 2);

        $this->assertEncryptedAmount(BudgetEnvelope::class, ['envelope_id' => $envelope->id], 'amount', -250);
        $this->assertSame(2, $assignment->fresh()->revision);
    }

    public function test_update_envelope_rejects_nonsense_revisions(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);

        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}", [
            'amount' => 250, 'expected_revision' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}", [
            'amount' => 250, 'expected_revision' => 'two',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
    }

    public function test_remove_envelope(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}/envelopes/{$envelope->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('budget_envelopes', ['budget_id' => $budget->id]);
    }

    // ------------------------------------------------------------------
    // Publish
    // ------------------------------------------------------------------

    public function test_publish_stamps_effective_year_and_month(): void
    {
        $budget = $this->createBudget($this->user);

        $response = $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/publish");

        $response->assertOk();
        $budget->refresh();
        $this->assertNotNull($budget->published_at);
        $this->assertEquals(now()->year, $budget->effective_year);
        $this->assertEquals(now()->month, $budget->effective_month);
    }

    public function test_publish_uses_the_users_time_zone_for_the_current_month(): void
    {
        // 2026-11-30 22:30 UTC is already 1 December in Sydney.
        Carbon::setTestNow(Carbon::parse('2026-11-30 22:30:00', 'UTC'));

        try {
            $november = $this->createBudget($this->user, [
                'published_at' => now(),
                'effective_year' => 2026,
                'effective_month' => 11,
            ]);
            $draft = $this->createBudget($this->user);

            $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish", [
                'timezone' => 'Australia/Sydney',
                'month' => '2026-12',
            ])->assertOk()
                ->assertJsonPath('budget.effective_year', 2026)
                ->assertJsonPath('budget.effective_month', 12);

            $this->assertDatabaseHas('budgets', ['id' => $november->id, 'effective_month' => 11]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_publish_refuses_a_month_other_than_the_users_current_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-30 22:30:00', 'UTC'));

        try {
            $november = $this->createBudget($this->user, [
                'published_at' => now(),
                'effective_year' => 2026,
                'effective_month' => 11,
            ]);
            $draft = $this->createBudget($this->user);

            // The client means December; without its time zone the server's
            // month (November) applies — refused rather than replacing
            // November's budget.
            $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish", [
                'month' => '2026-12',
            ])->assertUnprocessable()->assertJsonValidationErrors('month');

            $this->assertDatabaseHas('budgets', ['id' => $november->id]);
            $this->assertDatabaseHas('budgets', ['id' => $draft->id, 'published_at' => null]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_publish_rejects_unknown_time_zones(): void
    {
        $draft = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish", [
            'timezone' => 'Mars/Olympus_Mons',
        ])->assertUnprocessable()->assertJsonValidationErrors('timezone');

        $this->assertDatabaseHas('budgets', ['id' => $draft->id, 'published_at' => null]);
    }

    public function test_publishing_twice_is_rejected(): void
    {
        $budget = $this->createBudget($this->user);
        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/publish")->assertOk();

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors('budget');
    }

    public function test_publishing_replaces_the_published_budget_for_the_same_month(): void
    {
        $now = now();
        $previous = $this->createBudget($this->user, [
            'published_at' => $now,
            'effective_year' => $now->year,
            'effective_month' => $now->month,
        ]);
        $draft = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish")->assertOk();

        $this->assertDatabaseMissing('budgets', ['id' => $previous->id]);
        $this->assertDatabaseHas('budgets', ['id' => $draft->id, 'effective_month' => $now->month]);
    }

    public function test_draft_creation_and_publish_lock_the_profile_row(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $budget = $this->createBudget($this->user);
        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/publish")->assertOk();

        // The one-draft / one-published-per-month read-then-write is guarded
        // by a pessimistic lock on the parent profile row inside the
        // transaction. SQLite strips the FOR UPDATE modifier, so the strict
        // assertion is skipped there (see report — MySQL runs the locked
        // select).
        $profileSelects = collect($queries)->filter(
            fn (string $sql) => str_contains($sql, 'from "financial_profiles"') && str_contains($sql, 'where'),
        );
        $this->assertGreaterThan(0, $profileSelects->count());

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->assertTrue($profileSelects->contains(
                fn (string $sql) => str_contains(strtolower($sql), 'for update'),
            ));
        }
    }

    public function test_publishing_does_not_touch_other_months(): void
    {
        $now = now();
        $previousMonth = $now->copy()->startOfMonth()->subMonth();
        $previous = $this->createBudget($this->user, [
            'published_at' => $previousMonth,
            'effective_year' => $previousMonth->year,
            'effective_month' => $previousMonth->month,
        ]);
        $draft = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish")->assertOk();

        $this->assertDatabaseHas('budgets', ['id' => $previous->id]);
    }

    // ------------------------------------------------------------------
    // Revisions
    // ------------------------------------------------------------------

    public function test_publishing_replaces_a_published_budget_even_with_recorded_transactions(): void
    {
        $now = now();
        $previous = $this->createBudget($this->user, [
            'published_at' => $now,
            'effective_year' => $now->year,
            'effective_month' => $now->month,
        ]);
        $ledger = $this->createLedger();
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $transaction = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $draft = $this->createBudget($this->user);
        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish")->assertOk();

        // Transactions do not reference budgets — the replaced budget is
        // deleted immediately and the transaction is unaffected.
        $this->assertDatabaseMissing('budgets', ['id' => $previous->id]);
        $this->assertDatabaseMissing('budget_permissions', ['budget_id' => $previous->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    // ------------------------------------------------------------------
    // Clone
    // ------------------------------------------------------------------

    public function test_clone_creates_a_draft_copy_with_envelopes(): void
    {
        $budget = $this->createBudget($this->user, [
            'published_at' => now(),
            'effective_year' => now()->year,
            'effective_month' => now()->month,
        ]);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -1300, 'type' => 'fixed',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/clone", [
            'currency' => 'EUR',
        ]);

        $response->assertCreated();
        $draft = Budget::whereNull('published_at')->firstOrFail();
        $this->assertEquals('EUR', $draft->currency);
        $this->assertEncryptedAmount(BudgetEnvelope::class, ['budget_id' => $draft->id, 'envelope_id' => $envelope->id], 'amount', -1300);
        $this->assertDatabaseHas('budget_envelopes', [
            'budget_id' => $draft->id, 'envelope_id' => $envelope->id,
        ]);
        $this->assertDatabaseHas('budget_envelopes', [
            'budget_id' => $budget->id, 'envelope_id' => $envelope->id,
        ]);
    }

    public function test_clone_inherits_the_currency_by_default(): void
    {
        $budget = $this->createBudget($this->user, [
            'published_at' => now(),
            'effective_year' => now()->year,
            'effective_month' => now()->month,
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/clone")
            ->assertCreated();

        $this->assertDatabaseHas('budgets', ['currency' => 'CAD', 'published_at' => null]);
    }

    public function test_clone_is_rejected_for_drafts_or_when_a_draft_exists(): void
    {
        $draft = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/clone")
            ->assertUnprocessable();

        $published = $this->createBudget($this->user, [
            'published_at' => now(),
            'effective_year' => now()->year,
            'effective_month' => now()->month,
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$published->id}/clone")
            ->assertUnprocessable()->assertJsonValidationErrors('budget');
    }

    // ------------------------------------------------------------------
    // Shares
    // ------------------------------------------------------------------

    public function test_owner_can_assign_permissions_to_profile_members(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/shares", [
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ])->assertCreated();

        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $budget->id,
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);
    }

    public function test_shares_must_target_profile_members(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/shares", [
            'user_id' => $this->stranger->id,
            'permission' => 'viewer',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        // Sharing with the owner themselves is rejected (they already hold
        // the owner row).
        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/shares", [
            'user_id' => $this->user->id,
            'permission' => 'viewer',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_the_owner_permission_cannot_be_granted(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/shares", [
            'user_id' => $this->member->id,
            'permission' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors('permission');
    }

    public function test_owner_can_change_and_revoke_member_permissions(): void
    {
        $budget = $this->createBudget($this->user);
        $budget->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => BudgetPermission::PERMISSION_VIEWER,
        ]);

        $this->actingAs($this->user)->patchJson("/api/budgets/{$budget->id}/shares/{$this->member->id}", [
            'permission' => 'contributor',
        ])->assertOk();

        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $budget->id,
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}/shares/{$this->member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('budget_permissions', ['budget_id' => $budget->id, 'user_id' => $this->member->id]);
    }

    public function test_owner_share_cannot_be_removed(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}/shares/{$this->user->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('budget_permissions', ['budget_id' => $budget->id, 'user_id' => $this->user->id]);
    }

    public function test_shares_are_visible_to_profile_members(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->member)->getJson("/api/budgets/{$budget->id}/shares")
            ->assertOk()->assertJsonCount(2, 'shares')
            ->assertJsonPath('shares.0.permission', 'owner')
            ->assertJsonPath('shares.0.explicit', true)
            // Members without a share row are implicit viewers and cannot
            // be revoked (the Share drawer hides the revoke action).
            ->assertJsonPath('shares.1.permission', 'viewer')
            ->assertJsonPath('shares.1.explicit', false);

        $this->actingAs($this->stranger)->getJson("/api/budgets/{$budget->id}/shares")
            ->assertNotFound();
    }

    public function test_implicit_viewers_cannot_be_revoked(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}/shares/{$this->member->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('budget_permissions', 1);
    }

    public function test_ownership_can_be_transferred_to_a_member(): void
    {
        // A published budget: drafts stay with the profile owner (below).
        $budget = $this->createBudget($this->user, [
            'published_at' => now(),
            'effective_year' => now()->year,
            'effective_month' => now()->month,
        ]);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertOk();

        $this->assertSame(ResourcePermissions::OWNER, $budget->fresh()->permissionFor($this->member));
        $this->assertNotSame(ResourcePermissions::OWNER, $budget->fresh()->permissionFor($this->user));

        // The previous owner becomes a contributor.
        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $budget->id,
            'user_id' => $this->user->id,
            'permission' => 'contributor',
        ]);
    }

    public function test_a_draft_cannot_be_handed_to_a_collaborator(): void
    {
        // WF-R2-1: the collaborator could not publish it (profile ownership
        // is required) and the profile owner could neither publish, delete
        // nor replace it (single draft slot).
        $draft = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id' => 'A draft budget can only be owned by the profile owner.']);

        $this->assertSame(ResourcePermissions::OWNER, $draft->fresh()->permissionFor($this->user));
        $this->assertSame(ResourcePermissions::VIEWER, $draft->fresh()->permissionFor($this->member));
        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish")->assertOk();
    }

    public function test_a_draft_held_by_a_collaborator_can_be_handed_back_to_the_profile_owner(): void
    {
        // A draft stranded with a collaborator before the rule above.
        $draft = $this->createBudget($this->member);

        $this->actingAs($this->member)->postJson("/api/budgets/{$draft->id}/transfer-ownership", [
            'user_id' => $this->user->id,
        ])->assertOk();

        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish")->assertOk();
    }

    public function test_the_migration_hands_stranded_drafts_back_to_the_profile_owner(): void
    {
        $stranded = $this->createBudget($this->member);
        $published = $this->createBudget($this->member, [
            'published_at' => now(),
            'effective_year' => now()->year,
            'effective_month' => now()->month,
        ]);
        $otherProfile = FinancialProfile::createOwnedBy($this->stranger, 'Stranger');
        $healthy = Budget::create(['profile_id' => $otherProfile->id, 'currency' => 'CAD', 'effective_year' => 0, 'effective_month' => 0]);
        $healthy->permissions()->create(['user_id' => $this->stranger->id, 'permission' => ResourcePermissions::OWNER]);

        $migration = require database_path('migrations/2026_11_12_000001_hand_stranded_drafts_to_profile_owners.php');
        $migration->up();

        $this->assertSame(ResourcePermissions::OWNER, $stranded->fresh()->permissionFor($this->user));
        $this->assertSame(ResourcePermissions::CONTRIBUTOR, $stranded->fresh()->permissionFor($this->member));
        $this->assertSame(1, $stranded->permissions()->where('permission', ResourcePermissions::OWNER)->count());
        // Published budgets keep their owner; healthy drafts are untouched.
        $this->assertSame(ResourcePermissions::OWNER, $published->fresh()->permissionFor($this->member));
        $this->assertSame(ResourcePermissions::OWNER, $healthy->fresh()->permissionFor($this->stranger));
        $this->assertSame(1, $healthy->permissions()->count());
    }

    public function test_transfer_ownership_requires_a_member_target(): void
    {
        $budget = $this->createBudget($this->user);

        $this->actingAs($this->user)->postJson("/api/budgets/{$budget->id}/transfer-ownership", [
            'user_id' => $this->stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->actingAs($this->member)->postJson("/api/budgets/{$budget->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Envelope deletion is blocked while referenced by budgets
    // ------------------------------------------------------------------

    public function test_referenced_envelopes_cannot_be_deleted(): void
    {
        $budget = $this->createBudget($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);

        $this->actingAs($this->user)->delete('/envelopes/'.$envelope->id)
            ->assertSessionHasErrors('envelope');

        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id]);
    }

    private function createLedger(): Ledger
    {
        $ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
        ]);
        $ledger->permissions()->create(['user_id' => $this->user->id, 'permission' => 'owner']);

        return $ledger;
    }
}
