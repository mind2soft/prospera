<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the authorization and integrity hardening:
 * owner-only profile-wide writes, envelope contributor checks, invite
 * hygiene, ownership transfers and input validation.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);
    }

    private function budget(User $owner, bool $published = true): Budget
    {
        $budget = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => $published ? now()->year : 0,
            'effective_month' => $published ? now()->month : 0,
            'published_at' => $published ? now() : null,
        ]);

        $budget->permissions()->create(['user_id' => $owner->id, 'permission' => ResourcePermissions::OWNER]);

        return $budget;
    }

    private function ledger(User $owner): Ledger
    {
        $ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => 'Ledger',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'starting_balance' => 0,
        ]);

        $ledger->permissions()->create(['user_id' => $owner->id, 'permission' => ResourcePermissions::OWNER]);

        return $ledger;
    }

    // ------------------------------------------------------------------
    // Profile-wide writes are owner-only
    // ------------------------------------------------------------------

    public function test_collaborators_cannot_create_ledgers(): void
    {
        $this->actingAs($this->member)->postJson('/api/ledgers', [
            'profile_id' => $this->profile->id,
            'name' => 'Mine',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
            'starting_balance_type' => 'cash',
        ])->assertForbidden();

        $this->assertDatabaseCount('ledgers', 0);
    }

    public function test_collaborators_owning_a_draft_cannot_publish_over_the_owners_budget(): void
    {
        $published = $this->budget($this->owner);
        $draft = $this->budget($this->owner, published: false);
        $draft->transferOwnershipTo($this->member);

        $this->actingAs($this->member)->postJson("/api/budgets/{$draft->id}/publish")->assertForbidden();

        $this->assertDatabaseHas('budgets', ['id' => $published->id]);
    }

    public function test_collaborators_cannot_clone_budgets(): void
    {
        $published = $this->budget($this->owner);

        $this->actingAs($this->member)->postJson("/api/budgets/{$published->id}/clone")->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Envelope contributor checks
    // ------------------------------------------------------------------

    public function test_ledger_owners_cannot_record_against_metadata_only_envelopes(): void
    {
        $foreign = $this->member->envelopes()->create(['name' => 'Theirs', 'type' => 'expense', 'color' => '#000000']);

        $budget = $this->budget($this->owner);
        $budget->envelopes()->create(['envelope_id' => $foreign->id, 'amount' => -100, 'type' => 'fixed']);
        $ledger = $this->ledger($this->owner);

        $this->actingAs($this->owner)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $foreign->id,
            'amount' => 10,
            'event_at' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('envelope_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_envelope_totals_validate_dates(): void
    {
        $envelope = $this->owner->envelopes()->create(['name' => 'Mine', 'type' => 'expense', 'color' => '#000000']);

        $this->actingAs($this->owner)->getJson("/api/envelopes/{$envelope->id}/totals?from_date=not-a-date")
            ->assertUnprocessable()->assertJsonValidationErrors('from_date');

        $this->actingAs($this->owner)->getJson("/api/envelopes/{$envelope->id}/totals")
            ->assertOk()->assertJsonPath('totals.total', 0)->assertJsonPath('totals.count', 0);
    }

    // ------------------------------------------------------------------
    // Ownership transfers
    // ------------------------------------------------------------------

    public function test_envelope_transfer_drops_the_new_owners_stale_share_row(): void
    {
        $envelope = $this->owner->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#000000']);
        $envelope->permissions()->create(['user_id' => $this->member->id, 'permission' => ResourcePermissions::VIEWER]);

        $this->actingAs($this->owner)->postJson("/api/envelopes/{$envelope->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertOk();

        $this->assertDatabaseMissing('envelope_permissions', ['envelope_id' => $envelope->id, 'user_id' => $this->member->id]);
        $this->assertDatabaseHas('envelope_permissions', [
            'envelope_id' => $envelope->id,
            'user_id' => $this->owner->id,
            'permission' => ResourcePermissions::CONTRIBUTOR,
        ]);
    }

    public function test_leaving_keeps_envelopes_still_used_in_the_members_other_profiles(): void
    {
        $envelope = $this->member->envelopes()->create(['name' => 'Shared', 'type' => 'expense', 'color' => '#000000']);

        $here = $this->budget($this->owner);
        $here->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed']);

        $other = FinancialProfile::createOwnedBy($this->member, 'Mine');
        $elsewhere = Budget::create(['profile_id' => $other->id, 'currency' => 'CAD', 'effective_year' => 0, 'effective_month' => 0]);
        $elsewhere->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed']);

        $this->actingAs($this->member)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->member->id}")
            ->assertNoContent();

        $this->assertSame($this->member->id, $envelope->fresh()->user_id);
        $this->assertSame(ResourcePermissions::CONTRIBUTOR, $envelope->fresh()->permissionFor($this->owner));
    }

    // ------------------------------------------------------------------
    // Bulk deletion
    // ------------------------------------------------------------------

    public function test_deleting_a_ledger_cascades_its_transactions(): void
    {
        $envelope = $this->owner->envelopes()->create(['name' => 'Rent', 'type' => 'expense', 'color' => '#000000']);
        $ledger = $this->ledger($this->owner);

        Transaction::create([
            'ledger_id' => $ledger->id, 'user_id' => $this->owner->id, 'envelope_id' => $envelope->id,
            'amount' => -10, 'event_at' => now()->startOfDay(),
        ]);

        $this->actingAs($this->owner)->deleteJson("/api/ledgers/{$ledger->id}")->assertNoContent();

        $this->assertDatabaseCount('transactions', 0);
    }

    // ------------------------------------------------------------------
    // Invites
    // ------------------------------------------------------------------

    public function test_existing_members_cannot_be_invited(): void
    {
        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => strtoupper($this->member->email),
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_re_inviting_an_email_replaces_the_previous_invite(): void
    {
        foreach ([1, 2] as $_) {
            $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
                'email' => 'new@example.com',
            ])->assertCreated();
        }

        $this->assertSame(1, FinancialProfileInvite::query()->where('email', 'new@example.com')->count());
    }

    public function test_reserved_invites_cannot_be_reused(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'new@example.com']);
        FinancialProfileInvite::create([
            'profile_id' => $this->profile->id,
            'invite_key' => FinancialProfileInvite::keyHash('k'),
            'email' => 'new@example.com',
            'invited_by' => $this->owner->id,
            'pending_user_id' => $user->id,
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/invites/k')->assertNotFound();
    }

    public function test_prune_removes_expired_and_stale_reserved_invites(): void
    {
        $make = fn (string $key, $expiresAt, ?int $pending = null) => FinancialProfileInvite::create([
            'profile_id' => $this->profile->id,
            'invite_key' => FinancialProfileInvite::keyHash($key),
            'email' => $key.'@example.com',
            'invited_by' => $this->owner->id,
            'pending_user_id' => $pending,
            'expires_at' => $expiresAt,
        ]);

        $live = $make('live', now()->addDay());
        $make('expired', now()->subMinute());
        $recentReservation = $make('recent', now()->subDay(), User::factory()->unverified()->create()->id);
        $make('stale', now()->subWeeks(2), User::factory()->unverified()->create()->id);

        $this->artisan('invites:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$live->id, $recentReservation->id],
            FinancialProfileInvite::query()->pluck('id')->all(),
        );
    }
}
