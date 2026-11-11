<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Auth\Events\Verified;
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
        $this->assertSame(
            ResourcePermissions::NONE,
            Envelope::permissionsFor($this->alice, [$envelope->id])->get($envelope->id),
        );
        $this->assertNotContains($envelope->id, $this->listedEnvelopeIds($this->alice));
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
        $listed = collect($this->actingAs($this->alice)->getJson('/api/envelopes')->assertOk()->json('envelopes'))
            ->firstWhere('id', $envelope->id);
        $this->assertSame(ResourcePermissions::NONE, $listed['permission'] ?? ResourcePermissions::NONE);
    }

    /**
     * @return list<int>
     */
    private function listedEnvelopeIds(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/envelopes')->assertOk()->json('envelopes'))
            ->pluck('id')
            ->all();
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
    // WF-7 — the draft budget follows profile ownership
    // ------------------------------------------------------------------

    public function test_the_draft_budget_follows_an_explicit_profile_transfer(): void
    {
        $draftId = $this->actingAs($this->alice)
            ->postJson('/api/budgets', ['profile_id' => $this->profile->id, 'currency' => 'CAD'])
            ->assertCreated()
            ->json('budget.id');

        $this->actingAs($this->alice)
            ->postJson("/api/profiles/{$this->profile->id}/transfer-ownership", ['user_id' => $this->bob->id])
            ->assertOk();

        $draft = Budget::query()->findOrFail($draftId);
        $this->assertSame(ResourcePermissions::OWNER, $draft->permissionFor($this->bob));
        $this->assertSame(ResourcePermissions::CONTRIBUTOR, $draft->permissionFor($this->alice));

        // The new profile owner is no longer deadlocked on the draft slot.
        $this->actingAs($this->bob)->postJson("/api/budgets/{$draftId}/publish")->assertOk();
    }

    public function test_the_draft_budget_follows_the_ownership_hand_off_on_account_deletion(): void
    {
        $draftId = $this->actingAs($this->alice)
            ->postJson('/api/budgets', ['profile_id' => $this->profile->id, 'currency' => 'CAD'])
            ->assertCreated()
            ->json('budget.id');

        $this->actingAs($this->alice)
            ->delete('/settings/account', ['password' => 'password'])
            ->assertRedirect('/');

        $this->actingAs($this->bob)->postJson("/api/budgets/{$draftId}/publish")->assertOk();
    }

    public function test_published_budgets_keep_their_owner_on_profile_transfer(): void
    {
        $published = Budget::create([
            'profile_id' => $this->profile->id,
            'currency' => 'CAD',
            'effective_year' => now()->year,
            'effective_month' => now()->month,
            'published_at' => now(),
        ]);
        $published->permissions()->create(['user_id' => $this->alice->id, 'permission' => ResourcePermissions::OWNER]);

        $this->profile->transferOwnershipTo($this->bob);

        $this->assertSame(ResourcePermissions::OWNER, $published->fresh()->permissionFor($this->alice));
    }

    // ------------------------------------------------------------------
    // WF-9 — pending invites survive the inviter's account deletion
    // ------------------------------------------------------------------

    public function test_pending_invites_survive_the_owners_account_deletion(): void
    {
        $newcomer = User::factory()->unverified()->create(['email' => 'newcomer@example.com']);
        $reserved = FinancialProfileInvite::create([
            'profile_id' => $this->profile->id,
            'invite_key' => FinancialProfileInvite::keyHash('reserved-key'),
            'email' => 'newcomer@example.com',
            'invited_by' => $this->alice->id,
            'pending_user_id' => $newcomer->id,
            'expires_at' => now()->addHours(48),
        ]);
        $open = FinancialProfileInvite::create([
            'profile_id' => $this->profile->id,
            'invite_key' => FinancialProfileInvite::keyHash('open-key'),
            'email' => 'later@example.com',
            'invited_by' => $this->alice->id,
            'expires_at' => now()->addHours(48),
        ]);

        $this->actingAs($this->alice)
            ->delete('/settings/account', ['password' => 'password'])
            ->assertRedirect('/');

        // Re-attributed to the profile's new owner (the earliest joiner).
        $this->assertSame($this->bob->id, $reserved->fresh()?->invited_by);
        $this->assertSame($this->bob->id, $open->fresh()?->invited_by);

        $newcomer->markEmailAsVerified();
        event(new Verified($newcomer));

        $this->assertTrue($this->profile->isMember($newcomer));
        $this->assertTrue($this->profile->isDefaultFor($newcomer));
    }

    public function test_invites_of_a_profile_deleted_with_the_account_go_with_it(): void
    {
        $solo = FinancialProfile::createOwnedBy($this->alice, 'Solo');
        $invite = FinancialProfileInvite::create([
            'profile_id' => $solo->id,
            'invite_key' => FinancialProfileInvite::keyHash('solo-key'),
            'email' => 'friend@example.com',
            'invited_by' => $this->alice->id,
            'expires_at' => now()->addHours(48),
        ]);

        $this->actingAs($this->alice)
            ->delete('/settings/account', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertModelMissing($invite);
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
