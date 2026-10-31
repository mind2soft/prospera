<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Enveloppe;
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
        $this->assertFalse($profile->is_default);
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

    public function test_the_default_profile_cannot_be_deleted_but_can_be_renamed(): void
    {
        $profile = FinancialProfile::createDefaultFor($this->owner);

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$profile->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('profile');

        $this->assertDatabaseHas('financial_profiles', ['id' => $profile->id]);

        $this->actingAs($this->owner)->patchJson("/api/profiles/{$profile->id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('profile.name', 'Renamed');
    }

    public function test_the_deletion_guard_uses_the_default_flag_not_the_name(): void
    {
        $profile = FinancialProfile::createOwnedBy($this->owner, FinancialProfile::DEFAULT_NAME);

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$profile->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('financial_profiles', ['id' => $profile->id]);
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
        // A member-owned budget and a member-owned enveloppe referenced by
        // it: both would transfer to the profile owner during removal; make
        // the enveloppe save fail mid-way.
        $enveloppe = Enveloppe::create(['name' => 'Shared', 'type' => 'expense', 'user_id' => $this->member->id]);
        $budget = $this->shareBudget($this->member, $this->profile);
        $budget->enveloppes()->create(['enveloppe_id' => $enveloppe->id, 'amount' => -100, 'type' => 'fixed']);

        Enveloppe::updated(fn () => throw new \RuntimeException('boom'));

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
        $this->assertDatabaseHas('enveloppes', [
            'id' => $enveloppe->id,
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

    public function test_non_owners_cannot_remove_other_members(): void
    {
        $other = User::factory()->create();
        $this->profile->members()->attach($other->id, ['is_owner' => false]);

        $this->actingAs($this->member)->deleteJson("/api/profiles/{$this->profile->id}/members/{$other->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('financial_profile_user', ['profile_id' => $this->profile->id, 'user_id' => $other->id]);
    }

    public function test_member_removal_transfers_only_profile_referenced_enveloppes(): void
    {
        $referenced = Enveloppe::create(['name' => 'Referenced', 'type' => 'expense', 'user_id' => $this->member->id]);
        $unreferenced = Enveloppe::create(['name' => 'Personal', 'type' => 'revenue', 'user_id' => $this->member->id]);

        $budget = $this->shareBudget($this->owner, $this->profile);
        $budget->enveloppes()->create(['enveloppe_id' => $referenced->id, 'amount' => -100, 'type' => 'fixed']);

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$this->profile->id}/members/{$this->member->id}")
            ->assertNoContent();

        // Referenced enveloppes transfer to the profile owner...
        $this->assertDatabaseHas('enveloppes', [
            'id' => $referenced->id,
            'user_id' => $this->owner->id,
        ]);
        // ...unreferenced ones stay owned by the removed member.
        $this->assertDatabaseHas('enveloppes', [
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
