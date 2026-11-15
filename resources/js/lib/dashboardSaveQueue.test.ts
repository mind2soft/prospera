import { describe, expect, it } from 'vitest';
import {
    assignedIds,
    createLayoutSaveQueue,
    type LayoutSave,
    type QueuedSave,
} from '@/lib/dashboardSaveQueue';
import type { DashboardWidget, DashboardWidgetPayload } from '@/types';

/**
 * Deferred transport: tests decide when each save settles and in which
 * order, without touching the network (same approach as budgetSaveQueue).
 */
type TrackedSave = {
    profileId: number;
    widgets: DashboardWidgetPayload[];
    /** The revision the save was sent with, read from the save at send time. */
    revision: number;
    settle: (revision: number, ids: number[]) => void;
    fail: () => void;
};

class DeferredTransport {
    readonly requests: TrackedSave[] = [];

    send = (
        save: QueuedSave,
        input: { profileId: number; revision: number },
    ): Promise<{
        widgets: DashboardWidget[];
        revision: number;
    }> =>
        new Promise((resolve, reject) => {
            this.requests.push({
                profileId: input.profileId,
                widgets: save.widgets,
                // The queue passes the revision it read from the save at
                // send time (a queued save adopts the settled revision).
                revision: input.revision,
                settle: (revision, ids) =>
                    resolve({
                        revision,
                        widgets: save.widgets.map((widget, index) => ({
                            ...widget,
                            id: ids[index] ?? 1,
                            order: index,
                        })) as DashboardWidget[],
                    }),
                fail: () => reject(new Error('save failed')),
            });
        });
}

const payload = (id?: number): DashboardWidgetPayload => ({
    ...(id === undefined ? {} : { id }),
    type: 'envelope_spending',
    title: null,
    view_type: 'single',
    column_width: 3,
    period: { type: 'months', range: 3 },
    selection: [],
});

const saveOf = (
    profileId: number,
    revision: number,
    transport: DeferredTransport,
    entries: { key: number; widget: DashboardWidgetPayload }[],
): { save: LayoutSave; queued: QueuedSave } => {
    const save: LayoutSave = {
        profileId,
        revision,
        keys: entries.map(({ key }) => key),
        widgets: entries.map(({ widget }) => widget),
    };

    const queued: QueuedSave = {
        ...save,
        run: (input) => transport.send(queued, input),
        adopt: () => {},
        fail: () => {},
    };

    return { save, queued };
};

describe('layout save queue', () => {
    it('serializes saves of the same profile and adopts the new revision', async () => {
        const transport = new DeferredTransport();
        const queue = createLayoutSaveQueue();

        const first = saveOf(7, 3, transport, [{ key: -1, widget: payload() }]);
        const second = saveOf(7, 3, transport, [
            { key: -1, widget: payload() },
        ]);

        queue.enqueue(first.queued);
        queue.enqueue(second.queued);

        // The first save is in flight; the second waits.
        expect(transport.requests).toHaveLength(1);
        expect(transport.requests[0].revision).toBe(3);
        expect(queue.isBusy(7)).toBe(true);

        // The first save lands: the layout revision moves 3 → 4 and the
        // widget gets its server id.
        transport.requests[0].settle(4, [42]);
        await Promise.resolve();
        await Promise.resolve();

        // The queued save was captured against revision 3 too: it must go
        // out with the revision the first save produced (issue #55),
        // otherwise the server would refuse it as stale.
        expect(transport.requests).toHaveLength(2);
        expect(transport.requests[1].revision).toBe(4);
    });

    it('adopts server ids into the queued save of the same profile', async () => {
        const transport = new DeferredTransport();
        const queue = createLayoutSaveQueue();

        const first = saveOf(7, 1, transport, [{ key: -1, widget: payload() }]);
        const second = saveOf(7, 1, transport, [
            { key: -1, widget: payload() },
        ]);

        queue.enqueue(first.queued);
        queue.enqueue(second.queued);

        transport.requests[0].settle(2, [42]);
        await Promise.resolve();
        await Promise.resolve();

        // The queued save would create the same widget a second time
        // without the id the first save was assigned.
        expect(transport.requests[1].widgets[0].id).toBe(42);
    });

    it('keeps different profiles independent when unloading', async () => {
        const transport = new DeferredTransport();
        const queue = createLayoutSaveQueue();

        const home = saveOf(1, 5, transport, [{ key: -1, widget: payload() }]);
        const family = saveOf(2, 8, transport, [
            { key: -1, widget: payload() },
        ]);

        queue.enqueue(home.queued);
        queue.enqueue(family.queued);

        // Home's save is still in flight: its revision will advance, so its
        // queued saves would be stale — but Family's layout has its own
        // revision and can go out now (issue #55).
        queue.sendQueuedNow();

        expect(transport.requests).toHaveLength(2);

        // The unload cleared the queue: nothing follows Home's save.
        transport.requests[0].fail();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(transport.requests).toHaveLength(2);
    });

    it('does not send a queued save whose profile has a save in flight', async () => {
        const transport = new DeferredTransport();
        const queue = createLayoutSaveQueue();

        const first = saveOf(7, 3, transport, [{ key: -1, widget: payload() }]);
        const second = saveOf(7, 3, transport, [
            { key: -1, widget: payload() },
        ]);

        queue.enqueue(first.queued);
        queue.enqueue(second.queued);

        // The page unloads while the first save is still in flight: the
        // queued layout was captured against the same revision the first
        // save will advance — sending it would be a known-stale blind write
        // the server refuses (issue #55). It stays unsaved.
        queue.sendQueuedNow();

        expect(transport.requests).toHaveLength(1);

        transport.requests[0].fail();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(transport.requests).toHaveLength(1);
        expect(queue.isBusy(7)).toBe(false);
    });

    it('keeps draining after a failed save', async () => {
        const transport = new DeferredTransport();
        const queue = createLayoutSaveQueue();

        const failures: unknown[] = [];
        const first = saveOf(7, 1, transport, [{ key: -1, widget: payload() }]);
        const second = saveOf(7, 1, transport, [
            { key: -1, widget: payload() },
        ]);

        first.queued.fail = (error) => {
            failures.push(error);
        };

        queue.enqueue(first.queued);
        queue.enqueue(second.queued);

        transport.requests[0].fail();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(failures).toHaveLength(1);
        // The queued save still goes out.
        expect(transport.requests).toHaveLength(2);
        expect(transport.requests[1].revision).toBe(1);

        transport.requests[1].settle(2, [7]);
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(queue.isBusy(7)).toBe(false);
    });

    it('maps server ids to the temporary ids of the entries it created', () => {
        const save: LayoutSave = {
            profileId: 1,
            revision: 1,
            keys: [12, -1, -2],
            widgets: [],
        };

        const saved = [{ id: 12 }, { id: 50 }, { id: 51 }] as DashboardWidget[];

        expect([...assignedIds(save, saved)]).toEqual([
            [-1, 50],
            [-2, 51],
        ]);

        // A response whose length does not match the payload is never
        // interpreted (partial or mismatched data).
        expect(assignedIds(save, saved.slice(0, 2)).size).toBe(0);
    });
});
