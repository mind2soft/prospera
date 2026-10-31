<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Enveloppe;
use App\Models\EnveloppePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnveloppePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $stranger;

    private FinancialProfile $profile;

    private Enveloppe $enveloppe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);

        $this->enveloppe = $this->owner->enveloppes()->create([
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
            'enveloppe_id' => $this->enveloppe->id,
            'budget_id' => Budget::create([
                'profile_id' => $this->profile->id,
                'currency' => 'CAD',
                'effective_year' => 0,
                'effective_month' => 0,
            ])->id,
            'amount' => $amount,
            'event_at' => $eventAt.' 00:00:00',
        ]);
    }

    // ------------------------------------------------------------------
    // GET /api/enveloppes
    // ------------------------------------------------------------------

    public function test_api_index_lists_own_shared_and_metadata_only_enveloppes_with_permissions(): void
    {
        $this->owner->enveloppes()->create(['name' => 'Own', 'type' => 'revenue', 'color' => '#111111']);

        // Shared with the member: contributor.
        $this->enveloppe->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnveloppePermission::PERMISSION_CONTRIBUTOR,
        ]);
        $shared = $this->owner->enveloppes()->create(['name' => 'Shared', 'type' => 'expense', 'color' => '#222222']);
        $shared->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnveloppePermission::PERMISSION_VIEWER,
        ]);

        // Metadata-only: the member shares a profile with the owner but has
        // no permission row on this enveloppe.
        $metadataOnly = $this->owner->enveloppes()->create(['name' => 'Hidden', 'type' => 'expense', 'color' => '#333333']);

        // The stranger's enveloppe is not visible at all.
        $strangerEnveloppe = $this->stranger->enveloppes()->create(['name' => 'Theirs', 'type' => 'expense', 'color' => '#444444']);

        $response = $this->actingAs($this->member)->getJson('/api/enveloppes');

        $response->assertOk();
        $rows = collect($response->json('enveloppes'))->keyBy('id');

        $this->assertEquals('contributor', $rows->get($this->enveloppe->id)['permission']);
        $this->assertEquals('viewer', $rows->get($shared->id)['permission']);
        $this->assertEquals('none', $rows->get($metadataOnly->id)['permission']);
        $this->assertNull($rows->get($strangerEnveloppe->id));

        // The owner sees "owner" on their own enveloppes.
        $ownerResponse = $this->actingAs($this->owner)->getJson('/api/enveloppes');
        $ownerRows = collect($ownerResponse->json('enveloppes'))->keyBy('id');
        $this->assertEquals('owner', $ownerRows->get($this->enveloppe->id)['permission']);
    }

    // ------------------------------------------------------------------
    // Totals & transactions endpoints
    // ------------------------------------------------------------------

    public function test_totals_are_403_without_enveloppe_viewer_permission(): void
    {
        $this->createLedgerWithTransaction(-100);

        // Co-member without a permission row: metadata-only.
        $this->actingAs($this->member)->getJson("/api/enveloppes/{$this->enveloppe->id}/totals")
            ->assertForbidden();

        // A stranger: also forbidden (never leaks amounts).
        $this->actingAs($this->stranger)->getJson("/api/enveloppes/{$this->enveloppe->id}/totals")
            ->assertForbidden();

        // With viewer permission: allowed.
        $this->enveloppe->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnveloppePermission::PERMISSION_VIEWER,
        ]);

        $response = $this->actingAs($this->member)->getJson("/api/enveloppes/{$this->enveloppe->id}/totals");

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
            "/api/enveloppes/{$this->enveloppe->id}/totals?from_date=2026-06-01&to_date=2026-06-30"
        )->assertOk()->assertJsonPath('totals.total', -50)->assertJsonPath('totals.count', 1);
    }

    public function test_transactions_endpoint_is_403_without_enveloppe_viewer_permission(): void
    {
        $this->createLedgerWithTransaction(-100);

        $this->actingAs($this->member)->getJson("/api/enveloppes/{$this->enveloppe->id}/transactions")
            ->assertForbidden();

        $this->enveloppe->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnveloppePermission::PERMISSION_VIEWER,
        ]);

        $response = $this->actingAs($this->member)->getJson("/api/enveloppes/{$this->enveloppe->id}/transactions");

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
        $this->actingAs($this->owner)->postJson("/api/enveloppes/{$this->enveloppe->id}/shares", [
            'user_id' => $this->stranger->id,
            'permission' => 'viewer',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->actingAs($this->owner)->postJson("/api/enveloppes/{$this->enveloppe->id}/shares", [
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ])->assertCreated();

        $this->assertDatabaseHas('enveloppe_permissions', [
            'enveloppe_id' => $this->enveloppe->id,
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);
    }

    public function test_the_owner_permission_cannot_be_granted(): void
    {
        $this->actingAs($this->owner)->postJson("/api/enveloppes/{$this->enveloppe->id}/shares", [
            'user_id' => $this->member->id,
            'permission' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors('permission');
    }

    public function test_share_management_is_owner_only(): void
    {
        $this->enveloppe->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnveloppePermission::PERMISSION_VIEWER,
        ]);

        // The member (co-member of the owner) can list shares but not manage.
        $this->actingAs($this->member)->getJson("/api/enveloppes/{$this->enveloppe->id}/shares")
            ->assertOk()->assertJsonCount(1, 'shares');

        $this->actingAs($this->member)->patchJson("/api/enveloppes/{$this->enveloppe->id}/shares/{$this->member->id}", [
            'permission' => 'contributor',
        ])->assertNotFound();

        $this->actingAs($this->member)->deleteJson("/api/enveloppes/{$this->enveloppe->id}/shares/{$this->member->id}")
            ->assertNotFound();

        // Strangers cannot even list.
        $this->actingAs($this->stranger)->getJson("/api/enveloppes/{$this->enveloppe->id}/shares")
            ->assertNotFound();
    }

    public function test_owner_can_update_and_revoke_shares(): void
    {
        $this->enveloppe->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnveloppePermission::PERMISSION_VIEWER,
        ]);

        $this->actingAs($this->owner)->patchJson("/api/enveloppes/{$this->enveloppe->id}/shares/{$this->member->id}", [
            'permission' => 'contributor',
        ])->assertOk()->assertJsonPath('shares.0.permission', 'contributor');

        $this->assertDatabaseHas('enveloppe_permissions', [
            'enveloppe_id' => $this->enveloppe->id,
            'user_id' => $this->member->id,
            'permission' => 'contributor',
        ]);

        $this->actingAs($this->owner)->deleteJson("/api/enveloppes/{$this->enveloppe->id}/shares/{$this->member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('enveloppe_permissions', [
            'enveloppe_id' => $this->enveloppe->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_ownership_transfer_demotes_the_previous_owner_to_contributor(): void
    {
        $this->actingAs($this->owner)->postJson("/api/enveloppes/{$this->enveloppe->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertOk();

        $this->enveloppe->refresh();
        $this->assertTrue($this->enveloppe->isOwner($this->member));
        $this->assertEquals('Rent', $this->enveloppe->name);

        $this->assertDatabaseHas('enveloppe_permissions', [
            'enveloppe_id' => $this->enveloppe->id,
            'user_id' => $this->owner->id,
            'permission' => 'contributor',
        ]);
    }

    public function test_ownership_transfer_resolves_name_collisions(): void
    {
        $this->member->enveloppes()->create([
            'name' => 'Rent', 'type' => 'expense', 'color' => '#111111',
        ]);

        $this->actingAs($this->owner)->postJson("/api/enveloppes/{$this->enveloppe->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertOk();

        $this->assertDatabaseHas('enveloppes', [
            'id' => $this->enveloppe->id,
            'user_id' => $this->member->id,
            'name' => 'Rent (imported)',
        ]);
    }

    public function test_ownership_transfer_requires_a_member_target(): void
    {
        $this->actingAs($this->owner)->postJson("/api/enveloppes/{$this->enveloppe->id}/transfer-ownership", [
            'user_id' => $this->stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }
}
