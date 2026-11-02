<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
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
     * envelope, assigned amounts of 100 (revenue) and 200 (expense). The
     * effective month defaults to the current month.
     *
     * @param  array<string, int|string|null>  $attributes
     */
    private function createPublishedBudget(User $user, bool $published = true, array $attributes = []): Budget
    {
        $budget = Budget::create(array_merge([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => now()->year,
            'effective_month' => now()->month,
            'published_at' => $published ? now() : null,
        ], $attributes));

        $budget->permissions()->create([
            'user_id' => $user->id,
            'permission' => BudgetPermission::PERMISSION_OWNER,
        ]);

        $revenue = $user->envelopes()->create([
            'name' => 'Salary', 'type' => 'revenue', 'color' => '#10b981',
        ]);
        $expense = $user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        $budget->envelopes()->create([
            'envelope_id' => $revenue->id, 'amount' => 100, 'type' => 'fixed',
        ]);
        $budget->envelopes()->create([
            'envelope_id' => $expense->id, 'amount' => -200, 'type' => 'fixed',
        ]);

        return $budget;
    }

    private function createEnvelope(string $name = 'Rent'): Envelope
    {
        return $this->user->envelopes()->create([
            'name' => $name, 'type' => 'expense', 'color' => '#f43f5e',
        ]);
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

    public function test_adding_a_transaction_does_not_require_a_budget(): void
    {
        $ledger = $this->createLedger($this->user);
        $envelope = $this->createEnvelope();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $envelope->id,
            'amount' => 42,
            'event_at' => '2026-06-15',
        ])->assertCreated();

        $this->assertDatabaseHas('transactions', [
            'ledger_id' => $ledger->id,
            'envelope_id' => $envelope->id,
            'amount' => -42, // expense envelope
        ]);
    }

    public function test_adding_a_transaction_signs_the_amount(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        [$revenueAssignment, $expenseAssignment] = $budget->envelopes()->orderByDesc('amount')->get();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $revenueAssignment->envelope_id,
            'amount' => 42,
            'event_at' => '2026-06-15',
        ])->assertCreated();

        // remaining_allocated is a range-view concept; the create response
        // omits it.
        $this->assertArrayNotHasKey('remaining_allocated', $this->actingAs($this->user)
            ->postJson("/api/ledgers/{$ledger->id}/transactions", [
                'envelope_id' => $expenseAssignment->envelope_id,
                'amount' => 75,
                'event_at' => '2026-06-20',
            ])->assertCreated()->json('transaction'));

        $revenue = Transaction::where('amount', 42)->firstOrFail();
        $expense = Transaction::where('amount', -75)->firstOrFail();

        $this->assertEquals($ledger->id, $revenue->ledger_id);
        $this->assertEquals($this->user->id, $revenue->user_id);
        $this->assertEquals('2026-06-15 00:00:00', $revenue->event_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-06-20 00:00:00', $expense->event_at->format('Y-m-d H:i:s'));
    }

    public function test_adding_a_transaction_requires_a_valid_amount(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $envelope = $budget->envelopes()->first();

        foreach ([-10, 0, 1.5, 'abc'] as $amount) {
            $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
                'envelope_id' => $envelope->envelope_id,
                'amount' => $amount,
                'event_at' => '2026-06-15',
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
    }

    public function test_adding_a_transaction_rejects_dates_outside_the_ledger_range(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $envelope = $budget->envelopes()->first();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $envelope->envelope_id,
            'amount' => 10,
            'event_at' => '2025-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_at');

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $envelope->envelope_id,
            'amount' => 10,
            'event_at' => '2027-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_at');
    }

    public function test_transactions_can_be_filtered_by_date_range(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $envelope = $budget->envelopes()->first();

        foreach (['2026-05-01', '2026-05-10', '2026-06-01'] as $date) {
            $ledger->transactions()->create([
                'user_id' => $this->user->id,
                'envelope_id' => $envelope->envelope_id,
                'amount' => $envelope->amount > 0 ? 10 : -10,
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
        $envelope = $budget->envelopes()->first();

        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $envelope->envelope_id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('ledger');
    }

    public function test_transactions_cannot_be_deleted_from_a_closed_ledger(): void
    {
        $ledger = $this->createLedger($this->user, ['closed_at' => now()]);
        $budget = $this->createPublishedBudget($this->user);
        $envelope = $budget->envelopes()->first();
        $transaction = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->envelope_id,
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
        $envelope = $budget->envelopes()->first();
        $transaction = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->envelope_id,
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
        $envelope = $budget->envelopes()->first();

        $ownerEntry = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->envelope_id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);
        $contributorEntry = $ledger->transactions()->create([
            'user_id' => $this->member->id,
            'envelope_id' => $envelope->envelope_id,
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
            'envelope_id' => $envelope->envelope_id,
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
        $envelope = $budget->envelopes()->first();
        $transaction = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->envelope_id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/ledgers/{$ledger->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseMissing('ledger_permissions', ['ledger_id' => $ledger->id]);
    }

    public function test_a_budget_can_be_deleted_even_with_transactions(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $envelope = $budget->envelopes()->first();
        $transaction = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->envelope_id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $this->actingAs($this->user)->deleteJson("/api/budgets/{$budget->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);

        // Transactions do not reference budgets — they are unaffected.
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_envelopes_used_in_transactions_cannot_be_deleted(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $assignment = $budget->envelopes()->first();
        $envelope = $this->user->envelopes()->whereKey($assignment->envelope_id)->firstOrFail();

        $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $assignment->envelope_id,
            'amount' => -10,
            'event_at' => '2026-06-15 00:00:00',
        ]);

        $this->actingAs($this->user)->delete('/envelopes/'.$envelope->id)
            ->assertSessionHasErrors('envelope');

        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id]);
    }

    // ------------------------------------------------------------------
    // Masking
    // ------------------------------------------------------------------

    public function test_transactions_on_unshared_envelopes_are_masked(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user, attributes: [
            'effective_year' => 2026,
            'effective_month' => 6,
        ]);
        $salaryAssignment = $budget->envelopes()->where('amount', 100)->firstOrFail();
        $rentAssignment = $budget->envelopes()->where('amount', -200)->firstOrFail();

        $salary = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $salaryAssignment->envelope_id,
            'amount' => 10,
            'event_at' => '2026-06-01 00:00:00',
        ]);
        $rent = $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $rentAssignment->envelope_id,
            'amount' => -50,
            'event_at' => '2026-06-02 00:00:00',
        ]);

        // The member has viewer permission on the Salary envelope only.
        Envelope::query()->findOrFail($salaryAssignment->envelope_id)
            ->permissions()->create([
                'user_id' => $this->member->id,
                'permission' => EnvelopePermission::PERMISSION_VIEWER,
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
        $budget = $this->createPublishedBudget($this->user, attributes: [
            'effective_year' => 2026,
            'effective_month' => 6,
        ]);
        $rentAssignment = $budget->envelopes()->where('amount', -200)->firstOrFail();

        $ledger->transactions()->create([
            'user_id' => $this->member->id,
            'envelope_id' => $rentAssignment->envelope_id,
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

    public function test_remaining_allocated_follows_the_displayed_months_budget(): void
    {
        $ledger = $this->createLedger($this->user);
        $envelope = $this->user->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);

        // Published budget for June 2026: Rent allocated -200.
        $june = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
        ]);
        $june->forceFill([
            'published_at' => '2026-06-01 00:00:00',
            'effective_year' => 2026,
            'effective_month' => 6,
        ])->save();
        $june->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -200, 'type' => 'fixed']);

        // Published budget for July 2026: Rent allocated -300.
        $july = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
        ]);
        $july->forceFill([
            'published_at' => '2026-07-01 00:00:00',
            'effective_year' => 2026,
            'effective_month' => 7,
        ])->save();
        $july->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -300, 'type' => 'fixed']);

        $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'amount' => -50,
            'event_at' => '2026-06-05 00:00:00',
        ]);
        $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'amount' => -80,
            'event_at' => '2026-07-20 00:00:00',
        ]);

        // June: -200 − (-50).
        $juneRows = $this->actingAs($this->user)
            ->getJson("/api/ledgers/{$ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30")
            ->assertOk()->json('transactions');
        $this->assertEquals(-200 - -50, $juneRows[0]['remaining_allocated']);

        // July: -300 − (-80).
        $julyRows = $this->actingAs($this->user)
            ->getJson("/api/ledgers/{$ledger->id}/transactions?from_date=2026-07-01&to_date=2026-07-31")
            ->assertOk()->json('transactions');
        $this->assertEquals(-300 - -80, $julyRows[0]['remaining_allocated']);
    }

    public function test_months_without_a_published_budget_report_no_remaining_allocated(): void
    {
        $ledger = $this->createLedger($this->user);
        $envelope = $this->createEnvelope();

        $ledger->transactions()->create([
            'user_id' => $this->user->id,
            'envelope_id' => $envelope->id,
            'amount' => -50,
            'event_at' => '2026-06-05 00:00:00',
        ]);

        $row = $this->actingAs($this->user)
            ->getJson("/api/ledgers/{$ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30")
            ->assertOk()->json('transactions.0');

        $this->assertFalse($row['masked']);
        $this->assertEquals(-50, $row['amount']);
        $this->assertNull($row['remaining_allocated']);
    }

    // ------------------------------------------------------------------
    // Ledger contributor gated by envelope permission
    // ------------------------------------------------------------------

    public function test_a_ledger_contributor_needs_envelope_contributor_access(): void
    {
        $ledger = $this->createLedger($this->user);
        $ledger->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);
        $budget = $this->createPublishedBudget($this->user);
        [$revenueAssignment, $expenseAssignment] = $budget->envelopes()->orderByDesc('amount')->get();

        // Without envelope contributor access: rejected.
        $this->actingAs($this->member)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $expenseAssignment->envelope_id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('envelope_id');

        // Grant envelope contributor access on the expense envelope.
        Envelope::query()->findOrFail($expenseAssignment->envelope_id)
            ->permissions()->create([
                'user_id' => $this->member->id,
                'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR,
            ]);

        $this->actingAs($this->member)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $expenseAssignment->envelope_id,
            'amount' => 10,
            'event_at' => '2026-06-15',
        ])->assertCreated();

        // The ledger owner records regardless of envelope permissions.
        $this->actingAs($this->user)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $revenueAssignment->envelope_id,
            'amount' => 10,
            'event_at' => '2026-06-16',
        ])->assertCreated();
    }

    public function test_implicit_viewers_cannot_record_transactions(): void
    {
        $ledger = $this->createLedger($this->user);
        $budget = $this->createPublishedBudget($this->user);
        $envelope = $budget->envelopes()->first();

        $this->actingAs($this->member)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $envelope->envelope_id,
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
