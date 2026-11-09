<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $stranger;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);
    }

    private function shareBudget(User $user, FinancialProfile $profile, string $permission = BudgetPermission::PERMISSION_OWNER): Budget
    {
        $budget = Budget::create([
            'profile_id' => $profile->id,
            'currency' => 'CAD',
            'effective_year' => 0,
            'effective_month' => 0,
        ]);

        $budget->permissions()->create([
            'user_id' => $user->id,
            'permission' => $permission,
        ]);

        return $budget;
    }

    // ------------------------------------------------------------------
    // Index & create
    // ------------------------------------------------------------------

    public function test_index_lists_all_profiles_with_the_ownership_flag(): void
    {
        $shared = FinancialProfile::createOwnedBy($this->member, 'Shared');
        $shared->members()->attach($this->owner->id, ['is_owner' => false]);

        $default = FinancialProfile::createDefaultFor($this->owner);

        $response = $this->actingAs($this->owner)->getJson('/api/profiles');

        $response->assertOk();
        $profiles = collect($response->json('profiles'))->keyBy('id');

        $family = $profiles->get($this->profile->id);
        $this->assertEquals('Family', $family['name']);
        $this->assertTrue($family['is_owner']);
        $this->assertFalse($family['is_default']);

        $sharedRow = $profiles->get($shared->id);
        $this->assertFalse($sharedRow['is_owner']);

        $defaultRow = $profiles->get($default->id);
        $this->assertTrue($defaultRow['is_owner']);
        $this->assertTrue($defaultRow['is_default']);
    }

    public function test_store_creates_a_profile_with_the_creator_as_owner(): void
    {
        $response = $this->actingAs($this->stranger)->postJson('/api/profiles', [
            'name' => 'Business',
        ]);

        $response->assertCreated();
        $profile = FinancialProfile::where('name', 'Business')->firstOrFail();
        $this->assertTrue($profile->isOwner($this->stranger));
        // A new profile never becomes the creator's default implicitly.
        $this->assertFalse($profile->isDefaultFor($this->stranger));
    }

    public function test_store_requires_a_name(): void
    {
        $this->actingAs($this->owner)->postJson('/api/profiles', [])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_guests_cannot_access_the_api(): void
    {
        $this->getJson('/api/profiles')->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // Show & rename
    // ------------------------------------------------------------------

    public function test_members_can_show_a_profile(): void
    {
        $this->actingAs($this->member)->getJson("/api/profiles/{$this->profile->id}")
            ->assertOk()
            ->assertJsonPath('profile.id', $this->profile->id)
            ->assertJsonPath('profile.is_owner', false)
            ->assertJsonPath('profile.is_default', false);
    }

    public function test_non_members_get_a_404(): void
    {
        $this->actingAs($this->stranger)->getJson("/api/profiles/{$this->profile->id}")
            ->assertNotFound();
    }

    public function test_only_the_owner_can_rename_a_profile(): void
    {
        $this->actingAs($this->member)->patchJson("/api/profiles/{$this->profile->id}", ['name' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($this->stranger)->patchJson("/api/profiles/{$this->profile->id}", ['name' => 'Nope'])
            ->assertNotFound();

        $this->actingAs($this->owner)->patchJson("/api/profiles/{$this->profile->id}", ['name' => 'Household'])
            ->assertOk()->assertJsonPath('profile.name', 'Household');

        $this->assertDatabaseHas('financial_profiles', ['id' => $this->profile->id, 'name' => 'Household']);
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_is_owner_only(): void
    {
        $profile = FinancialProfile::createOwnedBy($this->owner, 'Empty');

        // A non-owner member of another profile of the same owner.
        $this->actingAs($this->member)->deleteJson("/api/profiles/{$this->profile->id}")
            ->assertForbidden();

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$profile->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('financial_profiles', ['id' => $profile->id]);
    }

    public function test_delete_is_blocked_while_budgets_exist(): void
    {
        $this->shareBudget($this->owner, $this->profile);

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$this->profile->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('profile');

        $this->assertDatabaseHas('financial_profiles', ['id' => $this->profile->id]);
    }

    public function test_delete_is_blocked_while_ledgers_exist(): void
    {
        Ledger::create([
            'profile_id' => $this->profile->id,
            'user_id' => $this->owner->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
        ]);

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$this->profile->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('profile');
    }

    public function test_the_default_profile_can_be_deleted_and_renamed(): void
    {
        $solo = User::factory()->create();
        $default = FinancialProfile::createDefaultFor($solo);

        // Renaming still works; the response carries the caller's flags.
        $this->actingAs($solo)->patchJson("/api/profiles/{$default->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('profile.name', 'Renamed')
            ->assertJsonPath('profile.is_default', true);

        // Deleting their only profile seeds a fresh default profile (the
        // old row-flag deletion guard is gone — issue #41).
        $this->actingAs($solo)->deleteJson("/api/profiles/{$default->id}")
            ->assertNoContent();

        $replacement = FinancialProfile::where('name', FinancialProfile::DEFAULT_NAME)->sole();
        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $replacement->id,
            'user_id' => $solo->id,
            'is_owner' => true,
            'is_default' => true,
        ]);
    }

    public function test_setting_the_default_prefers_the_earliest_created_owned_profile(): void
    {
        // A shared membership created *before* any owned profile must lose
        // to the owned-first rule when the default is deleted.
        $sharedFirst = FinancialProfile::createOwnedBy($this->owner, 'Shared first');
        $sharedFirst->members()->attach($this->member->id, ['is_owner' => false]);

        $ownedLater = FinancialProfile::createOwnedBy($this->owner, 'Owned later');
        $ownedLater->members()->attach($this->member->id, ['is_owner' => true]);

        $default = FinancialProfile::createDefaultFor($this->owner);
        $default->members()->attach($this->member->id, ['is_owner' => false, 'is_default' => true]);

        // The default profile is deleted: the member's default is reassigned
        // to their earliest *owned* profile, not to the earlier shared
        // membership.
        $default->delete();
        FinancialProfile::ensureDefaultFor($this->member);

        $this->assertTrue($ownedLater->isDefaultFor($this->member));
        $this->assertFalse($sharedFirst->isDefaultFor($this->member));
    }

    public function test_the_default_falls_back_to_the_earliest_membership_when_the_user_owns_nothing(): void
    {
        $invitee = User::factory()->create();

        $first = FinancialProfile::createOwnedBy($this->owner, 'First shared');
        $first->members()->attach($invitee->id, ['is_owner' => false, 'is_default' => true]);

        $second = FinancialProfile::createOwnedBy($this->owner, 'Second shared');
        $second->members()->attach($invitee->id, ['is_owner' => false, 'is_default' => false]);

        $first->delete();

        FinancialProfile::ensureDefaultFor($invitee);

        $this->assertTrue($second->isDefaultFor($invitee));
    }

    // ------------------------------------------------------------------
    // Atomicity
    // ------------------------------------------------------------------

    public function test_profile_creation_is_atomic_when_a_listener_fails_mid_way(): void
    {
        // setUp already created one profile ("Family").
        FinancialProfile::created(fn () => throw new \RuntimeException('boom'));

        try {
            FinancialProfile::createOwnedBy($this->owner, 'Broken');
            $this->fail('Expected the profile creation to fail.');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('financial_profiles', 1);
        $this->assertDatabaseCount('financial_profile_user', 2);
    }

    public function test_member_removal_is_atomic_when_a_transfer_fails(): void
    {
        // A member-owned budget and a member-owned envelope referenced by
        // it: both would transfer to the profile owner during removal; make
        // the envelope save fail mid-way.
        $envelope = Envelope::create(['name' => 'Shared', 'type' => 'expense', 'user_id' => $this->member->id]);
        $budget = $this->shareBudget($this->member, $this->profile);
        $budget->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed']);

        Envelope::updated(fn () => throw new \RuntimeException('boom'));

        try {
            $this->profile->removeMember($this->member);
            $this->fail('Expected the member removal to fail.');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $this->member->id,
        ]);
        // The budget ownership transfer is rolled back too.
        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $budget->id,
            'user_id' => $this->member->id,
            'permission' => 'owner',
        ]);
        $this->assertDatabaseHas('envelopes', [
            'id' => $envelope->id,
            'user_id' => $this->member->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Members
    // ------------------------------------------------------------------

    public function test_members_index_lists_members_with_ownership_and_join_date(): void
    {
        $response = $this->actingAs($this->member)->getJson("/api/profiles/{$this->profile->id}/members");

        $response->assertOk();
        $members = $response->json('members');
        $this->assertCount(2, $members);
        $this->assertEquals($this->owner->id, $members[0]['id']);
        $this->assertTrue($members[0]['is_owner']);
        $this->assertNotNull($members[0]['joined_at']);
        $this->assertEquals($this->member->email, $members[1]['email']);
        $this->assertFalse($members[1]['is_owner']);
    }

    public function test_members_index_is_limited_to_members(): void
    {
        $this->actingAs($this->stranger)->getJson("/api/profiles/{$this->profile->id}/members")
            ->assertNotFound();
    }

    public function test_owner_can_remove_a_member_and_their_owned_budgets_auto_transfer(): void
    {
        $memberBudget = $this->shareBudget($this->member, $this->profile);
        $memberBudget->permissions()->create(['user_id' => $this->owner->id, 'permission' => BudgetPermission::PERMISSION_VIEWER]);
        $memberBudget->permissions()->create(['user_id' => $this->stranger->id, 'permission' => BudgetPermission::PERMISSION_VIEWER]);

        $memberOtherBudget = $this->shareBudget($this->member, $this->profile);
        $memberOtherBudget->permissions()->create(['user_id' => $this->owner->id, 'permission' => BudgetPermission::PERMISSION_VIEWER]);

        // A stranger's share on a profile budget gets deleted on removal.
        $ownerBudget = $this->shareBudget($this->owner, $this->profile);
        $ownerBudget->permissions()->create(['user_id' => $this->member->id, 'permission' => BudgetPermission::PERMISSION_VIEWER]);

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->member->id}")
            ->assertNoContent();

        // Member removed from the profile.
        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $this->member->id,
        ]);

        // The member's owned budgets auto-transfer to the profile owner; the
        // owner's pre-existing viewer row is upgraded to owner.
        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $memberBudget->id,
            'user_id' => $this->owner->id,
            'permission' => 'owner',
        ]);
        $this->assertDatabaseMissing('budget_permissions', [
            'budget_id' => $memberBudget->id,
            'user_id' => $this->member->id,
        ]);
        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $memberBudget->id,
            'user_id' => $this->stranger->id,
            'permission' => 'viewer',
        ]);

        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $memberOtherBudget->id,
            'user_id' => $this->owner->id,
            'permission' => 'owner',
        ]);

        // The member's viewer row on the owner's budget is deleted.
        $this->assertDatabaseMissing('budget_permissions', [
            'budget_id' => $ownerBudget->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_member_can_leave_voluntarily_and_resources_transfer(): void
    {
        $budget = $this->shareBudget($this->member, $this->profile);

        $this->actingAs($this->member)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $this->member->id,
        ]);
        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $budget->id,
            'user_id' => $this->owner->id,
            'permission' => 'owner',
        ]);
    }

    public function test_the_owner_cannot_leave_the_profile(): void
    {
        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->owner->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $this->owner->id,
            'is_owner' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Zero-profile guarantee (invite registrations skip the default profile)
    // ------------------------------------------------------------------

    public function test_a_member_who_would_end_up_without_any_profile_gets_a_default_profile_seeded(): void
    {
        // Like an invite-registered account: no owned profile of their own.
        $invitee = User::factory()->create();
        $shared = FinancialProfile::createOwnedBy($this->owner, 'Shared');
        $shared->members()->attach($invitee->id, ['is_owner' => false]);

        $this->actingAs($invitee)->deleteJson("/api/profiles/{$shared->id}/members/{$invitee->id}")
            ->assertNoContent();

        $default = FinancialProfile::where('name', FinancialProfile::DEFAULT_NAME)->sole();
        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $default->id,
            'user_id' => $invitee->id,
            'is_owner' => true,
            'is_default' => true,
        ]);
    }

    public function test_a_leaving_member_who_keeps_another_profile_gets_it_as_their_new_default(): void
    {
        $own = FinancialProfile::createOwnedBy($this->member, 'Mine');

        $this->actingAs($this->member)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('financial_profiles', ['name' => FinancialProfile::DEFAULT_NAME]);
        $this->assertSame([$own->id], $this->member->profiles()->pluck('financial_profiles.id')->all());
        $this->assertTrue($own->isDefaultFor($this->member));
    }

    public function test_deleting_a_profile_seeds_stranded_members_a_default_profile(): void
    {
        $invitee = User::factory()->create();
        $shared = FinancialProfile::createOwnedBy($this->owner, 'Shared');
        $shared->members()->attach($invitee->id, ['is_owner' => false]);

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$shared->id}")
            ->assertNoContent();

        $default = FinancialProfile::where('name', FinancialProfile::DEFAULT_NAME)->sole();
        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $default->id,
            'user_id' => $invitee->id,
            'is_owner' => true,
            'is_default' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Set default endpoint (issue #41)
    // ------------------------------------------------------------------

    public function test_a_member_can_set_a_shared_profile_as_their_default(): void
    {
        FinancialProfile::createDefaultFor($this->member);

        $this->actingAs($this->member)->postJson("/api/profiles/{$this->profile->id}/set-default")
            ->assertOk()
            ->assertJsonPath('profile.is_default', true)
            ->assertJsonPath('profile.is_owner', false);

        $this->assertTrue($this->profile->isDefaultFor($this->member));
        // Exactly one default per user: the previous one was cleared.
        $this->assertDatabaseHas('financial_profile_user', [
            'user_id' => $this->member->id,
            'is_default' => true,
            'profile_id' => $this->profile->id,
        ]);
        $this->assertEquals(1, $this->member->profiles()->wherePivot('is_default', true)->count());
    }

    public function test_non_members_cannot_set_a_default(): void
    {
        $this->actingAs($this->stranger)->postJson("/api/profiles/{$this->profile->id}/set-default")
            ->assertNotFound();

        $this->assertFalse($this->profile->isDefaultFor($this->stranger));
    }

    public function test_non_owners_cannot_remove_other_members(): void
    {
        $other = User::factory()->create();
        $this->profile->members()->attach($other->id, ['is_owner' => false]);

        $this->actingAs($this->member)->deleteJson("/api/profiles/{$this->profile->id}/members/{$other->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('financial_profile_user', ['profile_id' => $this->profile->id, 'user_id' => $other->id]);
    }

    public function test_member_removal_transfers_only_profile_referenced_envelopes(): void
    {
        $referenced = Envelope::create(['name' => 'Referenced', 'type' => 'expense', 'user_id' => $this->member->id]);
        $unreferenced = Envelope::create(['name' => 'Personal', 'type' => 'revenue', 'user_id' => $this->member->id]);

        $budget = $this->shareBudget($this->owner, $this->profile);
        $budget->envelopes()->create(['envelope_id' => $referenced->id, 'amount' => -100, 'type' => 'fixed']);

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->member->id}")
            ->assertNoContent();

        // Referenced envelopes transfer to the profile owner...
        $this->assertDatabaseHas('envelopes', [
            'id' => $referenced->id,
            'user_id' => $this->owner->id,
        ]);
        // ...unreferenced ones stay owned by the removed member.
        $this->assertDatabaseHas('envelopes', [
            'id' => $unreferenced->id,
            'user_id' => $this->member->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Ownership transfer
    // ------------------------------------------------------------------

    public function test_ownership_can_be_transferred_to_a_member(): void
    {
        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertOk();

        $this->assertTrue($this->profile->fresh()->isOwner($this->member));
        $this->assertFalse($this->profile->fresh()->isOwner($this->owner));
    }

    public function test_transfer_ownership_requires_a_member_target(): void
    {
        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/transfer-ownership", [
            'user_id' => $this->stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_transfer_ownership_is_owner_only(): void
    {
        $this->actingAs($this->member)->postJson("/api/profiles/{$this->profile->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertForbidden();

        $this->actingAs($this->stranger)->postJson("/api/profiles/{$this->profile->id}/transfer-ownership", [
            'user_id' => $this->member->id,
        ])->assertNotFound();
    }
}
