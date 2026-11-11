import { useQueryClient } from '@tanstack/vue-query';
import { useDebounceFn } from '@vueuse/core';
import { computed, nextTick, type Ref, ref, shallowReactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import {
    dashboardKeys,
    useSaveDashboardLayout,
} from '@/composables/dashboardQueries';
import { useFlushOnLeave } from '@/composables/useFlushOnLeave';
import { errorMessage } from '@/lib/api';
import type {
    DashboardWidget,
    DashboardWidgetConfig,
    DashboardWidgetPayload,
} from '@/types';

/**
 * One layout save: the payload, and the local id of each payload entry
 * (negative for widgets added since the last save) so the server-assigned
 * ids are adopted by identity, never by position.
 */
type LayoutSave = {
    profileId: number;
    keys: number[];
    widgets: DashboardWidgetPayload[];
};

type QueuedSave = LayoutSave & {
    run: (input: {
        profileId: number;
        widgets: DashboardWidgetPayload[];
    }) => Promise<{ widgets: DashboardWidget[] }>;
    /** Applies the temporary → server id map to the page's local list. */
    adopt: (assigned: Map<number, number>) => void;
    fail: (error: unknown) => void;
};

/** The server ids assigned to the widgets a save created, by temporary id. */
const assignedIds = (
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
 * The chain runs on `mutateAsync` promises: per-call `mutate` callbacks are
 * dropped once the unmounted page's observer has no listener, which used
 * to strand the queued save (CQ-R3-1).
 */
const layoutSaves = (() => {
    const inFlight = ref<number | null>(null);
    const queued = shallowReactive(new Map<number, QueuedSave>());

    const send = (save: QueuedSave): void => {
        inFlight.value = save.profileId;

        void save
            .run({ profileId: save.profileId, widgets: save.widgets })
            .then((data) => {
                const assigned = assignedIds(save, data.widgets);

                if (assigned.size === 0) {
                    return;
                }

                // A queued save of the same profile was captured before
                // this response: without the ids it would create the new
                // widgets a second time.
                const next = queued.get(save.profileId);

                next?.keys.forEach((key, index) => {
                    const id = assigned.get(key);

                    if (id !== undefined) {
                        next.keys[index] = id;
                        next.widgets[index].id = id;
                    }
                });

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
         */
        sendQueuedNow: (): void => {
            for (const save of queued.values()) {
                void save
                    .run({ profileId: save.profileId, widgets: save.widgets })
                    .catch(save.fail);
            }

            queued.clear();
        },
    };
})();

/** The save payload of a widget — existing widgets keep their id. */
const configOf = (widget: DashboardWidgetConfig): DashboardWidgetPayload => ({
    ...(widget.id > 0 ? { id: widget.id } : {}),
    type: widget.type,
    title: widget.title,
    view_type: widget.view_type,
    column_width: widget.column_width,
    period: { ...widget.period },
    selection: widget.selection.map((item) => ({ ...item })),
});

/** Debounce of the autosave — the project convention: no save buttons. */
const SAVE_DELAY_MS = 600;

/**
 * The dashboard's editable layout for the current profile, autosaved: every
 * change to `widgets` is saved (debounced, full layout — the server assigns
 * ids and order), and flushed when the user leaves the page.
 *
 * `layout` is the current profile's canonical list (the widgets query); it
 * only triggers a resync, which reads the cache by profile key so a list can
 * never be applied to another profile.
 */
export function useLayoutAutosave(options: {
    profileId: Ref<number | null>;
    layout: Ref<DashboardWidget[] | undefined>;
}) {
    const { profileId, layout } = options;
    const { t } = useI18n();
    const queryClient = useQueryClient();
    const saveLayout = useSaveDashboardLayout();

    /** Local, editable copy of the layout. */
    const widgets = ref<DashboardWidgetConfig[]>([]);

    // Guard flag for programmatic list replacements (initial load, id
    // adoption) that must not trigger the autosave watch.
    let suppressSave = false;

    /** Applies a mutation to the local list without triggering the autosave. */
    const withoutAutosave = (apply: () => void): void => {
        suppressSave = true;

        apply();

        void nextTick().then(() => {
            suppressSave = false;
        });
    };

    /**
     * The profile whose canonical layout the local list holds (null while
     * none is). Until the current profile's layout has been applied (still
     * loading, load failed, or waiting for a save to settle), the local list
     * is NOT the user's layout: the full-layout save would delete every
     * saved widget missing from it, so adding widgets and autosaving are
     * both blocked.
     */
    const layoutProfileId = ref<number | null>(null);

    const layoutLoaded = computed(
        () =>
            profileId.value !== null &&
            layoutProfileId.value === profileId.value,
    );

    /** The layout is cached but not applied yet (a save for it is settling). */
    const layoutSyncing = computed(
        () => layout.value !== undefined && !layoutLoaded.value,
    );

    /** A local edit waits for the debounced autosave. */
    let editPending = false;
    /** A canonical list arrived while local edits were unsaved. */
    let resyncSkipped = false;

    const isSaving = (id: number): boolean =>
        layoutSaves.isBusy(id) || (editPending && layoutLoaded.value);

    /**
     * Replaces the local list with the current profile's canonical layout
     * (initial load, profile switch, removal invalidation, settled save).
     * Skipped while the profile has unsaved or in-flight edits — they are
     * newer than the server's copy — and retried once they settle.
     */
    const resync = (): void => {
        const id = profileId.value;
        const data =
            id === null
                ? undefined
                : queryClient.getQueryData<{ widgets: DashboardWidget[] }>(
                      dashboardKeys.widgets(id),
                  )?.widgets;

        if (id === null || data === undefined) {
            return;
        }

        if (isSaving(id)) {
            resyncSkipped = true;

            return;
        }

        resyncSkipped = false;

        const incoming: DashboardWidgetConfig[] = data.map((widget) => ({
            ...configOf(widget),
            id: widget.id,
        }));

        const fingerprint = (list: DashboardWidgetConfig[]): string =>
            JSON.stringify(list.map((w) => [w.id, configOf(w)]));

        if (
            layoutProfileId.value === id &&
            fingerprint(incoming) === fingerprint(widgets.value)
        ) {
            return;
        }

        withoutAutosave(() => {
            widgets.value = incoming;
            layoutProfileId.value = id;
        });
    };

    const enqueue = (id: number): void => {
        const save: LayoutSave = {
            profileId: id,
            keys: widgets.value.map((widget) => widget.id),
            widgets: widgets.value.map(configOf),
        };

        layoutSaves.enqueue({
            ...save,
            run: saveLayout.mutateAsync,
            adopt: (assigned) => {
                // The local list belongs to another profile after a switch.
                if (layoutProfileId.value !== id) {
                    return;
                }

                // A temp id → real id transition must not re-trigger the
                // autosave.
                withoutAutosave(() => {
                    for (const widget of widgets.value) {
                        const real = assigned.get(widget.id);

                        if (real !== undefined) {
                            widget.id = real;
                        }
                    }
                });
            },
            fail: (error) => {
                toast.error(
                    errorMessage(error, t('dashboard.toast.saveFailed')),
                );
            },
        });
    };

    /** Saves the current profile's local layout now. */
    const saveNow = (): void => {
        editPending = false;

        if (!layoutLoaded.value || profileId.value === null) {
            return;
        }

        enqueue(profileId.value);
    };

    const debouncedSave = useDebounceFn(() => {
        if (editPending) {
            saveNow();
        }
    }, SAVE_DELAY_MS);

    watch(
        widgets,
        () => {
            if (suppressSave || !layoutLoaded.value) {
                return;
            }

            editPending = true;
            void debouncedSave();
        },
        { deep: true },
    );

    // Switching profiles (widget config is per profile): a pending edit of
    // the previous profile is saved right away — it can no longer be
    // debounced — and the local list is cleared until the new profile's
    // canonical list replaces it.
    watch(profileId, (_next, previous) => {
        if (
            editPending &&
            previous !== null &&
            previous !== undefined &&
            layoutProfileId.value === previous
        ) {
            enqueue(previous);
        }

        editPending = false;

        withoutAutosave(() => {
            widgets.value = [];
            layoutProfileId.value = null;
        });

        resync();
    });

    watch(layout, resync, { immediate: true });

    // Once the profile's saves have settled, apply the canonical list a
    // resync skipped meanwhile (or that a remounted page waits for).
    watch(
        () => profileId.value !== null && layoutSaves.isBusy(profileId.value),
        (busy) => {
            if (!busy && (resyncSkipped || !layoutLoaded.value)) {
                resync();
            }
        },
    );

    // Leaving the page must not drop a debounced or queued edit.
    useFlushOnLeave((reason) => {
        if (editPending) {
            saveNow();
        }

        if (reason === 'pagehide') {
            layoutSaves.sendQueuedNow();
        }
    });

    return { widgets, layoutLoaded, layoutSyncing, withoutAutosave, saveNow };
}
