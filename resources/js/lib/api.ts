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

    get firstError(): string {
        const messages = Object.values(this.errors).flat();
        return messages[0] ?? this.message;
    }
}

type ApiOptions = {
    method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
    body?: unknown;
    signal?: AbortSignal;
};

export async function api<T>(
    url: string,
    options: ApiOptions = {},
): Promise<T> {
    const { method = 'GET', body, signal } = options;

    const headers: Record<string, string> = { Accept: 'application/json' };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    if (token) {
        headers['X-CSRF-TOKEN'] = token;
    }

    const response = await fetch(url, {
        method,
        headers,
        signal,
        body: body === undefined ? undefined : JSON.stringify(body),
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
        throw new ApiError(
            payload.message ?? response.statusText,
            response.status,
            payload.errors ?? {},
        );
    }

    return json as T;
}

/**
 * Sets a member's permission on a resource. Members without an explicit
 * share row have no row to patch (404), so a row is created instead.
 */
export async function setResourceShare(
    sharesUrl: string,
    userId: number,
    permission: string,
): Promise<void> {
    try {
        await api(`${sharesUrl}/${userId}`, {
            method: 'PATCH',
            body: { permission },
        });
    } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
            await api(sharesUrl, {
                method: 'POST',
                body: { user_id: userId, permission },
            });
            return;
        }
        throw error;
    }
}
