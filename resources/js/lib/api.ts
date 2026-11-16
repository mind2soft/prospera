import { i18n } from '@/i18n';
import { isClient } from '@/lib/ssr';
import { createKeepaliveBudget, utf8ByteLength } from '@/lib/unloadTransport';
import type { RouteDefinition } from '@/wayfinder';

export class ApiError extends Error {
    readonly status: number;
    readonly errors: Record<string, string[]>;

    constructor(
        message: string,
        status: number,
        errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }

    /** The first validation message, else the error's message — never empty. */
    get firstError(): string {
        const messages = Object.values(this.errors).flat();

        return (
            messages.find((message) => message.trim() !== '') ??
            (this.message.trim() || statusMessage(this.status))
        );
    }
}

/**
 * The message to show for a failed request: the API's own (validation or
 * localized error) message, else the given, already translated fallback
 * (network failure, unexpected exception).
 */
export function errorMessage<T extends string | undefined>(
    error: unknown,
    fallback: T,
): string | T {
    return error instanceof ApiError ? error.firstError : fallback;
}

/**
 * The localized, generic message of an HTTP error status. The server
 * already sends localized messages (app/Exceptions/LocalizedJsonErrors.php);
 * this covers an empty body, a proxy error page or a non-JSON response.
 */
export function statusMessage(status: number): string {
    const { t } = i18n.global;

    switch (true) {
        case status === 401:
            return t('common.errors.unauthenticated');
        case status === 403:
            return t('common.errors.forbidden');
        case status === 404:
            return t('common.errors.notFound');
        case status === 419:
            return t('common.errors.sessionExpired');
        case status === 429:
            return t('common.errors.tooMany');
        case status === 503:
            return t('common.errors.unavailable');
        case status >= 500:
            return t('common.errors.server');
        default:
            return t('common.errors.generic');
    }
}

type ApiMethod = 'get' | 'post' | 'patch' | 'put' | 'delete';

type ApiOptions = {
    body?: unknown;
    signal?: AbortSignal;
    /**
     * The request may outlive the page (an autosave flushed on `pagehide`).
     * It is sent as a `keepalive` request when its body fits the browser's
     * keepalive budget — on its own and summed with the keepalive requests
     * still in flight; an oversized body rides a normal request instead,
     * which a unloading page may drop (the save stays pending in its queue
     * and is reconciled on the next visit — issue #54). Ordinary requests
     * must not set this: keepalive is reserved for actual unload flushes.
     */
    unload?: boolean;
};

/**
 * The keepalive budget is page-wide (the browser enforces it across all of
 * the page's keepalive requests), so one instance serves every call.
 */
const keepaliveBudget = createKeepaliveBudget();

/**
 * Calls a JSON endpoint. The target is a generated Wayfinder route
 * definition (`@/routes/...`), which carries both the URL and the HTTP
 * method — URLs are never hand-built, so a renamed route or parameter fails
 * the type check.
 */
export async function api<T>(
    route: RouteDefinition<ApiMethod>,
    options: ApiOptions = {},
): Promise<T> {
    const { url } = route;

    // The SSR pass has no CSRF meta tag, no session cookie, and Node's fetch
    // cannot resolve relative URLs — every call would fail one way or
    // another. Fail fast so queryFns bail out instead of retrying a doomed
    // request; the client refetches on hydration.
    if (!isClient) {
        throw new ApiError(`api() cannot fetch ${url} during SSR`, 0);
    }

    const method = route.method.toUpperCase();
    const { body, signal, unload } = options;

    const headers: Record<string, string> = { Accept: 'application/json' };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const token = isClient
        ? document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
              ?.content
        : null;
    if (token) {
        headers['X-CSRF-TOKEN'] = token;
    }

    // The body is serialized once: the same text decides the transport and
    // is sent.
    const bodyText = body === undefined ? undefined : JSON.stringify(body);

    let keepalive = unload === true;
    let reservedBytes = 0;

    if (keepalive && bodyText !== undefined) {
        const bytes = utf8ByteLength(bodyText);

        if (keepaliveBudget.reserve(bytes)) {
            reservedBytes = bytes;
        } else {
            // The body does not fit the keepalive budget (issue #54): a
            // keepalive request would be rejected outright, so this save
            // rides a normal request instead.
            keepalive = false;
        }
    }

    try {
        const response = await fetch(url, {
            method,
            headers,
            signal,
            keepalive,
            body: bodyText,
        });

        if (response.status === 204) {
            return undefined as T;
        }

        const json: unknown = await response.json().catch(() => undefined);

        if (!response.ok) {
            const payload = (json ?? {}) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const message =
                typeof payload.message === 'string'
                    ? payload.message.trim()
                    : '';

            throw new ApiError(
                message || statusMessage(response.status),
                response.status,
                payload.errors ?? {},
            );
        }

        return json as T;
    } finally {
        // The reservation is held until the request settles: the browser
        // counts a keepalive body against the budget while it is in flight.
        if (reservedBytes > 0) {
            keepaliveBudget.release(reservedBytes);
        }
    }
}

/**
 * Sets a member's permission on a resource. Members without an explicit
 * share row have no row to patch (404), so a row is created instead.
 */
export async function setResourceShare(
    routes: {
        update: RouteDefinition<'patch'>;
        store: RouteDefinition<'post'>;
    },
    userId: number,
    permission: string,
): Promise<void> {
    try {
        await api(routes.update, { body: { permission } });
    } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
            await api(routes.store, {
                body: { user_id: userId, permission },
            });
            return;
        }
        throw error;
    }
}

/** Retries after the first failed attempt for idempotent reads (queries). */
const MAX_QUERY_RETRIES = 2;

/**
 * Query retry policy: transient failures (network errors, 5xx, 408/429) are
 * retried up to twice; other 4xx responses (authorization, validation, not
 * found, CSRF expiry) are final — retrying cannot change the answer.
 * Mutations are never retried (they are not idempotent).
 */
export function shouldRetryQuery(
    failureCount: number,
    error: unknown,
): boolean {
    if (failureCount >= MAX_QUERY_RETRIES) {
        return false;
    }

    if (error instanceof ApiError) {
        if (error.status === 0) {
            return false;
        }

        if (error.status >= 400 && error.status < 500) {
            return error.status === 408 || error.status === 429;
        }
    }

    return true;
}
