<?php

namespace Tests\Feature;

use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FinancialProfileInviteTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_KEY = 'raw-invite-key';

    private string $key = self::RAW_KEY;

    private User $owner;

    private User $member;

    private FinancialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->profile = FinancialProfile::createOwnedBy($this->owner, 'Family');
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);
    }

    private function createInvite(string $email = 'invitee@example.com', ?string $key = null, ?CarbonImmutable $expiresAt = null): FinancialProfileInvite
    {
        return FinancialProfileInvite::create([
            'profile_id' => $this->profile->id,
            'invite_key' => FinancialProfileInvite::keyHash($key ?? self::RAW_KEY),
            'email' => $email,
            'invited_by' => $this->owner->id,
            'expires_at' => $expiresAt ?? now()->addHours(48),
        ]);
    }

    // ------------------------------------------------------------------
    // Creation & revocation
    // ------------------------------------------------------------------

    public function test_the_owner_can_create_an_invite_expiring_after_48_hours(): void
    {
        $response = $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'invitee@example.com',
        ]);

        $response->assertCreated();
        $invite = FinancialProfileInvite::where('email', 'invitee@example.com')->firstOrFail();
        $this->assertEquals($this->profile->id, $invite->profile_id);
        $this->assertEquals($this->owner->id, $invite->invited_by);
        $this->assertEqualsWithDelta(now()->addHours(48), $invite->expires_at, 5);
        // The stored invite_key is the SHA-256 hash of the raw link key.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $invite->invite_key);
        $rawKey = $response->json('url');
        $this->assertMatchesRegularExpression('#^/invites/[0-9a-f]{40}$#', $rawKey);
        $this->assertSame(FinancialProfileInvite::keyHash(substr((string) $rawKey, strlen('/invites/'))), $invite->invite_key);
    }

    public function test_only_the_owner_can_create_invites(): void
    {
        $this->actingAs($this->member)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'invitee@example.com',
        ])->assertForbidden();

        $this->actingAs(User::factory()->create())->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'invitee@example.com',
        ])->assertNotFound();
    }

    public function test_invite_requires_an_email(): void
    {
        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_the_owner_can_revoke_a_pending_invite(): void
    {
        $invite = $this->createInvite();

        $this->actingAs($this->owner)->deleteJson("/api/profiles/{$this->profile->id}/invites/{$invite->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_the_owner_can_list_pending_invites(): void
    {
        $pending = $this->createInvite(email: 'pending@example.com', key: 'pending-key');
        $this->createInvite(email: 'expired@example.com', key: 'expired-key', expiresAt: now()->subMinute());
        $revoked = $this->createInvite(email: 'revoked@example.com', key: 'revoked-key');
        $revoked->delete();

        $response = $this->actingAs($this->owner)->getJson("/api/profiles/{$this->profile->id}/invites");

        $response->assertOk();
        $invites = collect($response->json('invites'));
        $this->assertCount(1, $invites);
        $row = $invites->firstWhere('id', $pending->id);
        $this->assertNotNull($row);
        $this->assertEquals('pending@example.com', $row['email']);
        $this->assertArrayNotHasKey('invite_key', $row);
        $this->assertFalse($row['awaiting_verification']);
        $this->assertNotNull($row['expires_at']);
    }

    public function test_only_the_owner_can_list_invites(): void
    {
        $this->createInvite();

        $this->actingAs($this->member)->getJson("/api/profiles/{$this->profile->id}/invites")
            ->assertForbidden();

        $this->actingAs(User::factory()->create())->getJson("/api/profiles/{$this->profile->id}/invites")
            ->assertNotFound();
    }

    public function test_non_owners_cannot_revoke_invites(): void
    {
        $invite = $this->createInvite();

        $this->actingAs($this->member)->deleteJson("/api/profiles/{$this->profile->id}/invites/{$invite->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id]);
    }

    // ------------------------------------------------------------------
    // Validation endpoint
    // ------------------------------------------------------------------

    public function test_the_validation_endpoint_returns_the_profile_and_inviter(): void
    {
        $invite = $this->createInvite();

        $this->getJson("/api/invites/{$this->key}")
            ->assertOk()
            ->assertJsonPath('invite.profile', 'Family')
            ->assertJsonPath('invite.inviter', $this->owner->name)
            ->assertJsonPath('invite.email', 'invitee@example.com');
    }

    public function test_the_validation_endpoint_returns_404_for_unknown_keys(): void
    {
        $this->getJson('/api/invites/not-a-key')->assertNotFound();
    }

    public function test_the_validation_endpoint_returns_410_for_expired_invites(): void
    {
        $invite = $this->createInvite(expiresAt: now()->subMinute());

        $this->getJson("/api/invites/{$this->key}")->assertStatus(410);
    }

    // ------------------------------------------------------------------
    // Accepting
    // ------------------------------------------------------------------

    public function test_a_user_can_accept_an_invite_and_it_is_consumed(): void
    {
        $invite = $this->createInvite(email: $this->member->email);
        $this->profile->members()->detach($this->member->id);

        $this->actingAs($this->member)->postJson("/api/invites/{$this->key}/accept")
            ->assertOk()
            ->assertJsonPath('profile.name', 'Family');

        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $this->member->id,
            'is_owner' => false,
        ]);
        $this->assertDatabaseMissing('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_accepting_rejects_email_mismatches(): void
    {
        $invite = $this->createInvite(email: 'invitee@example.com');

        $this->actingAs($this->member)->postJson("/api/invites/{$this->key}/accept")
            ->assertUnprocessable()->assertJsonValidationErrors('invite_key');

        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_accepting_rejects_expired_invites(): void
    {
        $invite = $this->createInvite(email: $this->member->email, expiresAt: now()->subMinute());
        $this->profile->members()->detach($this->member->id);

        $this->actingAs($this->member)->postJson("/api/invites/{$this->key}/accept")
            ->assertUnprocessable()->assertJsonValidationErrors('invite_key');
    }

    public function test_accepting_returns_404_for_unknown_keys(): void
    {
        $this->actingAs($this->member)->postJson('/api/invites/not-a-key/accept')->assertNotFound();
    }

    public function test_accepting_rejects_existing_members(): void
    {
        $invite = $this->createInvite(email: $this->member->email);

        $this->actingAs($this->member)->postJson("/api/invites/{$this->key}/accept")
            ->assertUnprocessable()->assertJsonValidationErrors('invite_key');

        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_accepting_re_verifies_the_invite_inside_the_transaction(): void
    {
        $invite = $this->createInvite(email: $this->member->email);
        $this->profile->members()->detach($this->member->id);

        // Simulate a concurrent revocation landing between the initial
        // check and the transaction: the invite is re-verified under lock.
        Event::listen(TransactionBeginning::class, function () use ($invite): void {
            $invite->delete();
        });

        $this->actingAs($this->member)->postJson("/api/invites/{$this->key}/accept")
            ->assertNotFound();

        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_a_membership_race_is_mapped_to_a_validation_error(): void
    {
        $invite = $this->createInvite(email: $this->member->email);
        $this->profile->members()->detach($this->member->id);

        // Simulate a concurrent accept winning the race on financial_profile_user's
        // unique [profile_id, user_id] constraint.
        $this->profile->members()->attach($this->member->id, ['is_owner' => false]);

        $this->actingAs($this->member)->postJson("/api/invites/{$this->key}/accept")
            ->assertUnprocessable()->assertJsonValidationErrors('invite_key');

        // The invite is not consumed by the losing accept.
        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id]);
    }

    // ------------------------------------------------------------------
    // Registration through an invite
    // ------------------------------------------------------------------

    public function test_registration_through_an_invite_grants_membership_on_email_verification(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com');

        $response = $this->post('/register', [
            'name' => 'New User',
            'email' => 'newbie@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => self::RAW_KEY,
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $user = User::where('email', 'newbie@example.com')->firstOrFail();

        // Not a member until the email is verified: the invite is reserved.
        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id, 'pending_user_id' => $user->id]);
        $this->get('/invites/'.self::RAW_KEY)->assertInertia(fn ($page) => $page->where('invite', null));

        $user->markEmailAsVerified();
        event(new Verified($user));

        // Member of the invited profile...
        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $user->id,
            'is_owner' => false,
        ]);
        // ...and owner of their own default profile.
        $defaultProfile = FinancialProfile::where('name', 'Default profile')->firstOrFail();
        $this->assertTrue($defaultProfile->is_default);
        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $defaultProfile->id,
            'user_id' => $user->id,
            'is_owner' => true,
        ]);
        $this->assertDatabaseMissing('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_registration_without_an_invite_still_creates_a_default_profile(): void
    {
        $this->post('/register', [
            'name' => 'New User',
            'email' => 'solo@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'solo@example.com')->firstOrFail();
        $profile = FinancialProfile::where('name', FinancialProfile::DEFAULT_NAME)->firstOrFail();
        $this->assertTrue($profile->is_default);

        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'is_owner' => true,
        ]);
    }

    public function test_registration_rejects_expired_invites(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com', expiresAt: now()->subMinute());

        $this->from('/register')->post('/register', [
            'name' => 'New User',
            'email' => 'newbie@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => self::RAW_KEY,
        ])->assertSessionHasErrors('invite_key');

        $this->assertGuest();
        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_registration_rejects_email_mismatches(): void
    {
        $invite = $this->createInvite(email: 'invitee@example.com');

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'someone-else@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => self::RAW_KEY,
        ])->assertSessionHasErrors('invite_key');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'someone-else@example.com']);
        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_registration_rejects_unknown_invite_keys(): void
    {
        $this->post('/register', [
            'name' => 'New User',
            'email' => 'newbie@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => 'not-a-key',
        ])->assertSessionHasErrors('invite_key');

        $this->assertGuest();
    }

    public function test_registration_re_verifies_the_invite_inside_the_transaction(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com');

        // Simulate a concurrent consumption landing between the initial
        // validation and account creation: the invite is re-verified under
        // lock and the whole registration rolls back.
        Event::listen(TransactionBeginning::class, function () use ($invite): void {
            $invite->delete();
        });

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'newbie@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => self::RAW_KEY,
        ])->assertSessionHasErrors('invite_key');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'newbie@example.com']);
        // The default profile seeded for the new user is rolled back too —
        // only the profile from setUp remains.
        $this->assertDatabaseMissing('financial_profiles', ['name' => 'Default profile']);
    }

    public function test_the_registration_screen_carries_the_invite_context(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com');

        $response = $this->get('/register?invite_key='.self::RAW_KEY);

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('invite.profile', 'Family')
            ->where('invite.inviter', $this->owner->name)
            ->where('invite.email', 'newbie@example.com'));
    }

    public function test_the_invite_landing_route_renders_the_registration_page_with_the_context(): void
    {
        $this->createInvite(email: 'newbie@example.com');

        $this->get('/invites/'.self::RAW_KEY)
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('invite.profile', 'Family')
            ->where('invite.inviter', $this->owner->name)
            ->where('invite.email', 'newbie@example.com'));
    }

    public function test_the_invite_landing_route_rejects_unknown_keys(): void
    {
        $response = $this->get('/invites/not-a-key');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('invite', null));

        // The explicit error state travels as the page's flash data (rendered
        // as an error toast by the frontend).
        $page = $response->viewData('page');
        $this->assertSame('error', $page['flash']['toast']['type'] ?? null);
        $this->assertSame(__('This invite is invalid.'), $page['flash']['toast']['message'] ?? null);
    }

    public function test_the_invite_landing_route_rejects_expired_keys(): void
    {
        $this->createInvite(expiresAt: now()->subMinute());

        $response = $this->get('/invites/'.self::RAW_KEY);

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('invite', null));

        $page = $response->viewData('page');
        $this->assertSame('error', $page['flash']['toast']['type'] ?? null);
        $this->assertSame(__('This invite has expired.'), $page['flash']['toast']['message'] ?? null);
    }
}
