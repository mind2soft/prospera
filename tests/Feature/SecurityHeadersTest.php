<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Browser security headers (App\Http\Middleware\SecurityHeaders).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Independent of a developer's running `npm run dev` (public/hot).
        Vite::useHotFile(storage_path('framework/testing/no-hot-file'));
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));

        $csp = (string) $response->headers->get('Content-Security-Policy');

        foreach (["default-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", "form-action 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'(;|$)/", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    private function nonce(TestResponse $response): string
    {
        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);

        return $matches[1];
    }

    public function test_guest_pages_carry_the_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertSecurityHeaders($response);
    }

    public function test_authenticated_pages_carry_the_security_headers(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $this->assertSecurityHeaders($response);
    }

    public function test_every_script_tag_carries_the_per_request_nonce(): void
    {
        $first = $this->get('/');
        $nonce = $this->nonce($first);
        $html = (string) $first->getContent();

        preg_match_all('/<script\b[^>]*>/', $html, $tags);
        $executable = array_filter($tags[0], fn (string $tag): bool => ! str_contains($tag, 'application/json'));

        $this->assertNotEmpty($executable);

        foreach ($executable as $tag) {
            $this->assertStringContainsString("nonce=\"{$nonce}\"", $tag);
        }

        $this->assertNotSame($nonce, $this->nonce($this->get('/')), 'the nonce must change per request');
    }

    public function test_json_and_health_responses_carry_the_headers_too(): void
    {
        $this->assertSecurityHeaders($this->get('/up'));
        $this->assertSecurityHeaders($this->getJson('/api/profiles'));
    }

    public function test_hsts_is_only_sent_in_production(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_the_vite_dev_server_is_allowed_while_it_runs(): void
    {
        $hot = storage_path('framework/testing/hot-'.getmypid());
        File::ensureDirectoryExists(dirname($hot));
        file_put_contents($hot, "http://[::1]:5173\n");
        Vite::useHotFile($hot);

        try {
            $csp = (string) $this->get('/up')->headers->get('Content-Security-Policy');
        } finally {
            unlink($hot);
        }

        // By scheme: CSP cannot name IPv6 literal hosts like [::1].
        $this->assertMatchesRegularExpression("#script-src 'self' 'nonce-[A-Za-z0-9]+' http:;#", $csp);
        $this->assertMatchesRegularExpression('#connect-src [^;]* http: ws:;#', $csp);
        $this->assertMatchesRegularExpression('#style-src [^;]* http:;#', $csp);
        $this->assertMatchesRegularExpression('#font-src [^;]* http:;#', $csp);
    }

    public function test_no_third_party_origin_is_allowed(): void
    {
        $csp = (string) $this->get('/up')->headers->get('Content-Security-Policy');

        // Fonts and icons are self-hosted / bundled.
        $this->assertDoesNotMatchRegularExpression('#https?://#', $csp);
        $this->assertStringContainsString("connect-src 'self';", $csp);
        $this->assertStringContainsString("font-src 'self' data:;", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline';", $csp);
    }

    public function test_the_dev_server_is_not_allowed_otherwise(): void
    {
        $csp = (string) $this->get('/up')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString(' http:', $csp);
        $this->assertStringNotContainsString(' ws:', $csp);
    }
}
