<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_page_is_public()
    {
        $response = $this->get(route('privacy'));
        $response->assertOk();

        // The copy is loaded and rendered client-side; the route only
        // provides the Inertia page shell (issue #18).
        $response->assertInertia(fn ($page) => $page->component('Privacy'));
    }

    public function test_signed_in_users_can_visit_the_privacy_page()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('privacy'))->assertOk();
    }
}
