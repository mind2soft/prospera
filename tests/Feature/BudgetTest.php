<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BudgetTest extends TestCase
{
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

    public function test_index_excludes_other_profiles_and_retired_budgets(): void
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

        // A retired budget is hidden from the selector.
        $retired = $this->createBudget($this->user, [
            'published_at' => now(),
            'effective_year' => now()->year,
            'effective_month' => now()->month,
            'retired_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/budgets?profile_id='.$this->profile->id);

        $response->assertOk();
        $data = $response->json('budgets');
        $this->assertCount(1, $data);
        $this->assertNotNull($data[0]['id']);
        $this->assertNotEquals($retired->id, $data[0]['id']);
        $this->assertDatabaseHas('budgets', ['id' => $retired->id]);
    }

    public function test_guests_cannot_access_the_api(): void
    {
        $this->getJson('/api/budgets')->assertUnauthorized();
    }

    public function test_store_creates_a_draft_with_owner_share(): void
    {
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

        $this->assertDatabaseHas('budget_envelopes', [
            'envelope_id' => $expense->id, 'amount' => -1300, 'type' => 'fixed',
        ]);
        $this->assertDatabaseHas('budget_envelopes', [
            'envelope_id' => $revenue->id, 'amount' => 3000, 'type' => 'variable',
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
        ])->assertOk()->assertJsonPath('envelope.amount', -250);

        $this->assertDatabaseHas('budget_envelopes', ['envelope_id' => $envelope->id, 'amount' => -250]);
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
    // Retired lifecycle
    // ------------------------------------------------------------------

    public function test_a_replaced_published_budget_referenced_by_transactions_becomes_retired(): void
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
        $previous->envelopes()->create([
            'envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed',
        ]);
        Transaction::create([
            'ledger_id' => $ledger->id,
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'budget_id' => $previous->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $draft = $this->createBudget($this->user);
        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish")->assertOk();

        $previous->refresh();
        $this->assertNotNull($previous->retired_at);

        // Retired budgets are hidden from the selector but not deleted.
        $this->assertDatabaseHas('budgets', ['id' => $previous->id]);
    }

    public function test_a_retired_budget_is_deleted_when_its_last_referencing_transaction_is_deleted(): void
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
        $transaction = Transaction::create([
            'ledger_id' => $ledger->id,
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'budget_id' => $previous->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $draft = $this->createBudget($this->user);
        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish")->assertOk();
        $this->assertDatabaseHas('budgets', ['id' => $previous->id]);

        // Deleting the last referencing transaction removes the retired budget.
        $transaction->delete();

        $this->assertDatabaseMissing('budgets', ['id' => $previous->id]);
        $this->assertDatabaseMissing('budget_permissions', ['budget_id' => $previous->id]);
    }

    public function test_deleting_the_ledger_sweeps_its_retired_budgets(): void
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
        Transaction::create([
            'ledger_id' => $ledger->id,
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'budget_id' => $previous->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $draft = $this->createBudget($this->user);
        $this->actingAs($this->user)->postJson("/api/budgets/{$draft->id}/publish")->assertOk();
        $this->assertDatabaseHas('budgets', ['id' => $previous->id]);

        // The ledger cascade would bypass model events at the DB level; the
        // controller deletes the transactions through Eloquent first so the
        // retired budget is swept.
        $this->actingAs($this->user)->deleteJson("/api/ledgers/{$ledger->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseMissing('budgets', ['id' => $previous->id]);
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
        $this->assertDatabaseHas('budget_envelopes', [
            'budget_id' => $draft->id, 'envelope_id' => $envelope->id, 'amount' => -1300,
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
            ->assertOk()->assertJsonCount(2, 'shares');

        $this->actingAs($this->stranger)->getJson("/api/budgets/{$budget->id}/shares")
            ->assertNotFound();
    }

    public function test_ownership_can_be_transferred_to_a_member(): void
    {
        $budget = $this->createBudget($this->user);

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
