import { ref, shallowReactive } from 'vue';
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
     * the new revision once that save settles (issue #55).
     */
    run: (input: {
        profileId: number;
        widgets: DashboardWidgetPayload[];
        revision: number;
    }) => Promise<LayoutSaveResponse>;
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

    const send = (save: QueuedSave): void => {
        inFlight.value = save.profileId;

        void save
            .run({
                profileId: save.profileId,
                widgets: save.widgets,
                revision: save.revision,
            })
            .then((data) => {
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
            }, save.fail)
            .finally(() => {
                const [next] = queued.values();

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

        enqueue: (save: QueuedSave): void => {
            if (inFlight.value === null) {
                send(save);
            } else {
                queued.set(save.profileId, save);
            }
        },

        /**
         * The page is unloading: no continuation will run, so the queued
         * saves go out now (keepalive requests). Each is a full layout,
         * newer than the one in flight.
         *
         * A profile with a save still in flight is skipped: that save was
         * captured against the same revision and will advance it — sending
         * the queued layout now would be a known-stale blind write the
         * server can only refuse (issue #55). It stays unsaved; the next
         * visit reconciles with the server's layout.
         */
        sendQueuedNow: (): void => {
            for (const save of queued.values()) {
                if (inFlight.value === save.profileId) {
                    continue;
                }

                void save
                    .run({
                        profileId: save.profileId,
                        widgets: save.widgets,
                        revision: save.revision,
                    })
                    .catch(save.fail);
            }

            queued.clear();
        },
    };
};

export type LayoutSaveQueue = ReturnType<typeof createLayoutSaveQueue>;
