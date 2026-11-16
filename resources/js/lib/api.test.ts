import { beforeEach, describe, expect, it, vi } from 'vitest';
import { KEEPALIVE_BODY_LIMIT_BYTES } from '@/lib/unloadTransport';
import type { RouteDefinition } from '@/wayfinder';

/**
 * api() checks `isClient` (typeof document !== 'undefined') at import time
 * and reads the CSRF meta tag from the document, so every test imports the
 * module fresh with a document stubbed — the browser quota policy itself is
 * exercised against the real fetch options api() computes (issue #54). The
 * `createElement` stub keeps Vue's runtime-dom (pulled in via `@/i18n`)
 * importable in the plain Node environment.
 */
vi.stubGlobal('document', {
    querySelector: () => null,
    createElement: () => ({}),
});

type FetchInit = {
    method?: string;
    body?: string;
    keepalive?: boolean;
};

const fetchCalls: FetchInit[] = [];

const route: RouteDefinition<'post'> = {
    url: '/api/save',
    method: 'post',
};

const jsonResponse = (status: number): Response =>
    new Response(JSON.stringify({}), { status });

/** Imports lib/api fresh, with fetch stubbed to record its options. */
const importApi = async () => {
    vi.resetModules();
    vi.stubGlobal('fetch', (_url: string, init: FetchInit) => {
        fetchCalls.push(init);

        return Promise.resolve(jsonResponse(200));
    });

    return import('@/lib/api');
};

const bodyOfBytes = (bytes: number): { filler: string } => ({
    // 'a' is 1 UTF-8 byte; the JSON wrapper adds a few bytes of overhead.
    filler: 'a'.repeat(bytes),
});

beforeEach(() => {
    fetchCalls.length = 0;
});

describe('api() unload transport policy', () => {
    it('sends an ordinary request without keepalive', async () => {
        const { api } = await importApi();

        await api(route, { body: { amount: 1 } });

        expect(fetchCalls[0].keepalive).toBe(false);
    });

    it('sends an unload request with a small body as keepalive', async () => {
        const { api } = await importApi();

        await api(route, { body: { amount: 1 }, unload: true });

        expect(fetchCalls[0].keepalive).toBe(true);
    });

    it('sends an unload request without a body as keepalive', async () => {
        const { api } = await importApi();

        await api(route, { unload: true });

        expect(fetchCalls[0].keepalive).toBe(true);
    });

    it('falls back to a normal request when the body exceeds the quota', async () => {
        const { api } = await importApi();

        // An oversized layout is valid: keepalive would reject it outright,
        // so it rides a normal request instead (issue #54).
        await api(route, {
            body: bodyOfBytes(KEEPALIVE_BODY_LIMIT_BYTES + 1),
            unload: true,
        });

        expect(fetchCalls[0].keepalive).toBe(false);
    });

    it('counts the outstanding keepalive bodies together', async () => {
        const { api } = await importApi();

        // Two bodies that each fit, but sum over the budget: only the
        // first may ride keepalive while the second is still outstanding.
        // (The JSON wrapper adds a few bytes of overhead.)
        const first = api(route, {
            body: bodyOfBytes(KEEPALIVE_BODY_LIMIT_BYTES - 32),
            unload: true,
        });
        // Individually small — but 65504 + 40 bytes over the budget while
        // the first request is still in flight.
        const second = api(route, { body: bodyOfBytes(40), unload: true });

        await Promise.all([first, second]);

        expect(fetchCalls[0].keepalive).toBe(true);
        expect(fetchCalls[1].keepalive).toBe(false);
    });

    it('releases the budget when a request settles', async () => {
        const { api } = await importApi();

        await api(route, {
            body: bodyOfBytes(KEEPALIVE_BODY_LIMIT_BYTES - 32),
            unload: true,
        });
        // The first request has settled: its reservation is released, so
        // the next unload request fits again.
        await api(route, { body: bodyOfBytes(5), unload: true });

        expect(fetchCalls[0].keepalive).toBe(true);
        expect(fetchCalls[1].keepalive).toBe(true);
    });

    it('releases the budget when a request fails', async () => {
        const { api } = await importApi();

        vi.stubGlobal('fetch', () => Promise.resolve(jsonResponse(500)));

        await expect(
            api(route, {
                body: bodyOfBytes(KEEPALIVE_BODY_LIMIT_BYTES - 32),
                unload: true,
            }),
        ).rejects.toMatchObject({ status: 500 });

        // The failed request's reservation is released: the next unload
        // request is not crowded out by it.
        vi.stubGlobal('fetch', (_url: string, init: FetchInit) => {
            fetchCalls.push(init);

            return Promise.resolve(jsonResponse(200));
        });

        await api(route, { body: bodyOfBytes(5), unload: true });

        expect(fetchCalls.at(-1)?.keepalive).toBe(true);
    });

    it('treats a browser rejection as failure and releases the budget', async () => {
        const { api } = await importApi();

        // Even when the local accounting predicted success, the browser
        // may still reject the keepalive request (quota, network): the
        // failure surfaces to the caller and is never retried silently.
        vi.stubGlobal('fetch', () =>
            Promise.reject(new TypeError('keepalive body limit exceeded')),
        );

        await expect(
            api(route, { body: bodyOfBytes(5), unload: true }),
        ).rejects.toBeInstanceOf(TypeError);

        // The rejected request's reservation is released.
        vi.stubGlobal('fetch', (_url: string, init: FetchInit) => {
            fetchCalls.push(init);

            return Promise.resolve(jsonResponse(200));
        });

        await api(route, {
            body: bodyOfBytes(KEEPALIVE_BODY_LIMIT_BYTES - 32),
            unload: true,
        });

        expect(fetchCalls.at(-1)?.keepalive).toBe(true);
    });
});
