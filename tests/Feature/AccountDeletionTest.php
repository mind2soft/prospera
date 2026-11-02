<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetPermission;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

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

    private function createLedger(User $owner, FinancialProfile $profile, array $attributes = []): Ledger
    {
        $ledger = Ledger::create(array_merge([
            'profile_id' => $profile->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 0,
        ], $attributes));

        $ledger->permissions()->create([
            'user_id' => $owner->id,
            'permission' => 'owner',
        ]);

        return $ledger;
    }

    public function test_ownership_passes_to_the_earliest_joiner_when_the_owner_is_deleted(): void
    {
        $owner = User::factory()->create();
        $early = User::factory()->create();
        $late = User::factory()->create();

        $profile = FinancialProfile::createOwnedBy($owner);
        $profile->members()->attach($late->id, ['is_owner' => false]);
        $profile->members()->attach($early->id, ['is_owner' => false]);

        DB::table('financial_profile_user')->where('user_id', $late->id)->update(['created_at' => now()->subDay()]);
        DB::table('financial_profile_user')->where('user_id', $early->id)->update(['created_at' => now()->subDays(2)]);

        $budget = $this->shareBudget($owner, $profile);

        $this->actingAs($owner)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('financial_profiles', ['id' => $profile->id]);
        $this->assertTrue($profile->fresh()->isOwner($early));
        $this->assertFalse($profile->fresh()->isOwner($late));
        $this->assertDatabaseHas('budget_permissions', [
            'budget_id' => $budget->id,
            'user_id' => $early->id,
            'permission' => 'owner',
        ]);
    }

    public function test_ownership_tie_break_uses_the_lowest_user_id(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $profile = FinancialProfile::createOwnedBy($owner);
        $profile->members()->attach($second->id, ['is_owner' => false]);
        $profile->members()->attach($first->id, ['is_owner' => false]);

        $joinedAt = now()->subDay();
        DB::table('financial_profile_user')->where('user_id', $second->id)->update(['created_at' => $joinedAt]);
        DB::table('financial_profile_user')->where('user_id', $first->id)->update(['created_at' => $joinedAt]);

        $this->actingAs($owner)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        $this->assertTrue($profile->fresh()->isOwner($first));
        $this->assertFalse($profile->fresh()->isOwner($second));
    }

    public function test_a_solo_profile_is_deleted_along_with_the_owners_resources(): void
    {
        $owner = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($owner);

        $budget = $this->shareBudget($owner, $profile);
        $envelope = Envelope::create(['name' => 'Rent', 'type' => 'expense', 'user_id' => $owner->id]);
        $ledger = $this->createLedger($owner, $profile);

        $this->actingAs($owner)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseMissing('financial_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
        $this->assertDatabaseMissing('ledgers', ['id' => $ledger->id]);
        $this->assertDatabaseMissing('envelopes', ['id' => $envelope->id]);
    }

    public function test_ledgers_owned_by_the_deleted_user_transfer_to_the_new_profile_owner(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $profile = FinancialProfile::createOwnedBy($owner);
        $profile->members()->attach($member->id, ['is_owner' => false]);

        $ledger = $this->createLedger($member, $profile);

        $this->actingAs($member)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseHas('ledger_permissions', [
            'ledger_id' => $ledger->id,
            'user_id' => $owner->id,
            'permission' => 'owner',
        ]);
        $this->assertSame(ResourcePermissions::OWNER, $ledger->fresh()->permissionFor($owner));
    }

    public function test_transactions_of_the_deleted_user_survive_in_other_users_ledgers(): void
    {
        $owner = User::factory()->create();
        $entrant = User::factory()->create();

        $profile = FinancialProfile::createOwnedBy($owner);
        $budget = $this->shareBudget($owner, $profile, BudgetPermission::PERMISSION_OWNER);
        $budget->forceFill([
            'published_at' => now(),
            'effective_year' => now()->year,
            'effective_month' => now()->month,
        ])->save();

        $envelope = Envelope::create(['name' => 'Rent', 'type' => 'expense', 'user_id' => $owner->id]);
        $ledger = $this->createLedger($owner, $profile);

        $transaction = Transaction::create([
            'ledger_id' => $ledger->id,
            'user_id' => $entrant->id,
            'envelope_id' => $envelope->id,
            'amount' => -100,
            'event_at' => '2026-03-01 00:00:00',
        ]);

        $this->actingAs($entrant)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'user_id' => null]);
        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id]);
        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id]);

        // Deleted users display as "Former member".
        $this->actingAs($owner)->getJson("/api/ledgers/{$ledger->id}/transactions?from_date=2026-03-01&to_date=2026-03-31")->assertOk()->assertJsonPath('transactions.0.user.id', null)
            ->assertJsonPath('transactions.0.user.name', 'Former member');
    }

    public function test_referenced_envelopes_transfer_to_the_owner_of_the_earliest_referencing_profile(): void
    {
        $owner = User::factory()->create();
        $entrant = User::factory()->create();
        $otherOwner = User::factory()->create();

        $entrantProfile = FinancialProfile::createOwnedBy($entrant);
        $entrantProfile->members()->attach($owner->id, ['is_owner' => false]);

        $otherProfile = FinancialProfile::createOwnedBy($otherOwner, 'Other');

        // The entrant's envelope is referenced by the other profile's budget;
        // it must transfer to that profile's owner.
        $envelope = Envelope::create(['name' => 'Groceries', 'type' => 'expense', 'user_id' => $entrant->id]);

        $budget = $this->shareBudget($otherOwner, $otherProfile);
        $budget->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed']);

        $this->actingAs($entrant)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseHas('envelopes', [
            'id' => $envelope->id,
            'user_id' => $otherOwner->id,
            'name' => 'Groceries',
        ]);
    }

    public function test_envelope_name_collisions_are_resolved_with_the_imported_suffix(): void
    {
        $owner = User::factory()->create();
        $entrant = User::factory()->create();

        $profile = FinancialProfile::createOwnedBy($owner);
        $profile->members()->attach($entrant->id, ['is_owner' => false]);

        Envelope::create(['name' => 'Groceries', 'type' => 'expense', 'user_id' => $owner->id]);
        $envelope = Envelope::create(['name' => 'Groceries', 'type' => 'expense', 'user_id' => $entrant->id]);

        // The collision only matters for referenced envelopes: unreferenced
        // ones are deleted with the account.
        $budget = $this->shareBudget($owner, $profile);
        $budget->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed']);

        $this->actingAs($entrant)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseHas('envelopes', [
            'id' => $envelope->id,
            'user_id' => $owner->id,
            'name' => 'Groceries (imported)',
        ]);
    }

    public function test_unreferenced_envelopes_are_deleted_with_the_account(): void
    {
        $entrant = User::factory()->create();

        $envelope = Envelope::create(['name' => 'Savings', 'type' => 'revenue', 'user_id' => $entrant->id]);

        $this->actingAs($entrant)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseMissing('envelopes', ['id' => $envelope->id]);
    }
}
