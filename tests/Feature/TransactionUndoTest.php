<?php

namespace Tests\Feature;

use App\Actions\DeleteAccount;
use App\Models\Budget;
use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountKeyRing;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Undoable transaction deletion (issue #48, docs/undoable-deletion.md).
 */
class TransactionUndoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private FinancialProfile $profile;

    private Ledger $ledger;

    private Envelope $envelope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);

        $this->ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2026-12-31 00:00:00',
            'starting_balance' => 5000,
        ]);
        $this->ledger->permissions()->create(['user_id' => $this->owner->id, 'permission' => 'owner']);

        $this->envelope = $this->owner->envelopes()->create(['name' => 'Food', 'type' => 'expense', 'color' => '#f43f5e']);
    }

    private function record(?User $user = null, float $amount = -12.34, ?Envelope $envelope = null): Transaction
    {
        return $this->ledger->transactions()->create([
            'user_id' => ($user ?? $this->owner)->id,
            'envelope_id' => ($envelope ?? $this->envelope)->id,
            'amount' => $amount,
            'event_at' => '2026-06-15 00:00:00',
        ]);
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $user): array
    {
        return $this->actingAs($user)
            ->getJson("/api/ledgers/{$this->ledger->id}/transactions?from_date=2026-06-01&to_date=2026-06-30")
            ->assertOk()
            ->json('transactions.*.id');
    }

    public function test_deleting_a_transaction_hides_it_but_keeps_it_restorable(): void
    {
        $transaction = $this->record();

        $this->actingAs($this->owner)->deleteJson("/api/transactions/{$transaction->id}")->assertNoContent();

        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
        $this->assertSame([], $this->listedIds($this->owner));

        // A pending deletion cannot be deleted a second time.
        $this->actingAs($this->owner)->deleteJson("/api/transactions/{$transaction->id}")->assertNotFound();
    }

    public function test_a_deleted_transaction_can_be_restored_within_the_undo_window(): void
    {
        $transaction = $this->record();
        $this->actingAs($this->owner)->deleteJson("/api/transactions/{$transaction->id}")->assertNoContent();

        $this->travel(Transaction::UNDO_WINDOW_SECONDS - 5)->seconds();

        $this->actingAs($this->owner)->postJson("/api/transactions/{$transaction->id}/restore")->assertNoContent();

        $this->assertNotSoftDeleted('transactions', ['id' => $transaction->id]);
        $this->assertSame([$transaction->id], $this->listedIds($this->owner));

        app(AmountKeyRing::class)->forget();
        $this->assertSame(-12.34, Transaction::query()->findOrFail($transaction->id)->amount);
    }

    public function test_restoring_after_the_undo_window_is_refused(): void
    {
        $transaction = $this->record();
        $this->actingAs($this->owner)->deleteJson("/api/transactions/{$transaction->id}")->assertNoContent();

        $this->travel(Transaction::UNDO_WINDOW_SECONDS)->seconds();

        $this->actingAs($this->owner)->postJson("/api/transactions/{$transaction->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');

        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
    }

    public function test_restoring_a_live_transaction_is_a_no_op_and_a_purged_one_is_gone(): void
    {
        $live = $this->record();
        $updatedAt = DB::table('transactions')->where('id', $live->id)->value('updated_at');

        $this->travel(1)->minutes();
        $this->actingAs($this->owner)->postJson("/api/transactions/{$live->id}/restore")->assertNoContent();
        $this->assertSame($updatedAt, DB::table('transactions')->where('id', $live->id)->value('updated_at'));

        $purged = $this->record();
        $purged->forceDelete();

        $this->actingAs($this->owner)->postJson("/api/transactions/{$purged->id}/restore")->assertNotFound();
    }

    public function test_restoring_follows_the_deletion_rules(): void
    {
        $this->ledger->permissions()->create(['user_id' => $this->member->id, 'permission' => 'contributor']);
        EnvelopePermission::create(['envelope_id' => $this->envelope->id, 'user_id' => $this->member->id, 'permission' => 'contributor']);
        $ownerEntry = $this->record($this->owner);
        $memberEntry = $this->record($this->member);
        $ownerEntry->delete();
        $memberEntry->delete();

        $viewer = User::factory()->create();
        $this->profile->members()->attach($viewer->id, ['is_owner' => false]);
        $stranger = User::factory()->create();

        // Viewers and contributors on others' entries: forbidden; outsiders
        // do not see the ledger at all.
        $this->actingAs($viewer)->postJson("/api/transactions/{$memberEntry->id}/restore")->assertForbidden();
        $this->actingAs($this->member)->postJson("/api/transactions/{$ownerEntry->id}/restore")->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/transactions/{$ownerEntry->id}/restore")->assertNotFound();
        $this->assertSoftDeleted('transactions', ['id' => $ownerEntry->id]);

        // Contributors restore their own entries, the owner any entry.
        $this->actingAs($this->member)->postJson("/api/transactions/{$memberEntry->id}/restore")->assertNoContent();
        $this->actingAs($this->owner)->postJson("/api/transactions/{$ownerEntry->id}/restore")->assertNoContent();
        $this->assertNotSoftDeleted('transactions', ['id' => $ownerEntry->id]);
        $this->assertNotSoftDeleted('transactions', ['id' => $memberEntry->id]);
    }

    public function test_guests_cannot_restore(): void
    {
        $transaction = $this->record();
        $transaction->delete();

        $this->postJson("/api/transactions/{$transaction->id}/restore")->assertUnauthorized();
        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
    }

    public function test_closed_ledgers_cannot_restore_transactions(): void
    {
        $transaction = $this->record();
        $this->actingAs($this->owner)->deleteJson("/api/transactions/{$transaction->id}")->assertNoContent();
        $this->ledger->update(['closed_at' => now()]);

        $this->actingAs($this->owner)->postJson("/api/transactions/{$transaction->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ledger');

        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
    }

    public function test_the_purge_removes_only_deletions_past_the_undo_window(): void
    {
        $live = $this->record();
        $expired = $this->record();
        $expired->delete();

        $this->travel(Transaction::UNDO_WINDOW_SECONDS)->seconds();
        $recent = $this->record();
        $recent->delete();

        $this->artisan('transactions:purge-deleted')
            ->expectsOutputToContain('1 deleted transaction(s) purged.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('transactions', ['id' => $expired->id]);
        $this->assertSoftDeleted('transactions', ['id' => $recent->id]);
        $this->assertNotSoftDeleted('transactions', ['id' => $live->id]);
    }

    public function test_the_purge_runs_every_minute(): void
    {
        $purge = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'transactions:purge-deleted'));

        $this->assertNotNull($purge, 'transactions:purge-deleted is not scheduled');
        $this->assertSame('* * * * *', $purge->expression);
        $this->assertTrue($purge->withoutOverlapping);
        $this->assertSame(5, $purge->expiresAt);
        $this->assertTrue($purge->onOneServer);
    }

    public function test_pending_deletions_no_longer_reference_their_envelope(): void
    {
        $transaction = $this->record();

        $this->assertSame(1, $this->envelope->transactions()->count());

        $transaction->delete();

        $this->assertSame(0, $this->envelope->transactions()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertFalse($this->envelope->isReferenced());
    }

    public function test_deleting_a_ledger_purges_its_pending_deletions(): void
    {
        $transaction = $this->record();
        $transaction->delete();

        $this->actingAs($this->owner)->deleteJson("/api/ledgers/{$this->ledger->id}")->assertNoContent();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseMissing('ledgers', ['id' => $this->ledger->id]);
    }

    public function test_an_envelope_with_only_pending_deletions_can_be_deleted(): void
    {
        $transaction = $this->record();
        $transaction->delete();

        $this->actingAs($this->owner)->delete("/envelopes/{$this->envelope->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('envelopes', ['id' => $this->envelope->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_an_envelope_with_live_transactions_still_cannot_be_deleted(): void
    {
        $live = $this->record();
        $pending = $this->record();
        $pending->delete();

        $this->actingAs($this->owner)->delete("/envelopes/{$this->envelope->id}")->assertSessionHasErrors('envelope');

        // A refused deletion leaves the pending deletion restorable.
        $this->assertDatabaseHas('envelopes', ['id' => $this->envelope->id]);
        $this->assertNotSoftDeleted('transactions', ['id' => $live->id]);
        $this->assertSoftDeleted('transactions', ['id' => $pending->id]);
    }

    public function test_a_direction_change_purges_pending_deletions(): void
    {
        $transaction = $this->record();
        $transaction->delete();

        $this->actingAs($this->owner)->put("/envelopes/{$this->envelope->id}", [
            'name' => 'Food', 'type' => 'revenue', 'color' => '#f43f5e',
        ])->assertSessionHasNoErrors();

        $this->assertSame('revenue', $this->envelope->refresh()->type);
        // Restoring it would bring back a negative amount on a revenue envelope.
        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
        $this->actingAs($this->owner)->postJson("/api/transactions/{$transaction->id}/restore")->assertNotFound();
    }

    public function test_a_rename_keeps_pending_deletions_restorable(): void
    {
        $transaction = $this->record();
        $transaction->delete();

        $this->actingAs($this->owner)->put("/envelopes/{$this->envelope->id}", [
            'name' => 'Groceries', 'type' => 'expense', 'color' => '#f43f5e',
        ])->assertSessionHasNoErrors();

        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
    }

    public function test_account_deletion_purges_pending_deletions_on_the_deleted_envelopes(): void
    {
        // The member's envelope is only referenced by a pending deletion: it
        // is deleted with the account instead of tripping the foreign key.
        $envelope = $this->member->envelopes()->create(['name' => 'Fun', 'type' => 'expense', 'color' => '#f43f5e']);
        $transaction = $this->record($this->member, envelope: $envelope);
        $transaction->delete();

        app(DeleteAccount::class)->handle($this->member);

        $this->assertDatabaseMissing('users', ['id' => $this->member->id]);
        $this->assertDatabaseMissing('envelopes', ['id' => $envelope->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_account_deletion_transfers_envelopes_still_referenced_by_live_transactions(): void
    {
        $envelope = $this->member->envelopes()->create(['name' => 'Fun', 'type' => 'expense', 'color' => '#f43f5e']);
        $live = $this->record($this->member, envelope: $envelope);
        $pending = $this->record($this->member, envelope: $envelope);
        $pending->delete();

        app(DeleteAccount::class)->handle($this->member);

        // The live entry keeps the envelope in use: it goes to the profile
        // owner. The pending deletion is purged when the membership ends.
        $this->assertSame($this->owner->id, $envelope->refresh()->user_id);
        $this->assertNotSoftDeleted('transactions', ['id' => $live->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $pending->id]);
    }

    public function test_key_rotation_re_encrypts_pending_deletions(): void
    {
        $transaction = $this->record();
        $transaction->delete();

        $this->artisan('amounts:rotate-keys', ['--profile' => [$this->profile->id]])->assertSuccessful();

        $raw = (string) DB::table('transactions')->where('id', $transaction->id)->value('amount');
        $this->assertSame(2, AmountCipher::keyVersionOf($raw));

        $this->artisan('amounts:verify')->assertSuccessful();

        $this->actingAs($this->owner)->postJson("/api/transactions/{$transaction->id}/restore")->assertNoContent();
        app(AmountKeyRing::class)->forget();
        $this->assertSame(-12.34, Transaction::query()->findOrFail($transaction->id)->amount);
    }

    public function test_an_author_who_lost_envelope_access_cannot_restore_their_entry(): void
    {
        $this->ledger->permissions()->create(['user_id' => $this->member->id, 'permission' => 'contributor']);
        $grant = EnvelopePermission::create([
            'envelope_id' => $this->envelope->id,
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);
        $entry = $this->record($this->member);
        $this->actingAs($this->member)->deleteJson("/api/transactions/{$entry->id}")->assertNoContent();

        $grant->delete();

        $this->actingAs($this->member)->postJson("/api/transactions/{$entry->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('envelope_id');
        $this->assertSoftDeleted('transactions', ['id' => $entry->id]);

        // The ledger owner may still undo it: they delete (and so restore)
        // any entry, whatever their envelope access.
        $this->actingAs($this->owner)->postJson("/api/transactions/{$entry->id}/restore")->assertNoContent();
        $this->assertNotSoftDeleted('transactions', ['id' => $entry->id]);
    }

    public function test_leaving_a_profile_purges_pending_deletions_on_the_members_envelopes(): void
    {
        $envelope = $this->member->envelopes()->create(['name' => 'Fun', 'type' => 'expense', 'color' => '#f43f5e']);
        $pending = $this->record($this->member, envelope: $envelope);
        $pending->delete();
        $ownersPending = $this->record($this->owner);
        $ownersPending->delete();

        $this->actingAs($this->member)
            ->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->member->id}")
            ->assertNoContent();

        // Nothing references the envelope any more: it stays the member's,
        // and the entry cannot come back pointing at it.
        $this->assertSame($this->member->id, $envelope->refresh()->user_id);
        $this->assertDatabaseMissing('transactions', ['id' => $pending->id]);
        $this->actingAs($this->owner)->postJson("/api/transactions/{$pending->id}/restore")->assertNotFound();

        // Other pending deletions in the profile are untouched.
        $this->assertSoftDeleted('transactions', ['id' => $ownersPending->id]);
    }

    public function test_removing_a_member_purges_pending_deletions_but_hands_over_envelopes_in_use(): void
    {
        $envelope = $this->member->envelopes()->create(['name' => 'Fun', 'type' => 'expense', 'color' => '#f43f5e']);
        $live = $this->record($this->member, envelope: $envelope);
        $pending = $this->record($this->member, envelope: $envelope);
        $pending->delete();

        $this->actingAs($this->owner)
            ->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->member->id}")
            ->assertNoContent();

        $this->assertSame($this->owner->id, $envelope->refresh()->user_id);
        $this->assertNotSoftDeleted('transactions', ['id' => $live->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $pending->id]);
    }

    public function test_key_pruning_re_encrypts_pending_deletions_on_retired_versions(): void
    {
        $transaction = $this->record();
        $transaction->delete();

        $this->artisan('amounts:rotate-keys', ['--profile' => [$this->profile->id]])->assertSuccessful();

        // A straggler still on the retired v1 key.
        $keyV1 = app(AmountKeyRing::class)->key($this->profile->id, 1);
        $context = Transaction::amountContextFor('amount', (array) DB::table('transactions')->where('id', $transaction->id)->first(), $this->profile->id);
        DB::table('transactions')->where('id', $transaction->id)->update(['amount' => (new AmountCipher)->encrypt(-4321, $keyV1, $context)]);

        $this->travel(25)->hours();
        $this->artisan('amounts:prune-keys')->assertSuccessful();

        $raw = (string) DB::table('transactions')->where('id', $transaction->id)->value('amount');
        $this->assertSame(2, AmountCipher::keyVersionOf($raw));
        app(AmountKeyRing::class)->forget();
        $this->assertSame(-43.21, Transaction::withTrashed()->findOrFail($transaction->id)->amount);
    }

    public function test_pending_deletions_are_left_out_of_dashboard_aggregates(): void
    {
        $this->travelTo('2026-06-20 12:00:00');
        $this->record(amount: -10);
        $this->record(amount: -90)->delete();

        $budget = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => 2026,
            'effective_month' => 6,
            'published_at' => now(),
        ]);
        $budget->envelopes()->create(['envelope_id' => $this->envelope->id, 'amount' => -200, 'type' => 'fixed']);

        $widget = DashboardWidget::create([
            'user_id' => $this->owner->id,
            'financial_profile_id' => $this->profile->id,
            'type' => 'envelope_spending',
            'title' => null,
            'view_type' => 'single',
            'column_width' => 3,
            'period_type' => 'months',
            'period_range' => 1,
            'selection' => [['kind' => 'envelope', 'envelope_id' => $this->envelope->id]],
            'sort_order' => 0,
        ]);

        $this->actingAs($this->owner)
            ->getJson("/api/dashboard/{$this->profile->id}/widgets/{$widget->id}/data")
            ->assertOk()
            ->assertJsonPath('rows.0.spent', -10);
    }

    public function test_the_restore_window_and_the_purge_agree_on_the_boundary(): void
    {
        $transaction = $this->record();
        $transaction->delete();

        $this->travel(Transaction::UNDO_WINDOW_SECONDS - 1)->seconds();
        $this->assertTrue($transaction->refresh()->isRestorable());
        $this->assertSame(0, Transaction::purgeExpiredDeletions());

        $this->travel(1)->seconds();
        $this->assertFalse($transaction->refresh()->isRestorable());
        $this->assertSame(1, Transaction::purgeExpiredDeletions());
    }
}
