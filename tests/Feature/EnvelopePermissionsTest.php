<?php

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
    // Transaction data never crosses profile boundaries (SEC-1)
    // ------------------------------------------------------------------

    /**
     * The envelope-wide totals/transactions endpoints returned every
     * transaction on the envelope across all ledgers of all profiles: an
     * envelope viewer could read another profile's entries. They had no
     * frontend caller and were removed; transaction data is only served
     * through profile-scoped endpoints (ledger, dashboard).
     */
    public function test_an_envelope_viewer_cannot_read_transactions_of_a_profile_they_are_not_a_member_of(): void
    {
        $carol = User::factory()->create();
        $private = FinancialProfile::createOwnedBy($this->owner, 'Private');
        $private->members()->attach($carol->id, ['is_owner' => false]);

        $this->envelope->permissions()->createMany([
            ['user_id' => $carol->id, 'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR],
            ['user_id' => $this->member->id, 'permission' => EnvelopePermission::PERMISSION_VIEWER],
        ]);

        $ledger = Ledger::create([
            'profile_id' => $private->id,
            'name' => 'Private 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
        ]);
        $ledger->permissions()->create(['user_id' => $this->owner->id, 'permission' => 'owner']);
        $ledger->transactions()->create([
            'user_id' => $carol->id,
            'envelope_id' => $this->envelope->id,
            'amount' => -12345,
            'event_at' => '2026-03-03 00:00:00',
        ]);

        $this->assertFalse(Route::has('api.envelopes.totals'));
        $this->assertFalse(Route::has('api.envelopes.transactions.index'));

        $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/transactions")
            ->assertNotFound()
            ->assertDontSee('12345');
        $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/totals")
            ->assertNotFound()
            ->assertDontSee('12345');

        // The profile-scoped ledger endpoint stays closed to non-members.
        $this->actingAs($this->member)
            ->getJson("/api/ledgers/{$ledger->id}/transactions?from_date=2026-01-01&to_date=2026-12-31")
            ->assertNotFound();

        // Control: a member of the private profile still reads it there.
        $this->actingAs($carol)
            ->getJson("/api/ledgers/{$ledger->id}/transactions?from_date=2026-03-01&to_date=2026-03-31")
            ->assertOk()
            ->assertJsonPath('transactions.0.amount', -12345);
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

    public function test_co_members_only_see_shares_of_people_sharing_a_profile_with_them(): void
    {
        // SEC-R2-1: the owner shares the envelope with Carol, a member of
        // another of their profiles the member does not belong to.
        $carol = User::factory()->create();
        $other = FinancialProfile::createOwnedBy($this->owner, 'Other');
        $other->members()->attach($carol->id, ['is_owner' => false]);
        $this->envelope->permissions()->create(['user_id' => $carol->id, 'permission' => EnvelopePermission::PERMISSION_CONTRIBUTOR]);
        $this->envelope->permissions()->create(['user_id' => $this->member->id, 'permission' => EnvelopePermission::PERMISSION_VIEWER]);

        $listed = fn (User $user): array => collect($this->actingAs($user)->getJson("/api/envelopes/{$this->envelope->id}/shares")
            ->assertOk()->json('shares'))->pluck('user_id')->all();

        $this->assertSame([$this->owner->id, $this->member->id], $listed($this->member));
        $this->assertStringNotContainsString($carol->email, (string) $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/shares")->getContent());

        // The owner sees every grant.
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->member->id, $carol->id], $listed($this->owner));

        // Once the member shares a profile with Carol, Carol is listed.
        FinancialProfile::createOwnedBy($carol, 'Carol')->members()->attach($this->member->id, ['is_owner' => false]);
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->member->id, $carol->id], $listed($this->member));
    }

    public function test_share_management_is_owner_only(): void
    {
        $this->envelope->permissions()->create([
            'user_id' => $this->member->id,
            'permission' => EnvelopePermission::PERMISSION_VIEWER,
        ]);

        // The member (co-member of the owner) can list shares but not
        // manage. The list anchors on the owner row, then explicit rows.
        $this->actingAs($this->member)->getJson("/api/envelopes/{$this->envelope->id}/shares")
            ->assertOk()->assertJsonCount(2, 'shares')
            ->assertJsonPath('shares.0.user_id', $this->owner->id)
            ->assertJsonPath('shares.0.permission', 'owner')
            ->assertJsonPath('shares.0.explicit', true)
            ->assertJsonPath('shares.1.user_id', $this->member->id)
            ->assertJsonPath('shares.1.permission', 'viewer')
            ->assertJsonPath('shares.1.explicit', true);

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
        ])->assertOk()->assertJsonPath('shares.1.permission', 'contributor');

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

    public function test_shares_always_include_the_owner_row(): void
    {
        // No explicit rows yet — the drawer still anchors on the owner.
        $this->actingAs($this->owner)->getJson("/api/envelopes/{$this->envelope->id}/shares")
            ->assertOk()->assertJsonCount(1, 'shares')
            ->assertJsonPath('shares.0.user_id', $this->owner->id)
            ->assertJsonPath('shares.0.permission', 'owner')
            ->assertJsonPath('shares.0.explicit', true);
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
