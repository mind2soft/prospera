<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Localize server-emitted strings (validation errors, toasts, emails sent
 * during the request) the way the frontend picks its locale
 * (docs/localization.md): the user's preferred locale (`users.locale`),
 * else the best Accept-Language match among the available locales, else
 * the default locale. The result is shared with the frontend as the
 * `locale` Inertia prop.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request));

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        $default = (string) config('app.locale');
        $available = array_map('strval', array_keys(config('app.available_locales', [])));

        $preferred = $request->user()?->locale;

        if (is_string($preferred) && in_array($preferred, $available, true)) {
            return $preferred;
        }

        if ($request->headers->has('Accept-Language')) {
            // The default first: it is Symfony's pick when nothing matches.
            $candidates = array_values(array_unique([$default, ...$available]));
            $match = $request->getPreferredLanguage($candidates);

            if ($match !== null && in_array($match, $available, true)) {
                return $match;
            }
        }

        return $default;
    }
}
