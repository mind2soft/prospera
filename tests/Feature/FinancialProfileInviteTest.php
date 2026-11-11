<?php

namespace Tests\Feature;

use App\Mail\ProfileInvite;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\FinancialProfileUser;
use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;
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

        Mail::fake();

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
        $this->assertSame('collaborator', $invite->role);
        $this->assertSame('collaborator', $response->json('invite.role'));
    }

    public function test_the_invite_role_must_be_grantable(): void
    {
        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'invitee@example.com',
            'role' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'invitee@example.com',
            'role' => 'emperor',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'invitee@example.com',
            'role' => FinancialProfileInvite::ROLE_COLLABORATOR,
        ])->assertCreated();

        $this->assertSame('collaborator', FinancialProfileInvite::where('email', 'invitee@example.com')->sole()->role);
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
        $this->assertEquals('collaborator', $row['role']);
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
    // Invite emails (issue #39)
    // ------------------------------------------------------------------

    public function test_creating_an_invite_emails_the_new_recipient_an_account_creation_invite(): void
    {
        $response = $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'stranger@example.com',
        ])->assertCreated();

        $rawKey = substr((string) $response->json('url'), strlen('/invites/'));

        Mail::assertQueued(ProfileInvite::class, 1);
        Mail::assertQueued(fn (ProfileInvite $mail) => $mail->hasTo('stranger@example.com')
            && $mail->inviterName === $this->owner->name
            && $mail->inviterEmail === $this->owner->email
            && $mail->profileName === 'Family'
            && $mail->role === 'collaborator'
            && str_ends_with((string) $mail->inviteUrl, "/invites/{$rawKey}")
            && $mail->acceptUrl === null
            && $mail->recipientUserId === null
            && $mail->locale === 'en'
            && $mail->envelope()->subject
                === "[Prospera] {$this->owner->name} shared their financial profile \"Family\" with you");
    }

    public function test_creating_an_invite_emails_the_registered_recipient_a_one_click_accept(): void
    {
        $this->profile->members()->detach($this->member->id);

        $response = $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => $this->member->email,
        ])->assertCreated();

        $rawKey = substr((string) $response->json('url'), strlen('/invites/'));

        Mail::assertQueued(ProfileInvite::class, 1);
        Mail::assertQueued(fn (ProfileInvite $mail) => $mail->hasTo($this->member->email)
            && $mail->inviterName === $this->owner->name
            && $mail->profileName === 'Family'
            && $mail->role === 'collaborator'
            && str_ends_with((string) $mail->inviteUrl, "/invites/{$rawKey}")
            && str_ends_with((string) $mail->acceptUrl, "/invites/{$rawKey}/accept")
            && $mail->recipientUserId === $this->member->id);
    }

    public function test_the_one_click_accept_email_is_localized_per_recipient(): void
    {
        $this->profile->members()->detach($this->member->id);
        $this->member->forceFill(['locale' => 'fr'])->save();

        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => $this->member->email,
        ])->assertCreated();

        Mail::assertQueued(fn (ProfileInvite $mail) => $mail->hasTo($this->member->email)
            && $mail->recipientUserId === $this->member->id
            && $mail->locale === 'fr');
    }

    public function test_re_inviting_an_email_sends_a_fresh_email(): void
    {
        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'invitee@example.com',
        ])->assertCreated();

        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => 'INVITEE@example.com',
        ])->assertCreated();

        // Every share event sends exactly one email — even when the invite
        // replaces a previous unreserved one (case-insensitive).
        Mail::assertQueued(ProfileInvite::class, 2);
    }

    public function test_invite_emails_respect_the_notification_toggle(): void
    {
        $this->profile->members()->detach($this->member->id);
        $this->member->notification_settings = NotificationSettings::default()
            ->with([NotificationSettings::PROFILE_INVITE => false]);
        $this->member->save();

        $this->actingAs($this->owner)->postJson("/api/profiles/{$this->profile->id}/invites", [
            'email' => $this->member->email,
        ])->assertCreated();

        Mail::assertNothingQueued();
    }

    public function test_queued_invite_emails_skip_delivery_when_the_recipient_opts_out(): void
    {
        // Bypass the Mail::fake() from setUp: these tests exercise the
        // mailable's own send-time gate against the array transport.
        $this->app->forgetInstance('mailer');
        $this->app->forgetInstance('mail.manager');

        $transport = $this->app['mailer']->getSymfonyTransport();

        $optOut = NotificationSettings::default()->with([NotificationSettings::PROFILE_INVITE => false]);
        $this->member->forceFill(['notification_settings' => $optOut])->save();

        $mail = new ProfileInvite(
            inviterName: 'Owner',
            inviterEmail: 'owner@example.com',
            profileName: 'Family',
            role: 'collaborator',
            inviteUrl: 'https://prospera.test/invites/key',
            acceptUrl: 'https://prospera.test/invites/key/accept',
            expiresAt: now()->addHours(48),
            recipientUserId: $this->member->id,
        );
        $mail->to($this->member->email)->send($this->app['mailer']);

        // The gate is checked at send time, inside the queued send.
        $this->assertCount(0, $transport->messages());
    }

    public function test_queued_invite_emails_deliver_when_the_recipient_has_not_opted_out(): void
    {
        // Bypass the Mail::fake() from setUp (see the opt-out test above).
        $this->app->forgetInstance('mailer');
        $this->app->forgetInstance('mail.manager');

        $transport = $this->app['mailer']->getSymfonyTransport();

        $mail = new ProfileInvite(
            inviterName: 'Owner',
            inviterEmail: 'owner@example.com',
            profileName: 'Family',
            role: 'collaborator',
            inviteUrl: 'https://prospera.test/invites/key',
            acceptUrl: 'https://prospera.test/invites/key/accept',
            expiresAt: now()->addHours(48),
            recipientUserId: $this->member->id,
        );
        $mail->to($this->member->email)->send($this->app['mailer']);

        $this->assertCount(1, $transport->messages());

        $body = $transport->messages()[0]->toString();

        $this->assertStringContainsString('shared their financial profile "Family" with you', $body);
        $this->assertStringContainsString('as a collaborator', $body);
        $this->assertStringContainsString('ignore this message', $body);
        $this->assertStringContainsString('https://prospera.test/invites/key/accept', $body);
    }

    // ------------------------------------------------------------------
    // Validation endpoint
    // ------------------------------------------------------------------

    public function test_the_validation_endpoint_returns_the_role(): void
    {
        $this->createInvite();

        $this->getJson("/api/invites/{$this->key}")
            ->assertOk()
            ->assertJsonPath('invite.role', 'collaborator');
    }

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
    // Declining
    // ------------------------------------------------------------------

    public function test_declining_deletes_the_invite_and_removes_it_from_the_owners_list(): void
    {
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $invite = $this->createInvite(email: 'Invitee@Example.com');

        $this->actingAs($invitee)->postJson("/api/invites/{$this->key}/decline")->assertNoContent();

        $this->assertModelMissing($invite);
        $this->assertFalse($this->profile->isMember($invitee));
        $this->actingAs($this->owner)->getJson("/api/profiles/{$this->profile->id}/invites")
            ->assertOk()->assertJsonCount(0, 'invites');

        // A declined invite cannot be accepted afterwards.
        $this->actingAs($invitee)->postJson("/api/invites/{$this->key}/accept")->assertNotFound();
    }

    public function test_only_the_invited_account_can_decline(): void
    {
        $invite = $this->createInvite(email: 'invitee@example.com');

        $this->actingAs($this->member)->postJson("/api/invites/{$this->key}/decline")
            ->assertUnprocessable()->assertJsonValidationErrors('invite_key');

        $this->assertModelExists($invite);
    }

    public function test_reserved_and_unknown_invites_cannot_be_declined(): void
    {
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $invite = $this->createInvite(email: 'invitee@example.com');
        $invite->forceFill(['pending_user_id' => User::factory()->unverified()->create()->id])->save();

        $this->actingAs($invitee)->postJson("/api/invites/{$this->key}/decline")->assertNotFound();
        $this->actingAs($invitee)->postJson('/api/invites/not-a-key/decline')->assertNotFound();

        $this->assertModelExists($invite);
    }

    public function test_declining_requires_a_verified_account(): void
    {
        $unverified = User::factory()->unverified()->create(['email' => 'invitee@example.com']);
        $invite = $this->createInvite(email: 'invitee@example.com');

        $this->postJson("/api/invites/{$this->key}/decline")->assertUnauthorized();
        $this->actingAs($unverified)->postJson("/api/invites/{$this->key}/decline")->assertForbidden();

        $this->assertModelExists($invite);
    }

    // ------------------------------------------------------------------
    // One-click accept link (issue #39)
    // ------------------------------------------------------------------

    public function test_the_accept_link_lands_on_the_confirmation_without_granting_membership(): void
    {
        $invite = $this->createInvite(email: $this->member->email);
        $this->profile->members()->detach($this->member->id);

        // SEC-3: a GET never changes state, even for the matching signed-in
        // user — the confirmation page's button POSTs the accept.
        $this->actingAs($this->member)->get("/invites/{$this->key}/accept")
            ->assertRedirect(route('invites.landing', ['inviteKey' => $this->key]));

        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $this->member->id,
        ]);
        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id]);

        $response = $this->actingAs($this->member)
            ->followingRedirects()
            ->get("/invites/{$this->key}/accept");

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('invite.profile', 'Family'));
        $this->assertArrayNotHasKey('toast', $response->viewData('page')['flash'] ?? []);
    }

    public function test_a_cross_site_accept_link_navigation_does_not_join_the_profile(): void
    {
        $this->createInvite(email: $this->member->email);
        $this->profile->members()->detach($this->member->id);

        $this->actingAs($this->member)
            ->withHeaders(['Sec-Fetch-Site' => 'cross-site'])
            ->get("/invites/{$this->key}/accept");

        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_the_confirmation_post_completes_the_accept_link_flow(): void
    {
        $invite = $this->createInvite(email: $this->member->email);
        $this->profile->members()->detach($this->member->id);

        $this->actingAs($this->member)->get("/invites/{$this->key}/accept");
        $this->actingAs($this->member)->postJson("/api/invites/{$this->key}/accept")->assertOk();

        $this->assertTrue($this->profile->isMember($this->member));
        $this->assertDatabaseMissing('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_the_one_click_accept_link_rejects_other_users(): void
    {
        $invite = $this->createInvite(email: 'invitee@example.com');
        $visitor = User::factory()->create();

        $response = $this->actingAs($visitor)
            ->followingRedirects()
            ->get("/invites/{$this->key}/accept");

        // Falls back to the landing page (the signed-in accept view) with
        // the mismatch error, behaving exactly as today.
        $response->assertOk()->assertInertia(fn ($page) => $page->component('auth/Register'));

        $page = $response->viewData('page');
        $this->assertSame('error', $page['flash']['toast']['type'] ?? null);
        $this->assertSame(__('This invite was issued to another email address.'), $page['flash']['toast']['message'] ?? null);

        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $visitor->id,
        ]);
        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_the_one_click_accept_link_redirects_guests_to_sign_in(): void
    {
        $this->createInvite(email: $this->member->email);

        $response = $this->get("/invites/{$this->key}/accept");

        // The auth middleware redirects guests to the sign-in page with the
        // accept URL preserved as the intended destination: signing in
        // completes the accept step.
        $response->assertRedirect(route('login'));
        $this->assertStringEndsWith(
            "/invites/{$this->key}/accept",
            (string) session('url.intended'),
        );
    }

    public function test_the_one_click_accept_link_sends_unverified_users_to_the_verification_notice(): void
    {
        $unverified = User::factory()->unverified()->create();
        $this->createInvite(email: $unverified->email, key: 'unverified-key');

        $response = $this->actingAs($unverified)->get('/invites/unverified-key/accept');

        // The `verified` middleware bounces the matching but unverified
        // account to the verification notice; the invite is untouched.
        $response->assertRedirect(route('verification.notice'));
        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $unverified->id,
        ]);
        $this->assertDatabaseHas('financial_profile_invites', ['invite_key' => FinancialProfileInvite::keyHash('unverified-key')]);
    }

    public function test_the_one_click_accept_link_falls_back_to_the_landing_page_for_expired_invites(): void
    {
        $this->createInvite(email: 'invitee@example.com', expiresAt: now()->subMinute());
        $visitor = User::factory()->create();

        $response = $this->actingAs($visitor)
            ->followingRedirects()
            ->get("/invites/{$this->key}/accept");

        $response->assertOk()->assertInertia(fn ($page) => $page->component('auth/Register')->where('invite', null));

        $page = $response->viewData('page');
        $this->assertSame(__('This invite has expired.'), $page['flash']['toast']['message'] ?? null);

        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $visitor->id,
        ]);
    }

    public function test_the_one_click_accept_link_falls_back_to_the_landing_page_for_reserved_invites(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com');
        $invitee = User::factory()->create();
        $invite->forceFill(['pending_user_id' => $invitee->id])->save();

        $this->actingAs($this->member)->get("/invites/{$this->key}/accept")
            ->assertRedirect(route('invites.landing', ['inviteKey' => $this->key]));

        // The reservation is untouched — the invite can't be used by anyone.
        $this->assertDatabaseHas('financial_profile_invites', [
            'id' => $invite->id,
            'pending_user_id' => $invitee->id,
        ]);
    }

    public function test_the_one_click_accept_link_reports_existing_members(): void
    {
        $invite = $this->createInvite(email: $this->member->email);

        $response = $this->actingAs($this->member)
            ->followingRedirects()
            ->get("/invites/{$this->key}/accept");

        $page = $response->viewData('page');
        $this->assertSame('error', $page['flash']['toast']['type'] ?? null);
        $this->assertSame(__('You are already a member of this profile.'), $page['flash']['toast']['message'] ?? null);

        // The invite is not consumed by the failed accept.
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

        // Member of the invited profile, and it is their only profile: no
        // personal "Default profile" is created for invite registrations.
        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $user->id,
            'is_owner' => false,
        ]);
        $this->assertDatabaseMissing('financial_profiles', ['name' => 'Default profile']);
        $this->assertSame(
            [$this->profile->id],
            $user->profiles()->pluck('financial_profiles.id')->all(),
        );
        // The shared profile is the account's default (per-user pivot flag).
        $this->assertTrue($this->profile->isDefaultFor($user));
        $this->assertDatabaseMissing('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_a_discarded_invite_registration_still_seeds_a_default_profile(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com');

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'newbie@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => self::RAW_KEY,
        ])->assertRedirect(route('dashboard', absolute: false));

        // The account changes its email before verifying: the invite is
        // discarded (no membership), and the account still gets its own
        // default profile.
        $user = User::where('email', 'newbie@example.com')->firstOrFail();
        $user->forceFill(['email' => 'changed@example.com'])->save();

        event(new Verified($user));

        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $user->id,
        ]);
        $defaultProfile = FinancialProfile::where('name', FinancialProfile::DEFAULT_NAME)->firstOrFail();
        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $defaultProfile->id,
            'user_id' => $user->id,
            'is_owner' => true,
        ]);
        $this->assertDatabaseMissing('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_a_revoked_reservation_still_seeds_a_default_profile(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com');

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'newbie@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => self::RAW_KEY,
        ])->assertRedirect(route('dashboard', absolute: false));

        // The owner revokes the reservation before the account verifies.
        $user = User::where('email', 'newbie@example.com')->firstOrFail();
        $invite->delete();

        event(new Verified($user));

        $this->assertDatabaseMissing('financial_profile_user', [
            'profile_id' => $this->profile->id,
            'user_id' => $user->id,
        ]);
        $defaultProfile = FinancialProfile::where('name', FinancialProfile::DEFAULT_NAME)->firstOrFail();
        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $defaultProfile->id,
            'user_id' => $user->id,
            'is_owner' => true,
        ]);
    }

    public function test_a_failed_grant_on_verification_is_completed_on_the_next_request(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com');

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'newbie@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => self::RAW_KEY,
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'newbie@example.com')->firstOrFail();

        // RES-7: the grant fails once (a transient DB error) while the
        // account is already marked verified.
        $failed = false;
        FinancialProfileUser::creating(function () use (&$failed): void {
            if (! $failed) {
                $failed = true;

                throw new RuntimeException('Deadlock found when trying to get lock');
            }
        });

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        // The verification itself still succeeds…
        $this->actingAs($user)->get($verificationUrl)
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertSame(0, $user->profiles()->count());
        $this->assertDatabaseHas('financial_profile_invites', ['id' => $invite->id, 'pending_user_id' => $user->id]);

        // …and the next authenticated request completes the grant.
        $this->actingAs($user->fresh())->getJson('/api/profiles')
            ->assertOk()
            ->assertJsonPath('profiles.0.id', $this->profile->id);

        $this->assertTrue($this->profile->isMember($user));
        $this->assertTrue($this->profile->isDefaultFor($user));
        $this->assertDatabaseMissing('financial_profile_invites', ['id' => $invite->id]);
    }

    public function test_a_verified_account_without_any_profile_gets_a_default_one_on_the_next_request(): void
    {
        // E.g. the listener failed and the reservation was pruned since.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame(1, $user->profiles()->count());
        $this->assertTrue($user->profiles()->sole()->isDefaultFor($user));
    }

    public function test_an_expired_reservation_does_not_grant_membership_on_verification(): void
    {
        $invite = $this->createInvite(email: 'newbie@example.com');

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'newbie@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_key' => self::RAW_KEY,
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'newbie@example.com')->firstOrFail();

        // WF-2: past its expiry the reservation disappears from the owner's
        // pending list (nothing left to revoke) — so it must not grant.
        $this->travel(49)->hours();
        $this->actingAs($this->owner)->getJson("/api/profiles/{$this->profile->id}/invites")
            ->assertOk()->assertJsonCount(0, 'invites');

        $user->markEmailAsVerified();
        event(new Verified($user));

        $this->assertFalse($this->profile->isMember($user));
        $this->assertSame(1, $user->profiles()->count());
        $this->assertTrue($user->profiles()->sole()->isOwner($user));
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
        $this->assertTrue($profile->isDefaultFor($user));

        $this->assertDatabaseHas('financial_profile_user', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'is_owner' => true,
            'is_default' => true,
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
            ->where('invite.email', 'newbie@example.com')
            ->where('invite.role', 'collaborator'));
    }

    public function test_the_invite_landing_route_renders_the_registration_page_with_the_context(): void
    {
        $this->createInvite(email: 'newbie@example.com');

        $this->get('/invites/'.self::RAW_KEY)
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->where('invite.profile', 'Family')
            ->where('invite.inviter', $this->owner->name)
            ->where('invite.email', 'newbie@example.com')
            ->where('invite.role', 'collaborator'));
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
