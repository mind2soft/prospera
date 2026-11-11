<?php

namespace Tests\Feature;

use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Non-validation API errors carry a localized, user-facing message
 * (UX-R2-1): never empty, English-only or an internal model name.
 */
class LocalizedJsonErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        Route::middleware('web')->prefix('api/__errors')->group(function (): void {
            Route::get('not-found', fn () => abort(404));
            Route::get('forbidden', fn () => abort(403));
            Route::get('forbidden-reason', fn () => abort(403, 'Your email address is not verified.'));
            Route::get('session', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('throttled', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 42]));
            Route::get('crash', fn () => throw new RuntimeException('SQLSTATE secret details'));
            Route::get('unavailable', fn () => abort(503));
            Route::get('user-lookup-fails', function (Request $request): never {
                $request->setUserResolver(fn () => throw new RuntimeException('database down while resolving the user'));

                throw new RuntimeException('original failure');
            });
        });

        Route::middleware('web')->get('__errors/page', fn () => abort(404));
    }

    private function french(): static
    {
        return $this->withHeader('Accept-Language', 'fr');
    }

    public function test_a_missing_model_is_a_localized_not_found_without_internals(): void
    {
        $user = User::factory()->create();
        FinancialProfile::createDefaultFor($user);

        $response = $this->actingAs($user)->french()->getJson('/api/budgets/999999');

        $response->assertNotFound()->assertExactJson([
            'message' => 'Cet élément est introuvable. Il a peut-être été supprimé, ou vous n\'y avez plus accès.',
        ]);
        $this->assertStringNotContainsString('App\\\\Models', $response->getContent());
    }

    public function test_an_empty_not_found_gets_a_message(): void
    {
        $this->getJson('/api/__errors/not-found')
            ->assertNotFound()
            ->assertExactJson(['message' => 'This item could not be found. It may have been deleted, or you may no longer have access to it.']);
    }

    public function test_a_default_forbidden_message_is_replaced_and_a_reason_is_kept_and_translated(): void
    {
        $this->french()->getJson('/api/__errors/forbidden')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Vous n\'avez pas l\'autorisation d\'effectuer cette action.']);

        $this->french()->getJson('/api/__errors/forbidden-reason')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Votre adresse courriel n\'est pas vérifiée.']);

        $this->flushHeaders()->getJson('/api/__errors/forbidden-reason')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Your email address is not verified.']);
    }

    public function test_session_throttle_server_and_maintenance_errors_are_localized(): void
    {
        $this->french()->getJson('/api/__errors/session')
            ->assertStatus(419)
            ->assertExactJson(['message' => 'Votre session a expiré. Rechargez la page et réessayez.']);

        $this->french()->getJson('/api/__errors/throttled')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '42')
            ->assertExactJson(['message' => 'Trop de tentatives. Patientez un moment, puis réessayez.']);

        $this->french()->getJson('/api/__errors/crash')
            ->assertInternalServerError()
            ->assertExactJson(['message' => 'Un problème est survenu de notre côté. Réessayez dans un instant.']);

        $this->french()->getJson('/api/__errors/unavailable')
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Prospera est momentanément indisponible pour maintenance. Réessayez dans quelques minutes.']);
    }

    public function test_the_renderer_falls_back_to_accept_language_when_the_user_lookup_fails(): void
    {
        // RES-R3-2: resolving the user (for its locale) can fail during an
        // outage; the error must still be a localized JSON 500.
        $this->french()->getJson('/api/__errors/user-lookup-fails')
            ->assertInternalServerError()
            ->assertExactJson(['message' => 'Un problème est survenu de notre côté. Réessayez dans un instant.']);
    }

    public function test_an_unauthenticated_api_call_is_localized(): void
    {
        $this->french()->getJson('/api/budgets')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Votre session a pris fin. Reconnectez-vous pour continuer.']);
    }

    public function test_unexpected_errors_keep_the_debug_output_in_debug_mode(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/__errors/crash')
            ->assertInternalServerError()
            ->assertJsonPath('message', 'SQLSTATE secret details')
            ->assertJsonPath('exception', RuntimeException::class);
    }

    public function test_validation_errors_and_html_pages_are_unchanged(): void
    {
        $this->french()->postJson(route('contact.store'), ['email' => 'jane@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject' => 'Veuillez saisir un objet.']);

        $page = $this->get('/__errors/page');

        $page->assertNotFound();
        $this->assertStringNotContainsString('application/json', (string) $page->headers->get('Content-Type'));
    }
}
