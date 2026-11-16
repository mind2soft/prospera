/**
 * The unload transport policy for autosave requests (issue #54).
 *
 * Browsers cap `keepalive` request bodies at ~64 KiB — per request, and
 * summed over the keepalive requests still in flight. A layout save that
 * exceeds the quota is valid, but sending it as a keepalive request would
 * fail outright; and a normal request sent while the page unloads is
 * dropped by the browser. So the policy is:
 *
 * - Only unload-time flushes (a `pagehide` save, issue #54) may use
 *   keepalive at all; ordinary debounced saves and SPA-unmount flushes
 *   ride a normal request that outlives the component, not the page.
 * - An unload request whose body does not fit the budget — on its own, or
 *   summed with the bodies already outstanding — falls back to a normal
 *   request instead of a guaranteed rejection.
 *
 * The budget accounting here is a best-effort mirror of the browser's
 * quota: when the browser still rejects a request we predicted to fit
 * (`fetch` throws), the failure surfaces to the caller — it is never
 * retried silently.
 */

/**
 * The keepalive body budget browsers enforce (~64 KiB per request and in
 * total, across the requests still in flight).
 */
export const KEEPALIVE_BODY_LIMIT_BYTES = 64 * 1024;

/** The UTF-8 byte length of a string (request bodies are UTF-8 JSON). */
export const utf8ByteLength = (body: string): number =>
    new TextEncoder().encode(body).length;

export type KeepaliveBudget = {
    /**
     * Tries to reserve `bytes` of the outstanding-keepalive budget: true
     * when the body fits — on its own and summed with the reservations
     * still held — and the request may go out as keepalive; false when it
     * does not (the caller must not rely on keepalive for it).
     */
    reserve: (bytes: number) => boolean;
    /** Releases a reservation once its request has settled. */
    release: (bytes: number) => void;
    /** The bytes currently reserved by requests still in flight. */
    outstanding: () => number;
};

export const createKeepaliveBudget = (
    limitBytes: number = KEEPALIVE_BODY_LIMIT_BYTES,
): KeepaliveBudget => {
    let outstandingBytes = 0;

    return {
        reserve: (bytes: number): boolean => {
            if (bytes > limitBytes || outstandingBytes + bytes > limitBytes) {
                return false;
            }

            outstandingBytes += bytes;

            return true;
        },
        release: (bytes: number): void => {
            outstandingBytes = Math.max(0, outstandingBytes - bytes);
        },
        outstanding: (): number => outstandingBytes,
    };
};

/** The transport options a save may carry when it may outlive the page. */
export type UnloadOptions = {
    /** See `ApiOptions.unload` (lib/api.ts). */
    unload?: boolean;
};
