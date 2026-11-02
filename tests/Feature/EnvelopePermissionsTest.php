<?php

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvelopePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $stranger;

    private FinancialProfile $profile;

    private Envelope $envelope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);

        $this->envelope = $this->owner->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#f43f5e',
        ]);
    }

    private function createLedgerWithTransaction(int $amount, string $eventAt = '2026-06-15'): Transaction
    {
        $ledger = Ledger::create([
            'profile_id' => $this->profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
        ]);
        $ledger->permissions()->create(['user_id' => $this->owner->id, 'permission' => 'owner']);

        return $ledger->transactions()->create([
            'user_id' => $this->owner->id,
            'envelope_id' => $this->envelope->id,
            'amount' => $amount,
            'event_at' => $eventAt.' 00:00:00',
        ]);
    }

    // ------------------------------------------------------------------
    // GET /api/envelopes
    // ------------------------------------------------------------------

    public function test_api_index_lists_own_shared_and_metadata_only_envelopes_with_permissions(): void
    {
        $this->owner->envelopes()->create(['name' => 'Own', 'type' => 'revenue', 'color' => '#111111']);

        // Shared with the member: contributor.
        $this->envelope->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR,
        ]);
        $shared = $this->owner->envelopes()->create(['name' => 'Shared', 'type' => 'expense', 'color' => '#222222']);
        $shared->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        // Metadata-only: the member shares a profile with the owner but has
        // no permission row on this envelope.
        $metadataOnly = $this->owner->envelopes()->create(['name' => 'Hidden', 'type' => 'expense', 'color' => '#333333']);

        // The stranger's envelope is not visible at all.
        $strangerEnvelope = $this->stranger->envelopes()->create(['name' => 'Theirs', 'type' => 'expense', 'color' => '#444444']);

        $response = $this->actingAs($this->member)->getJson('/api/envelopes');

        $response->assertOk();
        $rows = collect($response->json('envelopes'))->keyBy('id');

        $this->assertEquals('contributor', $rows->get($this->envelope->id)['permission']);
        $this->assertEquals('viewer', $rows->get($shared->id)['permission']);
        $this->assertEquals('none', $rows->get($metadataOnly->id)['permission']);
        $this->assertNull($rows->get($strangerEnvelope->id));

        // The owner sees "owner" on their own envelopes.
        $ownerResponse = $this->actingAs($this->owner)->getJson('/api/envelopes');
        $ownerRows = collect($ownerResponse->json('envelopes'))->keyBy('id');
        $this->assertEquals('owner', $ownerRows->get($this->envelope->id)['permission']);
    }

    // ------------------------------------------------------------------
    // Totals & transactions endpoints
    // ------------------------------------------------------------------

    public function test_totals_are_403_without_envelope_viewer_permission(): void
    {
        $this->createLedgerWithTransaction(-100);

        // Co-member without a permission row: metadata-only.
        $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/totals")
            ->assertForbidden();

        // A stranger: also forbidden (never leaks amounts).
        $this->actingAs($this->stranger)->getJson("/api/envelopes/{$this->envelope->id}/totals")
            ->assertForbidden();

        // With viewer permission: allowed.
        $this->envelope->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        $response = $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/totals");

        $response->assertOk()
            ->assertJsonPath('totals.total', -100)
            ->assertJsonPath('totals.direction', 'expense')
            ->assertJsonPath('totals.count', 1);
    }

    public function test_totals_support_date_filters(): void
    {
        $this->createLedgerWithTransaction(-100, '2026-05-10');
        $this->createLedgerWithTransaction(-50, '2026-06-20');

        $this->actingAs($this->owner)->getJson(
            "/api/envelopes/{$this->envelope->id}/totals?from_date=2026-06-01&to_date=2026-06-30"
        )->assertOk()->assertJsonPath('totals.total', -50)->assertJsonPath('totals.count', 1);
    }

    public function test_transactions_endpoint_is_403_without_envelope_viewer_permission(): void
    {
        $this->createLedgerWithTransaction(-100);

        $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/transactions")
            ->assertForbidden();

        $this->envelope->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        $response = $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/transactions");

        $response->assertOk();
        $row = $response->json('transactions.0');
        $this->assertEquals(-100, $row['amount']);
        $this->assertEquals($this->owner->id, $row['user']['id']);
        $this->assertEquals($this->owner->name, $row['user']['name']);
    }

    // ------------------------------------------------------------------
    // Shares CRUD
    // ------------------------------------------------------------------

    public function test_shares_must_target_profile_members_of_the_owner(): void
    {
        $this->actingAs($this->owner)->postJson("/api/envelopes/{$this->envelope->id}/shares", [
            'user_id' => $this->stranger->id,
            'permission' => 'viewer',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->actingAs($this->owner)->postJson("/api/envelopes/{$this->envelope->id}/shares", [
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ])->assertCreated();

        $this->assertDatabaseHas('envelope_permissions', [
            'envelope_id' => $this->envelope->id,
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);
    }

    public function test_the_owner_permission_cannot_be_granted(): void
    {
        $this->actingAs($this->owner)->postJson("/api/envelopes/{$this->envelope->id}/shares", [
            'user_id' => $this->member->id,
            'permission' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors('permission');
    }

    public function test_share_management_is_owner_only(): void
    {
        $this->envelope->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        // The member (co-member of the owner) can list shares but not manage.
        $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/shares")
            ->assertOk()->assertJsonCount(1, 'shares');

        $this->actingAs($this->member)->patchJson("/api/envelopes/{$this->envelope->id}/shares/{$this->member->id}", [
            'permission' => 'contributor',
        ])->assertNotFound();

        $this->actingAs($this->member)->deleteJson("/api/envelopes/{$this->envelope->id}/shares/{$this->member->id}")
            ->assertNotFound();

        // Strangers cannot even list.
        $this->actingAs($this->stranger)->getJson("/api/envelopes/{$this->envelope->id}/shares")
            ->assertNotFound();
    }

    public function test_owner_can_update_and_revoke_shares(): void
    {
        $this->envelope->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        $this->actingAs($this->owner)->patchJson("/api/envelopes/{$this->envelope->id}/shares/{$this->member->id}", [
            'permission' => 'contributor',
        ])->assertOk()->assertJsonPath('shares.0.permission', 'contributor');

        $this->assertDatabaseHas('envelope_permissions', [
            'envelope_id' => $this->envelope->id,
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);

        $this->actingAs($this->owner)->deleteJson("/api/envelopes/{$this->envelope->id}/shares/{$this->member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('envelope_permissions', [
            'envelope_id' => $this->envelope->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_ownership_transfer_demotes_the_previous_owner_to_contributor(): void
    {
        $this->actingAs($this->owner)->postJson("/api/envelopes/{$this->envelope->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertOk();

        $this->envelope->refresh();
        $this->assertTrue($this->envelope->isOwner($this->member));
        $this->assertEquals('Rent', $this->envelope->name);

        $this->assertDatabaseHas('envelope_permissions', [
            'envelope_id' => $this->envelope->id,
            'user_id' => $this->owner->id,
            'permission' => 'contributor',
        ]);
    }

    public function test_ownership_transfer_resolves_name_collisions(): void
    {
        $this->member->envelopes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#111111',
        ]);

        $this->actingAs($this->owner)->postJson("/api/envelopes/{$this->envelope->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertOk();

        $this->assertDatabaseHas('envelopes', [
            'id' => $this->envelope->id,
            'user_id' => $this->member->id,
            'name' => 'Rent (imported)',
        ]);
    }

    public function test_ownership_transfer_requires_a_member_target(): void
    {
        $this->actingAs($this->owner)->postJson("/api/envelopes/{$this->envelope->id}/transfer-ownership", [
            'user_id' => $this->stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }
}
