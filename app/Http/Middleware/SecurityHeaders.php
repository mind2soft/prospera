<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response (global middleware, so error
 * and maintenance pages carry them too).
 *
 * The Content-Security-Policy allows scripts from the app's origin and those
 * carrying the per-request nonce only: Vite adds it to the tags it renders
 * (Vite::useCspNonce), and app.blade.php to its inline appearance script.
 * Styles keep 'unsafe-inline' — the UI libraries set inline styles, and a
 * nonce would disable it; resources/css/app.css imports Google Fonts, whose
 * stylesheet and font origins are allowed. Framing is denied (clickjacking).
 * While the Vite dev server runs (public/hot), it is allowed for HMR.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce),
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        ];

        // Production only: HSTS pins the browser to HTTPS for a year, which a
        // plain-HTTP local or e2e server could not honor.
        if (app()->isProduction()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $scripts = ["'self'", "'nonce-{$nonce}'"];
        $styles = ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'];
        $fonts = ["'self'", 'data:', 'https://fonts.gstatic.com'];
        $images = ["'self'", 'data:', 'blob:'];
        // @iconify/vue loads the icons it does not find bundled from the
        // Iconify API (and its fallback hosts).
        $connect = ["'self'", 'https://api.iconify.design', 'https://api2.iconify.design', 'https://api.simplesvg.com', 'https://api.unisvg.com'];

        // Dev only (public/hot is never shipped): the Vite dev server serves
        // scripts, styles and fonts, and HMR connects to it over a websocket.
        // It is allowed by scheme: CSP cannot name the IPv6 literal hosts
        // (http://[::1]:5173) it often listens on, and its URLs mix
        // localhost and the literal address.
        $devServer = $this->viteDevServerScheme();

        if ($devServer !== null) {
            $socket = $devServer === 'https:' ? 'wss:' : 'ws:';
            $scripts[] = $devServer;
            $styles[] = $devServer;
            $fonts[] = $devServer;
            $images[] = $devServer;
            array_push($connect, $devServer, $socket);
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scripts),
            'style-src '.implode(' ', $styles),
            'img-src '.implode(' ', $images),
            'font-src '.implode(' ', $fonts),
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * The scheme (`http:` or `https:`) of the Vite dev server while it runs
     * (`npm run dev` writes its URL to public/hot), null otherwise.
     */
    private function viteDevServerScheme(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $scheme = parse_url(trim((string) file_get_contents(Vite::hotFile())), PHP_URL_SCHEME);

        return $scheme === 'https' ? 'https:' : 'http:';
    }
}
