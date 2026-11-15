<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Envelope name collision resolution on ownership transfer (#81): generated
 * names must stay within the schema's 60-character name limit, survive
 * repeated collisions, multibyte names and wildcard characters, and keep the
 * envelope's identity (id) so financial references stay valid.
 */
class EnvelopeTransferNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_max_length_collision_stays_within_the_name_limit_on_explicit_transfer(): void
    {
        $name = str_repeat('A', 60);
        $owner = User::factory()->create(['name' => 'Owner']);
        $target = User::factory()->create(['name' => 'Target']);

        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $owner->id]);
        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $target->id]);

        $envelope = Envelope::query()->where('user_id', $owner->id)->sole();
        $envelope->transferOwnershipTo($target);
        $envelope->refresh();

        $this->assertSame($target->id, $envelope->user_id);
        $this->assertLessThanOrEqual(Envelope::MAX_NAME_LENGTH, mb_strlen($envelope->name));
        $this->assertNotSame($name, $envelope->name);
    }

    public function test_repeated_collisions_are_numbered_from_the_original_name(): void
    {
        $target = User::factory()->create();
        $entrant = User::factory()->create();

        Envelope::create(['name' => 'Groceries', 'type' => 'expense', 'user_id' => $target->id]);
        Envelope::create(['name' => 'Groceries (imported)', 'type' => 'expense', 'user_id' => $target->id]);
        $envelope = Envelope::create(['name' => 'Groceries', 'type' => 'expense', 'user_id' => $entrant->id]);

        $envelope->transferTo($target);
        $envelope->refresh();

        $this->assertSame('Groceries (imported 2)', $envelope->name);
    }

    public function test_a_multibyte_max_length_collision_stays_within_the_name_limit(): void
    {
        $name = str_repeat('é', 60);
        $owner = User::factory()->create();
        $target = User::factory()->create();

        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $owner->id]);
        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $target->id]);

        $envelope = Envelope::query()->where('user_id', $owner->id)->sole();
        $envelope->transferTo($target);
        $envelope->refresh();

        $this->assertLessThanOrEqual(Envelope::MAX_NAME_LENGTH, mb_strlen($envelope->name));
        $this->assertSame(str_repeat('é', 30), mb_substr($envelope->name, 0, 30));
    }

    public function test_wildcard_characters_in_names_do_not_cause_false_collisions(): void
    {
        $target = User::factory()->create();
        $entrant = User::factory()->create();

        // A different name that the old LIKE-prefix lookup would have caught.
        Envelope::create(['name' => 'GrocXeries', 'type' => 'expense', 'user_id' => $target->id]);
        $envelope = Envelope::create(['name' => 'Groc_eries', 'type' => 'expense', 'user_id' => $entrant->id]);

        $envelope->transferTo($target);
        $envelope->refresh();

        $this->assertSame('Groc_eries', $envelope->name);
    }

    public function test_a_max_length_collision_stays_within_the_name_limit_on_member_departure(): void
    {
        $name = str_repeat('B', 60);
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $profile = FinancialProfile::createOwnedBy($owner);
        $profile->members()->attach($member->id, ['is_owner' => false]);

        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $owner->id]);
        $envelope = Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $member->id]);

        $budget = Budget::create([
            'profile_id' => $profile->id,
            'currency' => 'CAD',
            'effective_year' => 0,
            'effective_month' => 0,
        ]);
        $budget->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed']);

        $profile->removeMember($member);

        $envelope->refresh();

        $this->assertSame($owner->id, $envelope->user_id);
        $this->assertLessThanOrEqual(Envelope::MAX_NAME_LENGTH, mb_strlen($envelope->name));
    }

    public function test_a_max_length_collision_stays_within_the_name_limit_on_account_deletion(): void
    {
        $name = str_repeat('C', 60);
        $owner = User::factory()->create();
        $entrant = User::factory()->create();

        $profile = FinancialProfile::createOwnedBy($owner);
        $profile->members()->attach($entrant->id, ['is_owner' => false]);

        Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $owner->id]);
        $envelope = Envelope::create(['name' => $name, 'type' => 'expense', 'user_id' => $entrant->id]);

        $budget = Budget::create([
            'profile_id' => $profile->id,
            'currency' => 'CAD',
            'effective_year' => 0,
            'effective_month' => 0,
        ]);
        $budget->envelopes()->create(['envelope_id' => $envelope->id, 'amount' => -100, 'type' => 'fixed']);

        $this->actingAs($entrant)->delete('/settings/account', ['password' => 'password'])->assertRedirect('/');

        // The envelope keeps its identity: only its owner and name changed.
        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id, 'user_id' => $owner->id]);

        $envelope->refresh();

        $this->assertLessThanOrEqual(Envelope::MAX_NAME_LENGTH, mb_strlen($envelope->name));
        $this->assertNotSame($name, $envelope->name);
    }
}
