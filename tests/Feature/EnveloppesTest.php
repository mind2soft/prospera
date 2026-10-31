<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnveloppesTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_page_shows_envelopes(): void
    {
        $user = User::factory()->create();

        $user->enveloppes()->create([
            'name' => 'Groceries',
            'type' => 'expense',
            'color' => '#10b981',
        ]);

        $response = $this->actingAs($user)->get('/enveloppes');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Enveloppes')
            ->has('enveloppes', 1)
            ->where('enveloppes.0.name', 'Groceries')
        );
    }

    public function test_user_can_create_enveloppe(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/enveloppes', [
            'name' => 'Salary',
            'type' => 'revenue',
            'color' => '#6366f1',
        ]);

        $response->assertRedirect(route('enveloppes'));
        $this->assertDatabaseHas('enveloppes', [
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'revenue',
        ]);
    }

    public function test_name_must_be_unique_per_user(): void
    {
        $user = User::factory()->create();
        $user->enveloppes()->create(['name' => 'Rent', 'type' => 'expense']);

        $response = $this->actingAs($user)->post('/enveloppes', [
            'name' => 'Rent',
            'type' => 'expense',
            'color' => '#f43f5e',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_same_name_is_allowed_for_other_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owner->enveloppes()->create(['name' => 'Rent', 'type' => 'expense']);

        $response = $this->actingAs($other)->post('/enveloppes', [
            'name' => 'Rent',
            'type' => 'expense',
            'color' => '#f43f5e',
        ]);

        $response->assertRedirect(route('enveloppes'));
    }

    public function test_user_can_update_own_enveloppe(): void
    {
        $user = User::factory()->create();
        $enveloppe = $user->enveloppes()->create(['name' => 'Old', 'type' => 'expense']);

        $response = $this->actingAs($user)->put("/enveloppes/{$enveloppe->id}", [
            'name' => 'New',
            'type' => 'revenue',
            'color' => '#0ea5e9',
        ]);

        $response->assertRedirect(route('enveloppes'));
        $this->assertDatabaseHas('enveloppes', ['id' => $enveloppe->id, 'name' => 'New']);
    }

    public function test_user_cannot_update_someone_elses_enveloppe(): void
    {
        $user = User::factory()->create();
        $intruder = User::factory()->create();
        $enveloppe = $user->enveloppes()->create(['name' => 'Mine', 'type' => 'expense']);

        $response = $this->actingAs($intruder)->put("/enveloppes/{$enveloppe->id}", [
            'name' => 'Hacked',
            'type' => 'expense',
            'color' => '#000000',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('enveloppes', ['id' => $enveloppe->id, 'name' => 'Mine']);
    }

    public function test_user_can_delete_own_enveloppe(): void
    {
        $user = User::factory()->create();
        $enveloppe = $user->enveloppes()->create(['name' => 'Gone', 'type' => 'expense']);

        $response = $this->actingAs($user)->delete("/enveloppes/{$enveloppe->id}");

        $response->assertRedirect(route('enveloppes'));
        $this->assertModelMissing($enveloppe);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/enveloppes', [
            'name' => 'Weird',
            'type' => 'transfer',
            'color' => '#6366f1',
        ]);

        $response->assertSessionHasErrors(['type']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/enveloppes')->assertRedirect('/login');
    }
}
