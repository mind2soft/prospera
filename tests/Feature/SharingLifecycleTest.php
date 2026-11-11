<?php

namespace Tests\Feature;

use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the sharing lifecycle: what happens to grants and
 * per-member data when the profile relationship between two users ends.
 */
class SharingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->alice, 'Family', isDefault: true);
        $this->profile->members()->attach($this->bob->id, ['is_owner' => false]);
        FinancialProfile::createDefaultFor($this->bob);
    }

    private function envelopeOf(User $owner, string $name = 'Groceries'): Envelope
    {
        return $owner->envelopes()->create(['name' => $name, 'type' => 'expense', 'color' => '#000000']);
    }

    private function grant(Envelope $envelope, User $grantee, string $permission = ResourcePermissions::VIEWER): void
    {
        $envelope->permissions()->create(['user_id' => $grantee->id, 'permission' => $permission]);
    }

    private function privateTransaction(User $owner, Envelope $envelope, int $amount): void
    {
        $private = $owner->profiles()->wherePivot('is_owner', true)->whereKeyNot($this->profile->id)->firstOrFail();

        $ledger = Ledger::create([
            'profile_id' => $private->id,
            'name' => 'Private',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'starting_balance' => 0,
        ]);
        $ledger->permissions()->create(['user_id' => $owner->id, 'permission' => ResourcePermissions::OWNER]);

        $ledger->transactions()->create([
            'user_id' => $owner->id,
            'envelope_id' => $envelope->id,
            'amount' => $amount,
            'event_at' => now()->startOfDay(),
        ]);
    }

    // ------------------------------------------------------------------
    // SEC-2 — envelope grants end with the profile relationship
    // ------------------------------------------------------------------

    public function test_removing_a_member_drops_the_grants_they_made_to_former_co_members(): void
    {
        $envelope = $this->envelopeOf($this->bob);
        $this->grant($envelope, $this->alice);
        $this->privateTransaction($this->bob, $envelope, -500000);

        $this->actingAs($this->alice)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->bob->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('envelope_permissions', ['envelope_id' => $envelope->id, 'user_id' => $this->alice->id]);
        $this->assertSame(ResourcePermissions::NONE, $envelope->fresh()->permissionFor($this->alice));
        $this->actingAs($this->alice)->getJson("/api/envelopes/{$envelope->id}/transactions")->assertForbidden();
        $this->actingAs($this->alice)->getJson("/api/envelopes/{$envelope->id}/totals")->assertForbidden();
    }

    public function test_leaving_drops_grants_in_both_directions(): void
    {
        $bobs = $this->envelopeOf($this->bob, 'Bob');
        $alices = $this->envelopeOf($this->alice, 'Alice');
        $this->grant($bobs, $this->alice, ResourcePermissions::CONTRIBUTOR);
        $this->grant($alices, $this->bob);

        $this->actingAs($this->bob)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->bob->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('envelope_permissions', ['envelope_id' => $bobs->id, 'user_id' => $this->alice->id]);
        $this->assertDatabaseMissing('envelope_permissions', ['envelope_id' => $alices->id, 'user_id' => $this->bob->id]);
    }

    public function test_deleting_a_profile_drops_grants_between_users_who_no_longer_share_one(): void
    {
        $envelope = $this->envelopeOf($this->bob);
        $this->grant($envelope, $this->alice);

        $this->actingAs($this->alice)->deleteJson("/api/profiles/{$this->profile->id}")->assertNoContent();

        $this->assertDatabaseMissing('envelope_permissions', ['envelope_id' => $envelope->id, 'user_id' => $this->alice->id]);
    }

    public function test_deleting_a_profile_keeps_grants_between_users_still_sharing_another(): void
    {
        $other = FinancialProfile::createOwnedBy($this->alice, 'Other');
        $other->members()->attach($this->bob->id, ['is_owner' => false]);

        $envelope = $this->envelopeOf($this->bob);
        $this->grant($envelope, $this->alice);

        $this->actingAs($this->alice)->deleteJson("/api/profiles/{$this->profile->id}")->assertNoContent();

        $this->assertSame(ResourcePermissions::VIEWER, $envelope->fresh()->permissionFor($this->alice));
    }

    public function test_a_stale_grant_resolves_to_none(): void
    {
        $stranger = User::factory()->create();
        FinancialProfile::createDefaultFor($stranger);

        // A legacy row left over by the former cleanup: the users share no
        // profile any more.
        $envelope = $this->envelopeOf($stranger);
        $this->grant($envelope, $this->alice);

        $this->assertSame(ResourcePermissions::NONE, $envelope->permissionFor($this->alice));
        $this->assertSame(
            ResourcePermissions::NONE,
            Envelope::permissionsFor($this->alice, [$envelope->id])->get($envelope->id),
        );
        $this->actingAs($this->alice)->getJson("/api/envelopes/{$envelope->id}/transactions")->assertForbidden();
    }

    // ------------------------------------------------------------------
    // WF-1 — leaving one profile keeps grants still needed in another
    // ------------------------------------------------------------------

    public function test_leaving_one_profile_keeps_grants_between_users_sharing_another(): void
    {
        $other = FinancialProfile::createOwnedBy($this->alice, 'Other');
        $other->members()->attach($this->bob->id, ['is_owner' => false]);

        $envelope = $this->envelopeOf($this->alice);
        $this->grant($envelope, $this->bob, ResourcePermissions::CONTRIBUTOR);

        $ledger = Ledger::create([
            'profile_id' => $other->id,
            'name' => 'Other ledger',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'starting_balance' => 0,
        ]);
        $ledger->permissions()->create(['user_id' => $this->alice->id, 'permission' => ResourcePermissions::OWNER]);
        $ledger->permissions()->create(['user_id' => $this->bob->id, 'permission' => ResourcePermissions::CONTRIBUTOR]);

        $this->actingAs($this->bob)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->bob->id}")
            ->assertNoContent();

        $this->assertSame(ResourcePermissions::CONTRIBUTOR, $envelope->fresh()->permissionFor($this->bob));

        $this->actingAs($this->bob)->postJson("/api/ledgers/{$ledger->id}/transactions", [
            'envelope_id' => $envelope->id,
            'amount' => 10,
            'event_at' => now()->toDateString(),
        ])->assertCreated();
    }

    // ------------------------------------------------------------------
    // WF-8 — a member's dashboard widgets go with the membership
    // ------------------------------------------------------------------

    private function widgetFor(User $user, FinancialProfile $profile): DashboardWidget
    {
        return DashboardWidget::create([
            'user_id' => $user->id,
            'financial_profile_id' => $profile->id,
            'type' => 'radial',
            'title' => null,
            'view_type' => 'pie',
            'column_width' => 1,
            'period_type' => 'months',
            'period_range' => 1,
            'selection' => [],
            'sort_order' => 0,
        ]);
    }

    public function test_leaving_deletes_the_members_widgets_for_the_profile(): void
    {
        $leaving = $this->widgetFor($this->bob, $this->profile);
        $ownersWidget = $this->widgetFor($this->alice, $this->profile);
        $bobsOtherWidget = $this->widgetFor($this->bob, $this->bob->profiles()->whereKeyNot($this->profile->id)->firstOrFail());

        $this->actingAs($this->bob)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->bob->id}")
            ->assertNoContent();

        $this->assertModelMissing($leaving);
        $this->assertModelExists($ownersWidget);
        $this->assertModelExists($bobsOtherWidget);
    }

    public function test_removing_a_member_deletes_their_widgets_for_the_profile(): void
    {
        $widget = $this->widgetFor($this->bob, $this->profile);

        $this->actingAs($this->alice)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->bob->id}")
            ->assertNoContent();

        $this->assertModelMissing($widget);
    }
}
