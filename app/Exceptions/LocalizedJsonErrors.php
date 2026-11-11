<?php

namespace App\Exceptions;

use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * JSON error bodies for the API (docs/localization.md): every non-validation
 * error carries a localized, user-facing `message` the client can show as
 * is. Laravel's own bodies are English ("This action is unauthorized."),
 * empty (`abort(404)`) or leak internals ("No query results for model
 * [App\Models\Budget] 5").
 *
 * A 403 keeps a message the application chose (a policy denial reason);
 * every other status gets the generic message of its status. Unexpected
 * exceptions keep Laravel's debug output when `app.debug` is on.
 */
class LocalizedJsonErrors
{
    /** Laravel's and Symfony's default 403 messages, replaced by ours. */
    private const DEFAULT_FORBIDDEN_MESSAGES = ['', 'This action is unauthorized.', 'Forbidden'];

    public static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::wantsJson($request) || $e instanceof ValidationException) {
            return null;
        }

        // Authentication and route-model binding fail before the SetLocale
        // middleware has run.
        App::setLocale(self::locale($request));

        if ($e instanceof AuthenticationException) {
            return response()->json(['message' => self::messageFor(401)], 401);
        }

        if (! $e instanceof HttpExceptionInterface) {
            return config('app.debug')
                ? null
                : response()->json(['message' => self::messageFor(500)], 500);
        }

        $status = $e->getStatusCode();

        $message = $status === 403 && ! in_array($e->getMessage(), self::DEFAULT_FORBIDDEN_MESSAGES, true)
            ? __($e->getMessage())
            : self::messageFor($status);

        return response()->json(['message' => $message], $status, $e->getHeaders());
    }

    /**
     * The request's locale, without ever throwing: the renderer must not
     * fail itself (RES-R3-2). Resolving the user can hit the database — the
     * very thing that may have failed — so on any error the user's
     * preference is skipped and Accept-Language decides.
     */
    private static function locale(Request $request): string
    {
        try {
            return SetLocale::resolve($request);
        } catch (Throwable) {
            return SetLocale::resolve($request, withUser: false);
        }
    }

    public static function messageFor(int $status): string
    {
        return match (true) {
            $status === 401 => __('Your session has ended. Log in again to continue.'),
            $status === 403 => __('You do not have permission to do this.'),
            $status === 404 => __('This item could not be found. It may have been deleted, or you may no longer have access to it.'),
            $status === 419 => __('Your session has expired. Reload the page and try again.'),
            $status === 429 => __('Too many attempts. Wait a moment, then try again.'),
            $status === 503 => __('Prospera is briefly unavailable for maintenance. Try again in a few minutes.'),
            $status >= 500 => __('Something went wrong on our side. Try again in a moment.'),
            default => __('This request could not be completed. Reload the page and try again.'),
        };
    }
}
