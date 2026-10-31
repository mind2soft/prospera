<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvelopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_page_shows_envelopes(): void
    {
        $user = User::factory()->create();

        $user->envelopes()->create([
            'name' => 'Groceries',
            'type' => 'expense',
            'color' => '#10b981',
        ]);

        $response = $this->actingAs($user)->get('/envelopes');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Envelopes')
            ->has('envelopes', 1)
            ->where('envelopes.0.name', 'Groceries')
        );
    }

    public function test_user_can_create_envelope(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/envelopes', [
            'name' => 'Salary',
            'type' => 'revenue',
            'color' => '#6366f1',
        ]);

        $response->assertRedirect(route('envelopes'));
        $this->assertDatabaseHas('envelopes', [
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'revenue',
        ]);
    }

    public function test_name_must_be_unique_per_user(): void
    {
        $user = User::factory()->create();
        $user->envelopes()->create(['name' => 'Rent', 'type' => 'expense']);

        $response = $this->actingAs($user)->post('/envelopes', [
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
        $owner->envelopes()->create(['name' => 'Rent', 'type' => 'expense']);

        $response = $this->actingAs($other)->post('/envelopes', [
            'name' => 'Rent',
            'type' => 'expense',
            'color' => '#f43f5e',
        ]);

        $response->assertRedirect(route('envelopes'));
    }

    public function test_user_can_update_own_envelope(): void
    {
        $user = User::factory()->create();
        $envelope = $user->envelopes()->create(['name' => 'Old', 'type' => 'expense']);

        $response = $this->actingAs($user)->put("/envelopes/{$envelope->id}", [
            'name' => 'New',
            'type' => 'revenue',
            'color' => '#0ea5e9',
        ]);

        $response->assertRedirect(route('envelopes'));
        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id, 'name' => 'New']);
    }

    public function test_user_cannot_update_someone_elses_envelope(): void
    {
        $user = User::factory()->create();
        $intruder = User::factory()->create();
        $envelope = $user->envelopes()->create(['name' => 'Mine', 'type' => 'expense']);

        $response = $this->actingAs($intruder)->put("/envelopes/{$envelope->id}", [
            'name' => 'Hacked',
            'type' => 'expense',
            'color' => '#000000',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id, 'name' => 'Mine']);
    }

    public function test_user_can_delete_own_envelope(): void
    {
        $user = User::factory()->create();
        $envelope = $user->envelopes()->create(['name' => 'Gone', 'type' => 'expense']);

        $response = $this->actingAs($user)->delete("/envelopes/{$envelope->id}");

        $response->assertRedirect(route('envelopes'));
        $this->assertModelMissing($envelope);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/envelopes', [
            'name' => 'Weird',
            'type' => 'transfer',
            'color' => '#6366f1',
        ]);

        $response->assertSessionHasErrors(['type']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/envelopes')->assertRedirect('/login');
    }
}
