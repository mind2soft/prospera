import { ref, shallowReactive } from 'vue';
import type { UnloadOptions } from '@/lib/unloadTransport';
import type {
    DashboardLayoutData,
    DashboardWidget,
    DashboardWidgetPayload,
} from '@/types';

/** The response of a layout save or widget removal: the canonical layout and its new revision. */
export type LayoutSaveResponse = DashboardLayoutData;

/**
 * One layout save: the payload, the local id of each payload entry (negative
 * for widgets added since the last save) so the server-assigned ids are
 * adopted by identity, never by position, and the layout revision the
 * payload was captured against (issue #55).
 */
export type LayoutSave = {
    profileId: number;
    keys: number[];
    widgets: DashboardWidgetPayload[];
    revision: number;
};

export type QueuedSave = LayoutSave & {
    /**
     * Sends the save. The revision travels in the input, read from the save
     * at send time: a queued save captured before an in-flight save adopts
     * the new revision once that save settles (issue #55). The transport
     * options carry the leave reason: only a `pagehide` flush rides
     * keepalive (issue #54).
     */
    run: (
        input: {
            profileId: number;
            widgets: DashboardWidgetPayload[];
            revision: number;
        },
        transport?: UnloadOptions,
    ) => Promise<LayoutSaveResponse>;
    /** Applies the temporary → server id map to the page's local list. */
    adopt: (assigned: Map<number, number>) => void;
    fail: (error: unknown) => void;
};

/** The server ids assigned to the widgets a save created, by temporary id. */
export const assignedIds = (
    save: LayoutSave,
    saved: DashboardWidget[],
): Map<number, number> => {
    const assigned = new Map<number, number>();

    if (saved.length === save.keys.length) {
        save.keys.forEach((key, index) => {
            if (key <= 0) {
                assigned.set(key, saved[index].id);
            }
        });
    }

    return assigned;
};

/**
 * The layout save queue, shared by every dashboard instance: saves are
 * serialized (one in flight, at most one queued per profile) so responses
 * never land out of order and clobber newer edits. It lives outside the
 * page so it keeps draining after the page unmounts, and a remounted page
 * sees the saves still settling.
 *
 * Every save carries the layout revision it was captured against
 * (issue #55): the server compares it under a lock and refuses a stale save
 * with 409 instead of letting an older layout overwrite a newer one. When a
 * save settles, the queued save of the same profile adopts the new revision
 * — it was captured before that save landed and would otherwise be refused
 * as stale.
 */
export const createLayoutSaveQueue = () => {
    const inFlight = ref<number | null>(null);
    const queued = shallowReactive(new Map<number, QueuedSave>());

    /**
     * Unload saves that failed and returned to the queue (issue #54). They
     * stay there as the recoverable unsaved state — retried by the next
     * flush or replaced by a newer edit — and the settle chain below must
     * not immediately re-send them.
     */
    const recovered = new WeakSet<QueuedSave>();

    const send = (save: QueuedSave, transport?: UnloadOptions): void => {
        inFlight.value = save.profileId;

        void save
            .run(
                {
                    profileId: save.profileId,
                    widgets: save.widgets,
                    revision: save.revision,
                },
                transport,
            )
            .then(
                (data) => {
                    const assigned = assignedIds(save, data.widgets);

                    // A queued save of the same profile was captured before
                    // this response: without the ids it would create the new
                    // widgets a second time, and without the new revision it
                    // would be refused as stale (issue #55).
                    const next = queued.get(save.profileId);

                    if (next !== undefined) {
                        next.revision = data.revision;

                        next.keys.forEach((key, index) => {
                            const id = assigned.get(key);

                            if (id !== undefined) {
                                next.keys[index] = id;
                                next.widgets[index].id = id;
                            }
                        });
                    }

                    if (assigned.size === 0) {
                        return;
                    }

                    save.adopt(assigned);
                },
                (error: unknown) => {
                    // A conflict (409) means the server holds a newer
                    // layout: the local one is stale and is never re-sent —
                    // the fail handler reconciles by pulling the canonical
                    // layout (issue #55).
                    const conflicted =
                        (error as { status?: unknown } | undefined)?.status ===
                        409;

                    if (
                        transport?.unload === true &&
                        !conflicted &&
                        // Re-queue only while the profile has no queued
                        // layout: a newer enqueued layout (an edit made
                        // after the document survived the unload) must never
                        // be overwritten by this older snapshot — the newest
                        // full layout wins.
                        queued.get(save.profileId) === undefined
                    ) {
                        // The save rode the unload transport (a `pagehide`
                        // flush): it returns to the queue as the recoverable
                        // unsaved state (issue #54) — retried by the next
                        // flush or replaced by a newer edit; the settle
                        // chain above skips it, so the failure does not
                        // immediately re-send it. The failure toast is the
                        // explicit unsaved feedback.
                        recovered.add(save);
                        queued.set(save.profileId, save);
                    }

                    save.fail(error);
                },
            )
            .finally(() => {
                // A recovered unload save waits: re-sending it right away
                // would defeat the recovery it was kept for.
                const next = [...queued.values()].find(
                    (entry) => !recovered.has(entry),
                );

                if (next === undefined) {
                    inFlight.value = null;

                    return;
                }

                queued.delete(next.profileId);
                send(next);
            });
    };

    return {
        /** A save of the profile's layout is in flight or queued. */
        isBusy: (profileId: number): boolean =>
            inFlight.value === profileId || queued.has(profileId),

        enqueue: (save: QueuedSave, transport?: UnloadOptions): void => {
            // The newest full layout wins for the profile: a save enqueued
            // while a failed unload save is still queued replaces it (see
            // `sendQueuedNow`).
            queued.set(save.profileId, save);

            if (inFlight.value === null) {
                queued.delete(save.profileId);
                send(save, transport);
            }
        },

        /**
         * The page is unloading: no continuation will run, so the queued
         * saves go out now, as keepalive requests (issue #54) — each is a
         * full layout, newer than the one in flight. The queue is cleared
         * up front: nothing may follow these saves.
         *
         * A profile with a save still in flight is skipped and its queued
         * layout dropped: that save was captured against the same revision
         * and will advance it — sending the queued layout now would be a
         * known-stale blind write the server can only refuse (issue #55).
         * It stays unsaved; the next visit reconciles with the server's
         * layout.
         *
         * A failed unload save returns to the queue (see `send`): the
         * document may survive the unload (back/forward cache), and then
         * the unsaved state is recoverable — the next flush or edit
         * retries or replaces it. A save whose body does not fit the
         * keepalive budget rides a normal request instead (lib/api.ts); if
         * the unloading page drops it, it fails the same way.
         */
        sendQueuedNow: (): void => {
            const pending = [...queued.values()];
            queued.clear();

            for (const save of pending) {
                if (inFlight.value === save.profileId) {
                    continue;
                }

                // The parallel sends each claim `inFlight` for their
                // profile; with more than one profile queued, the last
                // writer wins and a settling save may reset it while
                // another is still out. That only blurs `isBusy` after a
                // document survived the unload (back/forward cache); the
                // server's revision guard bounds any duplicate write.
                send(save, { unload: true });
            }
        },
    };
};

export type LayoutSaveQueue = ReturnType<typeof createLayoutSaveQueue>;
