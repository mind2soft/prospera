<?php

namespace Tests\Feature;

use App\Models\FinancialProfile;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SEC-8: endpoints that send email to arbitrary addresses are rate-limited
 * — registration per IP, invite creation per user (not only per IP).
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < FortifyServiceProvider::REGISTRATIONS_PER_MINUTE; $i++) {
            // Invalid payloads still count — every attempt may end in an email.
            $this->post('/register', ['email' => "user{$i}@example.com"])->assertStatus(302);
        }

        $this->post('/register', [
            'name' => 'Spammer',
            'email' => 'victim@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertTooManyRequests();

        $this->assertDatabaseMissing('users', ['email' => 'victim@example.com']);
    }

    public function test_registration_is_throttled_per_ip_per_hour(): void
    {
        for ($i = 0; $i < FortifyServiceProvider::REGISTRATIONS_PER_HOUR; $i++) {
            if ($i > 0 && $i % FortifyServiceProvider::REGISTRATIONS_PER_MINUTE === 0) {
                $this->travel(61)->seconds();
            }

            $this->post('/register', ['email' => "user{$i}@example.com"])->assertStatus(302);
        }

        $this->travel(61)->seconds();

        $this->post('/register', ['email' => 'one-more@example.com'])->assertTooManyRequests();
    }

    public function test_password_reset_requests_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < FortifyServiceProvider::PASSWORD_RESETS_PER_MINUTE; $i++) {
            $this->post('/forgot-password', ['email' => "user{$i}@example.com"])->assertStatus(302);
        }

        $this->post('/forgot-password', ['email' => 'victim@example.com'])->assertTooManyRequests();
    }

    public function test_password_reset_requests_are_throttled_per_address(): void
    {
        for ($i = 0; $i < FortifyServiceProvider::PASSWORD_RESETS_PER_MINUTE; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post('/forgot-password', ['email' => 'Victim@example.com'])
                ->assertStatus(302);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post('/forgot-password', ['email' => 'victim@example.com'])
            ->assertTooManyRequests();
    }

    public function test_password_confirmation_is_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < FortifyServiceProvider::PASSWORD_CONFIRMATIONS_PER_MINUTE; $i++) {
            $this->actingAs($user)->post('/user/confirm-password', ['password' => 'wrong'])->assertStatus(302);
        }

        $this->actingAs($user)->post('/user/confirm-password', ['password' => 'password'])->assertTooManyRequests();
    }

    public function test_invite_creation_is_throttled_per_user_per_minute(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($owner, 'Family');

        for ($i = 0; $i < FortifyServiceProvider::INVITES_PER_MINUTE; $i++) {
            $this->actingAs($owner)
                ->postJson("/api/profiles/{$profile->id}/invites", ['email' => "invitee{$i}@example.com"])
                ->assertCreated();
        }

        // A different IP does not reset the per-user budget.
        $this->actingAs($owner)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson("/api/profiles/{$profile->id}/invites", ['email' => 'one-more@example.com'])
            ->assertTooManyRequests();

        // Other users keep their own budget.
        $other = User::factory()->create();
        $otherProfile = FinancialProfile::createOwnedBy($other, 'Other');
        $this->actingAs($other)
            ->postJson("/api/profiles/{$otherProfile->id}/invites", ['email' => 'friend@example.com'])
            ->assertCreated();
    }

    public function test_invite_creation_is_throttled_per_user_per_day(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $profile = FinancialProfile::createOwnedBy($owner, 'Family');

        for ($i = 0; $i < FortifyServiceProvider::INVITES_PER_DAY; $i++) {
            if ($i > 0 && $i % FortifyServiceProvider::INVITES_PER_MINUTE === 0) {
                $this->travel(61)->seconds();
            }

            $this->actingAs($owner)
                ->postJson("/api/profiles/{$profile->id}/invites", ['email' => "invitee{$i}@example.com"])
                ->assertCreated();
        }

        $this->travel(61)->seconds();

        $this->actingAs($owner)
            ->postJson("/api/profiles/{$profile->id}/invites", ['email' => 'one-more@example.com'])
            ->assertTooManyRequests();
    }
}
