<?php

namespace Tests\Feature;

use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Server-emitted strings follow the user's locale (docs/localization.md):
 * users.locale, else the Accept-Language header, else the default.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_get_the_accept_language_locale(): void
    {
        $this->withHeader('Accept-Language', 'fr-CA,fr;q=0.9,en;q=0.5')
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page->where('locale', 'fr'));

        $this->withHeader('Accept-Language', 'fr-CA,fr;q=0.9')
            ->postJson(route('contact.store'), ['email' => 'jane@example.com'])
            ->assertJsonValidationErrors([
                'subject' => 'Veuillez saisir un objet.',
                'message' => 'Veuillez saisir un message.',
            ]);
    }

    public function test_unsupported_or_missing_languages_fall_back_to_the_default(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));

        $this->withHeader('Accept-Language', '')
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    public function test_the_preferred_locale_wins_over_the_browser(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);
        $profile = FinancialProfile::createDefaultFor($user);

        // Laravel's built-in messages, with French attribute names.
        $this->actingAs($user)
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->postJson('/api/ledgers', ['profile_id' => $profile->id])
            ->assertJsonValidationErrors([
                'name' => 'Le champ nom est obligatoire.',
                'start_date' => 'Le champ date de début est obligatoire.',
            ]);

        // App messages.
        $this->actingAs($user)
            ->postJson("/api/profiles/{$profile->id}/transfer-ownership", ['user_id' => $user->id])
            ->assertJsonValidationErrors(['user_id' => 'Ce profil vous appartient déjà.']);
    }

    public function test_english_messages_are_unchanged(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        $profile = FinancialProfile::createDefaultFor($user);

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'fr')
            ->postJson('/api/ledgers', ['profile_id' => $profile->id])
            ->assertJsonValidationErrors([
                'name' => 'The name field is required.',
                'start_date' => 'The start date field is required.',
            ]);
    }

    public function test_a_language_change_applies_to_its_own_confirmation(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => 'fr',
            ]);

        $page = $response->viewData('page');
        $this->assertSame('fr', $page['props']['locale']);
        $this->assertSame('Profil mis à jour.', $page['flash']['toast']['message'] ?? null);
    }

    public function test_account_emails_use_the_preferred_locale(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['locale' => 'fr']);
        $this->assertSame('fr', $user->preferredLocale());

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification, array $channels, User $notifiable, ?string $locale): bool {
            return $locale === 'fr';
        });
    }
}
