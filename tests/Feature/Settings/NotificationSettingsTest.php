<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_settings_page_is_displayed_with_default_settings(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Notifications')
                ->where('settings', NotificationSettings::default()->toArray()),
            );
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('notifications.edit'))->assertRedirect(route('login'));

        $this->patch(route('notifications.update'))->assertRedirect(route('login'));
    }

    public function test_unverified_users_are_redirected_to_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('notifications.edit'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)->patch(route('notifications.update'), [])
            ->assertRedirect(route('verification.notice'));
    }

    public function test_settings_default_to_fully_enabled_before_any_save(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->notification_settings->toArray() === NotificationSettings::default()->toArray());

        foreach (NotificationSettings::KEYS as $key) {
            $this->assertTrue($user->wantsNotification($key), "{$key} is enabled by default");
        }
    }

    public function test_settings_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('notifications.update'), [
                'master' => true,
                'payment_reminders' => false,
                'ledger_expired' => true,
                'contributor_transactions' => true,
                'envelope_limit_exceeded' => false,
                'profile_invite' => false,
            ]);

        $response->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame([
            'master' => true,
            'payment_reminders' => false,
            'ledger_expired' => true,
            'contributor_transactions' => true,
            'envelope_limit_exceeded' => false,
            'profile_invite' => false,
        ], $user->notification_settings->toArray());
        $this->assertFalse($user->wantsNotification(NotificationSettings::PAYMENT_REMINDERS));
        $this->assertTrue($user->wantsNotification(NotificationSettings::LEDGER_EXPIRED));
        $this->assertFalse($user->wantsNotification(NotificationSettings::PROFILE_INVITE));
    }

    public function test_updated_settings_are_returned_as_json(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patchJson(route('notifications.update'), [
                'master' => false,
                'payment_reminders' => true,
                'ledger_expired' => true,
                'contributor_transactions' => true,
                'envelope_limit_exceeded' => true,
                'profile_invite' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('settings.master', false);

        $this->assertFalse($user->refresh()->wantsNotification(NotificationSettings::ENVELOPE_LIMIT_EXCEEDED));
    }

    public function test_master_off_disables_every_notification(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch(route('notifications.update'), [
                'master' => false,
                'payment_reminders' => true,
                'ledger_expired' => true,
                'contributor_transactions' => true,
                'envelope_limit_exceeded' => true,
                'profile_invite' => true,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        foreach (NotificationSettings::TOGGLES as $key) {
            $this->assertFalse($user->wantsNotification($key), "master off must disable {$key}");
        }
    }

    public function test_incomplete_payloads_are_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patchJson(route('notifications.update'), [
                'master' => true,
                'payment_reminders' => true,
                // ledger_expired, contributor_transactions,
                // envelope_limit_exceeded and profile_invite are missing.
            ]);

        $response->assertInvalid([
            'ledger_expired',
            'contributor_transactions',
            'envelope_limit_exceeded',
            'profile_invite',
        ]);

        $this->assertTrue($user->refresh()->wantsNotification(NotificationSettings::LEDGER_EXPIRED));
    }

    public function test_non_boolean_values_are_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patchJson(route('notifications.update'), [
                'master' => 'yes',
                'payment_reminders' => true,
                'ledger_expired' => true,
                'contributor_transactions' => true,
                'envelope_limit_exceeded' => true,
                'profile_invite' => true,
            ]);

        $response->assertInvalid('master');

        $this->assertTrue($user->refresh()->wantsNotification(NotificationSettings::MASTER));
    }
}
