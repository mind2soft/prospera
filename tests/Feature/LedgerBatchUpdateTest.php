<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Batch sparse update of manual transactions (issue #123, docs/ledger.md):
 * one request updates one or many rows with zero or more shared fields,
 * returns one outcome per requested id in request order, and never
 * discloses rows the caller cannot see.
 */
class LedgerBatchUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $member;

    private User $stranger;

    private FinancialProfile $profile;

    private Ledger $ledger;

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
            'permission' => ResourcePermissions::OWNER,
        ]);

        return $ledger;
    }

    private function createEnvelope(string $name, string $type, ?User $owner = null): Envelope
    {
        return ($owner ?? $this->user)->envelopes()->create([
            'name' => $name,
            'type' => $type,
            'color' => '#10b981',
        ]);
    }

    private function createTransaction(
        Ledger $ledger,
        Envelope $envelope,
        User $author,
        int|float $amount,
        string $eventAt = '2026-06-10 00:00:00',
        ?string $note = null,
    ): Transaction {
        return $ledger->transactions()->create([
            'user_id' => $author->id,
            'envelope_id' => $envelope->id,
            'amount' => $amount,
            'event_at' => $eventAt,
            'note' => $note,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function update(array $payload, ?User $as = null, ?Ledger $on = null): TestResponse
    {
        $ledger = $on ?? $this->ledger;

        return $this->actingAs($as ?? $this->user)
            ->patchJson("/api/ledgers/{$ledger->id}/transactions/batch", $payload);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function resultsOf(array $ids, array $payload = [], ?User $as = null, ?Ledger $on = null): array
    {
        $results = $this->update($payload + ['transaction_ids' => $ids], $as, $on)
            ->assertOk()
            ->json('results');

        $this->assertIsArray($results);

        return $results;
    }

    // ------------------------------------------------------------------
    // Full and sparse updates
    // ------------------------------------------------------------------

    public function test_owner_updates_a_single_transaction_across_all_fields(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $gas = $this->createEnvelope('Gas', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50, '2026-06-10 00:00:00', 'Old note');

        $result = $this->resultsOf([$transaction->id], [
            'envelope_id' => $gas->id,
            'amount' => 42.55,
            'event_at' => '2026-06-20',
            'note' => 'New note',
        ])[0];

        $this->assertSame('updated', $result['status']);
        $this->assertSame($transaction->id, $result['transaction']['id']);
        $this->assertSame($gas->id, $result['transaction']['envelope_id']);
        $this->assertSame(-42.55, $result['transaction']['amount']);
        $this->assertSame('2026-06-20', $result['transaction']['event_at']);
        $this->assertSame('New note', $result['transaction']['note']);

        $fresh = $transaction->fresh();
        $this->assertSame($gas->id, $fresh->envelope_id);
        $this->assertSame(-42.55, $fresh->amount);
        $this->assertSame('2026-06-20 00:00:00', $fresh->event_at->toDateTimeString());
        $this->assertSame('New note', $fresh->note);
    }

    public function test_sparse_update_only_changes_supplied_fields(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50, '2026-06-10 00:00:00', 'Keep me');

        $this->resultsOf([$transaction->id], ['amount' => 60])[0];

        $fresh = $transaction->fresh();
        // The entered amount is positive; the stored sign follows the
        // envelope direction (docs/ledger.md).
        $this->assertSame(-60, $fresh->amount);
        $this->assertSame($rent->id, $fresh->envelope_id);
        $this->assertSame('2026-06-10 00:00:00', $fresh->event_at->toDateTimeString());
        $this->assertSame('Keep me', $fresh->note);
    }

    public function test_a_request_without_fields_reports_updated_but_changes_nothing(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50, '2026-06-10 00:00:00', 'Note');

        $this->resultsOf([$transaction->id])[0];

        $fresh = $transaction->fresh();
        $this->assertSame(-50, $fresh->amount);
        $this->assertSame($rent->id, $fresh->envelope_id);
        $this->assertSame('Note', $fresh->note);
    }

    public function test_the_stored_amount_sign_follows_the_envelope_direction(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $salary = $this->createEnvelope('Salary', 'revenue');
        $expense = $this->createTransaction($this->ledger, $rent, $this->user, 0);
        $revenue = $this->createTransaction($this->ledger, $salary, $this->user, 0);

        $this->update(['amount' => 25.5] + ['transaction_ids' => [$expense->id, $revenue->id]], null, $this->ledger)->assertOk();

        $this->assertEncryptedAmount(Transaction::class, ['id' => $expense->id], 'amount', -25.5);
        $this->assertEncryptedAmount(Transaction::class, ['id' => $revenue->id], 'amount', 25.5);
    }

    public function test_changing_only_the_envelope_preserves_magnitude_and_recomputes_the_sign(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $salary = $this->createEnvelope('Salary', 'revenue');
        $expense = $this->createTransaction($this->ledger, $rent, $this->user, -50.25);
        $revenue = $this->createTransaction($this->ledger, $salary, $this->user, 40);

        $this->update(['envelope_id' => $salary->id] + ['transaction_ids' => [$expense->id, $revenue->id]], null, $this->ledger)->assertOk();

        // Both rows moved to the revenue envelope: magnitudes preserved,
        // stored signs recomputed from the new direction.
        $this->assertEncryptedAmount(Transaction::class, ['id' => $expense->id], 'amount', 50.25);
        $this->assertEncryptedAmount(Transaction::class, ['id' => $revenue->id], 'amount', 40);
        $this->assertSame($salary->id, $expense->fresh()->envelope_id);
        $this->assertSame($salary->id, $revenue->fresh()->envelope_id);
    }

    public function test_a_batch_of_rows_is_updated_with_one_shared_envelope(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $gas = $this->createEnvelope('Gas', 'expense');
        $first = $this->createTransaction($this->ledger, $rent, $this->user, -100);
        $second = $this->createTransaction($this->ledger, $rent, $this->user, 40.5);

        $results = $this->resultsOf([$first->id, $second->id], ['envelope_id' => $gas->id]);

        $this->assertSame('updated', $results[0]['status']);
        $this->assertSame('updated', $results[1]['status']);
        $this->assertSame($first->id, $results[0]['transaction']['id']);
        $this->assertSame($second->id, $results[1]['transaction']['id']);

        // Magnitudes preserved per row; both signed by the new direction.
        $this->assertEncryptedAmount(Transaction::class, ['id' => $first->id], 'amount', -100);
        $this->assertEncryptedAmount(Transaction::class, ['id' => $second->id], 'amount', -40.5);
    }

    // ------------------------------------------------------------------
    // Dates
    // ------------------------------------------------------------------

    public function test_the_event_date_is_normalized_to_midnight(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50);

        $this->update(['event_at' => '2026-07-04 15:30:00'] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)->assertOk();

        $this->assertSame('2026-07-04 00:00:00', $transaction->fresh()->event_at->toDateTimeString());
    }

    public function test_an_event_date_outside_the_ledger_period_is_rejected_without_writes(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50);

        $this->update(['event_at' => '2027-01-15'] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_at');

        $this->assertSame(-50, $transaction->fresh()->amount);
    }

    // ------------------------------------------------------------------
    // Notes
    // ------------------------------------------------------------------

    public function test_the_note_is_trimmed_and_stored_encrypted(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50);

        $this->update(['note' => '  Padded note  '] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)
            ->assertOk()
            ->assertJsonPath('results.0.transaction.note', 'Padded note');

        $stored = DB::table('transactions')->where('id', $transaction->id)->value('note');
        $this->assertIsString($stored);
        $this->assertNotSame('Padded note', $stored);
        $this->assertSame('Padded note', $transaction->fresh()->note);
    }

    public function test_a_blank_note_is_never_a_clear_signal(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50, '2026-06-10 00:00:00', 'Existing');

        $this->update(['note' => '   '] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)->assertOk();
        $this->assertSame('Existing', $transaction->fresh()->note);

        $this->update(['note' => null] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)->assertOk();
        $this->assertSame('Existing', $transaction->fresh()->note);
    }

    public function test_clear_note_clears_the_notes_of_updated_rows(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $first = $this->createTransaction($this->ledger, $rent, $this->user, -50, '2026-06-10 00:00:00', 'First');
        $second = $this->createTransaction($this->ledger, $rent, $this->user, -60, '2026-06-11 00:00:00', 'Second');

        $this->update(['clear_note' => true] + ['transaction_ids' => [$first->id, $second->id]], null, $this->ledger)->assertOk();

        $this->assertNull($first->fresh()->note);
        $this->assertNull($second->fresh()->note);
    }

    public function test_omitting_the_note_preserves_it(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50, '2026-06-10 00:00:00', 'Untouched');

        $this->update(['amount' => 10] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)->assertOk();

        $this->assertSame('Untouched', $transaction->fresh()->note);
    }

    public function test_note_and_clear_note_are_mutually_exclusive(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50, '2026-06-10 00:00:00', 'Original');

        $this->update(['note' => 'Replacement', 'clear_note' => true] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)
            ->assertUnprocessable();

        $this->assertSame('Original', $transaction->fresh()->note);
    }

    // ------------------------------------------------------------------
    // Request-level validation
    // ------------------------------------------------------------------

    public function test_the_id_list_must_be_a_non_empty_list_of_unique_integers(): void
    {
        $this->ledger = $this->createLedger($this->user);

        foreach ([[], ['transaction_ids' => []], ['transaction_ids' => 'nope'], ['transaction_ids' => [1, 1]]] as $payload) {
            $this->update($payload)->assertUnprocessable();
        }
    }

    public function test_a_malformed_amount_is_rejected_without_writes(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50);

        foreach (['0', '-5', '12.345', 'abc'] as $amount) {
            $this->update(['amount' => $amount] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        $this->assertSame(-50, $transaction->fresh()->amount);
    }

    public function test_an_unusable_replacement_envelope_is_rejected_without_writes(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50);

        // Unknown envelope.
        $this->update(['envelope_id' => 999999] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('envelope_id');

        // An envelope the caller cannot use (no envelope permission).
        $foreign = $this->createEnvelope('Foreign', 'expense', $this->stranger);
        $this->update(['envelope_id' => $foreign->id] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('envelope_id');

        $this->assertSame($rent->id, $transaction->fresh()->envelope_id);
    }

    public function test_viewing_an_envelope_is_not_enough_to_assign_it(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50);

        // The member sees the envelope (viewer share) but cannot use it:
        // the batch update rejects the replacement envelope either way.
        $rent->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);
        $this->update(['envelope_id' => $rent->id] + ['transaction_ids' => [$transaction->id]], $this->member, $this->ledger)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('envelope_id');

        $this->assertSame(-50, $transaction->fresh()->amount);
    }

    public function test_system_managed_fields_are_never_accepted(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50);

        foreach ([['schedule_id' => 4], ['user_id' => 4], ['ledger_id' => 4]] as $payload) {
            $this->update($payload + ['transaction_ids' => [$transaction->id]])
                ->assertUnprocessable();
        }

        $this->assertSame(-50, $transaction->fresh()->amount);
    }

    // ------------------------------------------------------------------
    // Per-row outcomes
    // ------------------------------------------------------------------

    public function test_results_are_returned_in_request_order(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $first = $this->createTransaction($this->ledger, $rent, $this->user, -10);
        $second = $this->createTransaction($this->ledger, $rent, $this->user, -20);

        $results = $this->resultsOf([$second->id, $first->id], ['amount' => 5]);

        $this->assertSame($second->id, $results[0]['id']);
        $this->assertSame($first->id, $results[1]['id']);
        $this->assertSame(-5, $results[0]['transaction']['amount']);
        $this->assertSame(-5, $results[1]['transaction']['amount']);
    }

    public function test_unknown_ids_and_other_ledgers_rows_fail_as_not_found(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $known = $this->createTransaction($this->ledger, $rent, $this->user, -10);

        // The caller owns both ledgers, yet a foreign-ledger row is only
        // ever "not found" — the endpoint does not disclose it.
        $otherLedger = $this->createLedger($this->user, ['name' => '2027']);
        $foreign = $this->createTransaction($otherLedger, $rent, $this->user, -99);

        $results = $this->resultsOf([$foreign->id, $known->id, 424242], ['amount' => 7]);

        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('not_found', $results[0]['code']);
        $this->assertSame('failed', $results[2]['status']);
        $this->assertSame('not_found', $results[2]['code']);

        $this->assertSame('updated', $results[1]['status']);
        $this->assertSame(-7, $known->fresh()->amount);
        $this->assertSame(-99, $foreign->fresh()->amount);
    }

    public function test_rows_pending_deletion_fail_as_not_found(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $pending = $this->createTransaction($this->ledger, $rent, $this->user, -10);
        $pending->delete();

        $alive = $this->createTransaction($this->ledger, $rent, $this->user, -20);

        $results = $this->resultsOf([$pending->id, $alive->id], ['amount' => 7]);

        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('not_found', $results[0]['code']);
        $this->assertSame('updated', $results[1]['status']);
        $this->assertSame(-10, $pending->fresh()->amount);
        $this->assertSame(-7, $alive->fresh()->amount);
    }

    public function test_generated_rows_fail_as_scheduled(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $schedule = $rent->schedules()->create([
            'period' => 'once', 'start_date' => '2026-06-12', 'amount' => -500,
        ]);
        $generated = (new Transaction)->forceFill([
            'ledger_id' => $this->ledger->id,
            'envelope_id' => $rent->id,
            'schedule_id' => $schedule->id,
            'amount' => -500,
            'event_at' => '2026-06-12 00:00:00',
        ]);
        $generated->save();

        $manual = $this->createTransaction($this->ledger, $rent, $this->user, -10);

        $results = $this->resultsOf([$generated->id, $manual->id], ['amount' => 7]);

        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('scheduled', $results[0]['code']);
        $this->assertSame('updated', $results[1]['status']);
        $this->assertSame(-500, $generated->fresh()->amount);
    }

    public function test_a_contributor_updates_only_their_own_entries(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $own = $this->createTransaction($this->ledger, $rent, $this->member, -10);
        $foreign = $this->createTransaction($this->ledger, $rent, $this->user, -20);

        $this->ledger->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => ResourcePermissions::CONTRIBUTOR,
        ]);
        // The member recorded the entry on this envelope, so they hold
        // contributor access to it (docs/envelope.md).
        $rent->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR,
        ]);

        $results = $this->resultsOf([$own->id, $foreign->id], ['amount' => 7], $this->member);

        $this->assertSame('updated', $results[0]['status']);
        $this->assertSame('failed', $results[1]['status']);
        $this->assertSame('forbidden', $results[1]['code']);

        $this->assertSame(-7, $own->fresh()->amount);
        $this->assertSame(-20, $foreign->fresh()->amount);
    }

    public function test_viewers_and_implicit_members_get_forbidden_rows(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -10);

        foreach ([
            'explicit viewer' => true,
            'implicit viewer' => false,
        ] as $case => $explicit) {
            if ($explicit) {
                $this->ledger->permissions()->create([
                    'user_id' => $this->member->id,
                    'permission' => ResourcePermissions::VIEWER,
                ]);
            }

            $results = $this->resultsOf([$transaction->id], ['amount' => 7], $this->member);

            $this->assertSame('failed', $results[0]['status'], $case);
            $this->assertSame('forbidden', $results[0]['code'], $case);
            $this->assertSame(-10, $transaction->fresh()->amount);
        }
    }

    public function test_strangers_get_a_404(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -10);

        $this->update(['amount' => 7] + ['transaction_ids' => [$transaction->id]], $this->stranger)->assertNotFound();
        $this->assertSame(-10, $transaction->fresh()->amount);
    }

    public function test_a_closed_ledger_is_rejected_without_writes(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -10);
        $this->ledger->update(['closed_at' => now()]);

        $this->update(['amount' => 7] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ledger');

        $this->assertSame(-10, $transaction->fresh()->amount);
    }

    // ------------------------------------------------------------------
    // Payload and masking
    // ------------------------------------------------------------------

    public function test_results_carry_the_transaction_payload(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -10, '2026-06-10 00:00:00');

        $payload = $this->resultsOf([$transaction->id], ['amount' => 7])[0]['transaction'];

        $this->assertSame($transaction->id, $payload['id']);
        $this->assertSame($this->ledger->id, $payload['ledger_id']);
        $this->assertSame($rent->id, $payload['envelope_id']);
        $this->assertSame('Rent', $payload['name']);
        $this->assertSame('expense', $payload['direction']);
        $this->assertSame(-7, $payload['amount']);
        $this->assertFalse($payload['masked']);
        $this->assertFalse($payload['scheduled']);
        $this->assertSame('2026-06-10', $payload['event_at']);
        // remaining_allocated is a range-view concept; the update response
        // omits it like the create response does.
        $this->assertArrayNotHasKey('remaining_allocated', $payload);
    }

    public function test_rows_on_envelopes_the_caller_cannot_view_are_not_editable(): void
    {
        // Masked rows are not selectable in the UI and the server agrees:
        // updating one — let alone re-assigning its envelope — would
        // disclose its hidden amount (docs/ledger.md — amount masking).
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense', $this->member);
        $gas = $this->createEnvelope('Gas', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->member, -10);

        foreach ([
            ['amount' => 7],
            ['envelope_id' => $gas->id],
        ] as $payload) {
            $results = $this->resultsOf([$transaction->id], $payload);

            $this->assertSame('failed', $results[0]['status']);
            $this->assertSame('forbidden', $results[0]['code']);
            $this->assertArrayNotHasKey('transaction', $results[0]);
        }

        $fresh = $transaction->fresh();
        $this->assertSame(-10, $fresh->amount);
        $this->assertSame($rent->id, $fresh->envelope_id);
    }

    public function test_a_note_over_the_limit_is_rejected(): void
    {
        $this->ledger = $this->createLedger($this->user);
        $rent = $this->createEnvelope('Rent', 'expense');
        $transaction = $this->createTransaction($this->ledger, $rent, $this->user, -50, '2026-06-10 00:00:00', 'Original');

        $this->update(['note' => str_repeat('a', 201)] + ['transaction_ids' => [$transaction->id]], null, $this->ledger)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->assertSame('Original', $transaction->fresh()->note);
    }

    public function test_the_id_list_is_bounded(): void
    {
        $this->ledger = $this->createLedger($this->user);

        $this->update(['transaction_ids' => range(1, 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction_ids');
    }
}
