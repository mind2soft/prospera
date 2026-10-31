<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Enveloppe;
use App\Models\EnveloppePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
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

    private function createLedger(User $user, array $attributes = []): Ledger
    {
        $ledger = Ledger::create(array_merge([
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 5000,
        ], $attributes));

        $ledger->permissions()->create([
            'user_id' => $user->id,
            'permission' => 'owner',
        ]);

        return $ledger;
    }

    /**
     * A published budget for the profile with one expense and one revenue
     * enveloppe, assigned amounts of 100 (revenue) and 200 (expense).
     */
    private function createPublishedBudget(User $user, bool $published = true): Budget
    {
        $budget = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => now()->year,
            'effective_month' => now()->month,
            'published_at' => $published ? now() : null,
        ]);

        $budget->permissions()->create([
            'user_id' => $user->id,
            'permission' => BudgetPermission::PERMISSION_OWNER,
        ]);

        $revenue = $user->enveloppes()->create([
            'name' => 'Salary', 'type' => 'revenue', 'color' => '#10b981',
        ]);
        $expense = $user->enveloppes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        $budget->enveloppes()->create([
            'enveloppe_id' => $revenue->id, 'amount' => 100, 'type' => 'fixed',
        ]);
        $budget->enveloppes()->create([
            'enveloppe_id' => $expense->id, 'amount' => -200, 'type' => 'fixed',
        ]);

        return $budget;
    }

    // ------------------------------------------------------------------
    // List & create
    // ------------------------------------------------------------------

    public function test_guests_cannot_access_the_ledger_api(): void
    {
        $this->getJson('/api/ledgers')->assertUnauthorized();
    }

    public function test_index_returns_only_the_profile_ledgers(): void
    {
        $this->createLedger($this->user);

        // A stranger's profile ledger is not visible.
        $otherProfile = FinancialProfile::createOwnedBy($this->stranger, 'Stranger');
        $theirs = Ledger::create([
            'profile_id' => $otherProfile->id,
            'name' => 'Theirs',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 0,
        ]);
        $theirs->permissions()->create(['user_id' => $this->stranger->id, 'permission' => 'owner']);

        $response = $this->actingAs($this->user)->getJson('/api/ledgers?profile_id='.$this->profile->id);

        $response->assertOk();
        $data = $response->json('ledgers');
        $this->assertCount(1, $data);
        $this->assertEquals('2026', $data[0]['name']);
        $this->assertEquals('open', $data[0]['status']);
        $this->assertNull($data[0]['closed_at']);
        $this->assertEquals('owner', $data[0]['permission']);
    }

    public function test_profile_members_see_ledgers_read_only(): void
    {
        $ledger = $this->createLedger($this->user);

        $this->actingAs($this->member)->getJson('/api/ledgers?profile_id='.$this->profile->id)
            ->assertOk()->assertJsonPath('ledgers.0.permission', 'viewer');

        $this->actingAs($this->member)->postJson("/api/ledgers/{$ledger->id}/close")
            ->assertForbidden();
        $this->actingAs($this->member)->deleteJson("/api/ledgers/{$ledger->id}")
            ->assertForbidden();
    }

    public function test_store_creates_a_ledger_with_an_owner_share(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/ledgers', [
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 5000,
            'starting_balance_type' => 'cash',
        ]);

        $response->assertCreated();
        $ledger = Ledger::firstOrFail();
        $this->assertEquals($this->profile->id, $ledger->profile_id);
        $this->assertEquals('2026', $ledger->name);
        $this->assertEquals(5000, $ledger->starting_balance);
        $this->assertNull($ledger->closed_at);
        $this->assertDatabaseHas('ledger_permissions', [
            'ledger_id' => $ledger->id,
            'user_id' => $this->user->id,
            'permission' => 'owner',
        ]);
    }

    public function test_store_supports_a_debt_starting_balance(): void
    {
        $this->actingAs($this->user)->postJson('/api/ledgers', [
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 500,
            'starting_balance_type' => 'debt',
        ])->assertCreated();

        $this->assertDatabaseHas('ledgers', [
            'profile_id' => $this->profile->id,
            'starting_balance' => -500,
        ]);
    }

    public function test_store_supports_a_zero_starting_balance(): void
    {
        $this->actingAs($this->user)->postJson('/api/ledgers', [
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 0,
            'starting_balance_type' => 'cash',
        ])->assertCreated();

        $this->assertDatabaseHas('ledgers', [
            'profile_id' => $this->profile->id,
            'starting_balance' => 0,
        ]);
    }

    public function test_store_requires_valid_input(): void
    {
        $base = [
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 5000,
            'starting_balance_type' => 'cash',
        ];

        $this->actingAs($this->user)->postJson('/api/ledgers', array_merge($base, ['name' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->actingAs($this->user)->postJson('/api/ledgers', array_merge($base, [
            'start_date' => '2026-12-31', 'end_date' => '2026-01-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('end_date');

        $this->actingAs($this->user)->postJson('/api/ledgers', array_merge($base, ['starting_balance' => -50]))
            ->assertUnprocessable()->assertJsonValidationErrors('starting_balance');

        $this->actingAs($this->user)->postJson('/api/ledgers', array_merge($base, ['starting_balance_type' => 'gold']))
            ->assertUnprocessable()->assertJsonValidationErrors('starting_balance_type');

        $this->actingAs($this->user)->postJson('/api/ledgers', array_merge($base, ['profile_id' => 99999]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Close & reopen
    // ------------------------------------------------------------------

    public function test_close_marks_the_ledger_read_only(): void
    {
        $ledger = $this->createLedger($this->user);

        $response = $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/close");

        $response->assertOk();
        $this->assertNotNull($response->json('ledger.closed_at'));
        $this->assertEquals('closed', $response->json('ledger.status'));
    }

    public function test_reopen_restores_the_ledger(): void
    {
        $ledger = $this->createLedger($this->user, ['closed_at' => now()]);

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/close")
            ->assertOk();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/reopen")
            ->assertOk()
            ->assertJsonPath('ledger.status', 'open')
            ->assertJsonPath('ledger.closed_at', null);

        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id, 'closed_at' => null]);
    }

    // ------------------------------------------------------------------
    // Transactions
    // ------------------------------------------------------------------

    public function test_adding_a_transaction_requires_a_published_budget(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user, published: false);
        $enveloppe = $budget->enveloppes()->first();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'amount' => 42,
            'event_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('budget');

        $budget->update(['published_at' => now()]);

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'amount' => 42,
            'event_at' => '2026-06-15',
        ])->assertCreated();
    }

    public function test_adding_a_transaction_signs_the_amount_and_snapshots_the_budget(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        [$revenueAssignment, $expenseAssignment] = $budget->enveloppes()->orderByDesc('amount')->get();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $revenueAssignment->enveloppe_id,
            'amount' => 42,
            'event_at' => '2026-06-15',
        ])->assertCreated();

        // remaining_allocated is a range-view concept; the create response
        // omits it.
        $this->assertArrayNotHasKey('remaining_allocated', $this->actingAs($this->user)
            ->postJson("/api/ledgers/{$ledger->id}/transactions", [
                'enveloppe_id' => $expenseAssignment->enveloppe_id,
                'amount' => 75,
                'event_at' => '2026-06-20',
            ])->assertCreated()->json('transaction'));

        $revenue = Transaction::where('amount', 42)->firstOrFail();
        $expense = Transaction::where('amount', -75)->firstOrFail();

        $this->assertEquals($ledger->id, $revenue->ledger_id);
        $this->assertEquals($this->user->id, $revenue->user_id);
        $this->assertEquals($budget->id, $revenue->budget_id);
        $this->assertEquals($budget->id, $expense->budget_id);
        $this->assertEquals('2026-06-15 00:00:00', $revenue->event_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-06-20 00:00:00', $expense->event_at->format('Y-m-d H:i:s'));
    }

    public function test_retired_budgets_never_qualify_as_the_snapshot(): void
    {
        $ledger = $this->createLedger($this->user);
        $enveloppe = $this->user->enveloppes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        // An old published budget for the current month that is retired.
        Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => now()->year,
            'effective_month' => now()->month,
            'published_at' => now()->subDay(),
            'retired_at' => now(),
        ]);

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $enveloppe->id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('budget');
    }

    public function test_adding_a_transaction_rejects_enveloppes_outside_the_budget(): void
    {
        $ledger = $this->createLedger($this->user);
        $this->createPublishedBudget($this->user);
        $enveloppe = $this->user->enveloppes()->create([
            'name' => 'Not in budget', 'type' => 'expense', 'color' => '#111111',
        ]);

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $enveloppe->id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('enveloppe_id');
    }

    public function test_adding_a_transaction_requires_a_valid_amount(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();

        foreach ([-10, 0, 1.5, 'abc'] as $amount) {
            $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
                'enveloppe_id' => $enveloppe->enveloppe_id,
                'amount' => $amount,
                'event_at' => '2026-06-15',
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
    }

    public function test_adding_a_transaction_rejects_dates_outside_the_ledger_range(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'amount' => 10,
            'event_at' => '2025-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_at');

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'amount' => 10,
            'event_at' => '2027-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_at');
    }

    public function test_transactions_can_be_filtered_by_date_range(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();

        foreach (['2026-05-01', '2026-05-10', '2026-06-01'] as $date) {
            $ledger->transactions()->create([
                'user_id' => $this->user->id,
                'enveloppe_id' => $enveloppe->enveloppe_id,
                'budget_id' => $budget->id,
                'amount' => $enveloppe->amount > 0 ? 10 : -10,
                'event_at' => $date.' 00:00:00',
            ]);
        }

        $response = $this->actingAs($this->user)->getJson(
            "/api/ledgers/{$ledger->id}/transactions?from_date=2026-05-01&to_date=2026-05-31"
        );

        $response->assertOk();
        $data = $response->json('transactions');
        $this->assertCount(2, $data);
        $this->assertEquals('Salary', $data[0]['name']);
        $this->assertEquals('#10b981', $data[0]['color']);
        $this->assertEquals('revenue', $data[0]['direction']);
        $this->assertEquals($this->user->id, $data[0]['user']['id']);
        $this->assertFalse($data[0]['masked']);
        $this->assertNotNull($data[0]['amount']);
    }

    public function test_transactions_cannot_be_added_to_a_closed_ledger(): void
    {
        $ledger = $this->createLedger($this->user, ['closed_at' => now()]);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('ledger');
    }

    public function test_transactions_cannot_be_deleted_from_a_closed_ledger(): void
    {
        $ledger = $this->createLedger($this->user, ['closed_at' => now()]);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();
        $transaction = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/transactions/{$transaction->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('ledger');
    }

    public function test_a_transaction_can_be_deleted(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();
        $transaction = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/transactions/{$transaction->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_transaction_deletion_rules_per_permission(): void
    {
        $ledger = $this->createLedger($this->user);
        $ledger->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();

        $ownerEntry = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);
        $contributorEntry = $ledger->transactions()->create([
            'user_id' => $this->member->id,
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -10,
            'event_at' => '2026-06-16 00:00:00',
        ]);

        // A ledger contributor may delete only their own entries.
        $this->actingAs($this->member)->deleteJson("/api/transactions/{$ownerEntry->id}")
            ->assertForbidden();
        $this->actingAs($this->member)->deleteJson("/api/transactions/{$contributorEntry->id}")
            ->assertNoContent();

        // A viewer (implicit member) cannot delete anything.
        $other = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -10,
            'event_at' => '2026-06-17 00:00:00',
        ]);
        $this->profile->members()->attach($this->stranger->id, ['is_owner' => false]);
        $this->actingAs($this->stranger)->deleteJson("/api/transactions/{$other->id}")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Ownership & cascades
    // ------------------------------------------------------------------

    public function test_users_outside_the_profile_cannot_access_ledgers(): void
    {
        $ledger = $this->createLedger($this->user);

        $this->actingAs($this->stranger)->getJson("/api/ledgers/{$ledger->id}/transactions")
            ->assertNotFound();

        $this->actingAs($this->stranger)->deleteJson("/api/ledgers/{$ledger->id}")
            ->assertNotFound();

        $this->actingAs($this->stranger)->postJson("/api/ledgers/{$ledger->id}/close")
            ->assertNotFound();
    }

    public function test_deleting_a_ledger_cascades_its_transactions(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();
        $transaction = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/ledgers/{$ledger->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseMissing('ledger_permissions', ['ledger_id' => $ledger->id]);
    }

    public function test_deleting_a_budget_with_transactions_is_blocked(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();
        $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('budget');

        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    public function test_enveloppes_used_in_transactions_cannot_be_deleted(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $assignment = $budget->enveloppes()->first();
        $enveloppe = $this->user->enveloppes()->whereKey($assignment->enveloppe_id)->firstOrFail();

        $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $assignment->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $this->actingAs($this->user)->delete('/enveloppes/'.$enveloppe->id)
            ->assertSessionHasErrors('enveloppe');

        $this->assertDatabaseHas('enveloppes', ['id' => $enveloppe->id]);
    }

    // ------------------------------------------------------------------
    // Masking
    // ------------------------------------------------------------------

    public function test_transactions_on_unshared_enveloppes_are_masked(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $salaryAssignment = $budget->enveloppes()->where('amount', 100)->firstOrFail();
        $rentAssignment = $budget->enveloppes()->where('amount', -200)->firstOrFail();

        $salary = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $salaryAssignment->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => 10,
            'event_at' => '2026-06-01 00:00:00',
        ]);
        $rent = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $rentAssignment->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -50,
            'event_at' => '2026-06-02 00:00:00',
        ]);

        // The member has viewer permission on the Salary enveloppe only.
        Enveloppe::query()->findOrFail($salaryAssignment->enveloppe_id)
            ->permissions()->create([
                'user_id' => $this->member->id,
                'permission' => EnveloppePermission::PERMISSION_VIEWER,
            ]);

        $response = $this->actingAs($this->member)->getJson(
            "/api/ledgers/{$ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30"
        );

        $response->assertOk();
        $rows = collect($response->json('transactions'))->keyBy('id');

        $salaryRow = $rows->get($salary->id);
        $rentRow = $rows->get($rent->id);

        // Visible row: amounts plus remaining allocated.
        $this->assertFalse($salaryRow['masked']);
        $this->assertEquals(10, $salaryRow['amount']);
        $this->assertEquals(90, $salaryRow['remaining_allocated']);

        // Masked row: raw amounts never reach the client.
        $this->assertTrue($rentRow['masked']);
        $this->assertNull($rentRow['amount']);
        $this->assertNull($rentRow['remaining_allocated']);
        $this->assertEquals('Rent', $rentRow['name']);
        $this->assertEquals('#f43f5e', $rentRow['color']);
        $this->assertEquals('expense', $rentRow['direction']);
    }

    public function test_the_owner_sees_all_amounts_unmasked(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $rentAssignment = $budget->enveloppes()->where('amount', -200)->firstOrFail();

        $ledger->transactions()->create([
            'user_id' => $this->member->id,
            'enveloppe_id' => $rentAssignment->enveloppe_id,
            'budget_id' => $budget->id,
            'amount' => -50,
            'event_at' => '2026-06-02 00:00:00',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/ledgers/{$ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30"
        );

        $row = $response->json('transactions.0');
        $this->assertFalse($row['masked']);
        $this->assertEquals(-50, $row['amount']);
        $this->assertEquals(-150, $row['remaining_allocated']);
    }

    public function test_remaining_allocated_follows_each_rows_own_budget_revision(): void
    {
        $ledger = $this->createLedger($this->user);
        $now = now();

        // Revision A: published, enveloppe allocated -200, one transaction.
        $revisionA = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => $now->year,
            'effective_month' => $now->month,
            'published_at' => $now,
        ]);
        $revisionA->permissions()->create(['user_id' => $this->user->id, 'permission' => 'owner']);
        $enveloppe = $this->user->enveloppes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
        $revisionA->enveloppes()->create(['enveloppe_id' => $enveloppe->id, 'amount' => -200, 'type' => 'fixed']);

        $first = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $enveloppe->id,
            'budget_id' => $revisionA->id,
            'amount' => -50,
            'event_at' => '2026-06-05 00:00:00',
        ]);

        // Revision B replaces A for the same month; A becomes retired while
        // still referenced by the first transaction.
        $revisionB = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => 0,
            'effective_month' => 0,
        ]);
        $revisionB->permissions()->create(['user_id' => $this->user->id, 'permission' => 'owner']);
        $revisionB->enveloppes()->create(['enveloppe_id' => $enveloppe->id, 'amount' => -300, 'type' => 'fixed']);

        $this->actingAs($this->user)->postJson("/api/budgets/{$revisionB->id}/publish")->assertOk();
        $this->assertNotNull($revisionA->fresh()->retired_at);

        $second = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'enveloppe_id' => $enveloppe->id,
            'budget_id' => $revisionB->id,
            'amount' => -80,
            'event_at' => '2026-06-20 00:00:00',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/ledgers/{$ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30"
        );

        $rows = collect($response->json('transactions'))->keyBy('id');

        // Each row computes remaining allocated against its own snapshot.
        $this->assertEquals(-200 - -50, $rows->get($first->id)['remaining_allocated']);
        $this->assertEquals(-300 - -80, $rows->get($second->id)['remaining_allocated']);
    }

    // ------------------------------------------------------------------
    // Ledger contributor gated by enveloppe permission
    // ------------------------------------------------------------------

    public function test_a_ledger_contributor_needs_enveloppe_contributor_access(): void
    {
        $ledger = $this->createLedger($this->user);
        $ledger->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);
        $budget = $this->createPublishedBudget($this->user);
        [$revenueAssignment, $expenseAssignment] = $budget->enveloppes()->orderByDesc('amount')->get();

        // Without enveloppe contributor access: rejected.
        $this->actingAs($this->member)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $expenseAssignment->enveloppe_id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('enveloppe_id');

        // Grant enveloppe contributor access on the expense enveloppe.
        Enveloppe::query()->findOrFail($expenseAssignment->enveloppe_id)
            ->permissions()->create([
                'user_id' => $this->member->id,
                'permission' => EnveloppePermission::PERMISSION_CONTRIBUTOR,
            ]);

        $this->actingAs($this->member)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $expenseAssignment->enveloppe_id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertCreated();

        // The ledger owner records regardless of enveloppe permissions.
        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $revenueAssignment->enveloppe_id,
            'amount' => 10,
            'event_at' => '2026-06-16',
        ])->assertCreated();
    }

    public function test_implicit_viewers_cannot_record_transactions(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $enveloppe = $budget->enveloppes()->first();

        $this->actingAs($this->member)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'enveloppe_id' => $enveloppe->enveloppe_id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Ledger shares
    // ------------------------------------------------------------------

    public function test_ledger_permissions_crud_and_transfer(): void
    {
        $ledger = $this->createLedger($this->user);

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/shares", [
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ])->assertCreated();

        $this->assertDatabaseHas('ledger_permissions', [
            'ledger_id' => $ledger->id,
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);

        // "owner" is rejected.
        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/shares", [
            'user_id' => $this->member->id,
            'permission' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors('permission');

        // Non-members are rejected.
        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/shares", [
            'user_id' => $this->stranger->id,
            'permission' => 'viewer',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        // Update.
        $this->actingAs($this->user)->patchJson("/api/ledgers/{$ledger->id}/shares/{$this->member->id}", [
            'permission' => 'viewer',
        ])->assertOk();

        $this->assertDatabaseHas('ledger_permissions', [
            'ledger_id' => $ledger->id,
            'user_id' => $this->member->id,
            'permission' => 'viewer',
        ]);

        // Members can list shares.
        $this->actingAs($this->member)->getJson("/api/ledgers/{$ledger->id}/shares")
            ->assertOk()->assertJsonCount(2, 'shares');

        // Revoke.
        $this->actingAs($this->user)->deleteJson("/api/ledgers/{$ledger->id}/shares/{$this->member->id}")
            ->assertNoContent();

        // Transfer ownership; the previous owner becomes contributor.
        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertOk();

        $this->assertSame(ResourcePermissions::OWNER, $ledger->fresh()->permissionFor($this->member));
        $this->assertNotSame(ResourcePermissions::OWNER, $ledger->fresh()->permissionFor($this->user));
        $this->assertDatabaseHas('ledger_permissions', [
            'ledger_id' => $ledger->id,
            'user_id' => $this->user->id,
            'permission' => 'contributor',
        ]);
    }
}
