import { useEventListener } from '@vueuse/core';
import { onBeforeUnmount } from 'vue';

/** How the page is being left. */
export type LeaveReason = 'unmount' | 'pagehide';

/**
 * Calls `flush` when the user leaves the page: on unmount (an Inertia visit
 * to another page) and on `pagehide` (reload, tab close, full-page
 * navigation), so a debounced autosave is sent instead of dropped.
 *
 * The flush outlives the component: it must not depend on per-call
 * `mutate` callbacks (TanStack drops them once the unmounted observer has
 * no listener) — chain on `mutateAsync` promises instead. The reason is
 * passed on: on `pagehide` no script runs after the handler, so everything
 * must be sent right away, as unload requests that may ride `keepalive`
 * (lib/api.ts, issue #54); an unmount flush uses a normal request.
 */
export function useFlushOnLeave(flush: (reason: LeaveReason) => void): void {
    onBeforeUnmount(() => flush('unmount'));
    useEventListener('pagehide', () => flush('pagehide'));
}
