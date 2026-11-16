import { describe, expect, it, vi } from 'vitest';
import {
    type AmountSaveTransport,
    BudgetSaveQueue,
    type SaveClock,
} from '@/lib/budgetSaveQueue';

/**
 * Deferred-promise transport: tests decide when each request settles and in
 * which order, without touching the network or patching globals.
 */
type TrackedRequest = {
    budgetId: number;
    envelopeId: number;
    amount: number;
    revision: number;
    /** Whether the save rode the unload transport (a `pagehide` flush). */
    unload: boolean;
    settle: (revision: number) => void;
    /** Rejects the request; a status marks the error like an ApiError. */
    fail: (status?: number) => void;
};

class DeferredTransport {
    readonly requests: TrackedRequest[] = [];

    readonly calls: string[] = [];

    send: AmountSaveTransport = (request, transport) =>
        new Promise<number>((resolve, reject) => {
            this.requests.push({
                ...request,
                unload: transport?.unload === true,
                settle: (revision) => {
                    this.calls.push(
                        `ack ${request.budgetId}:${request.envelopeId}=${request.amount}`,
                    );
                    resolve(revision);
                },
                fail: (status?: number) => {
                    this.calls.push(
                        `fail ${request.budgetId}:${request.envelopeId}=${request.amount}`,
                    );
                    const error: Error & { status?: number } = new Error(
                        'save failed',
                    );
                    if (status !== undefined) {
                        error.status = status;
                    }
                    reject(error);
                },
            });
            this.calls.push(
                `send ${request.budgetId}:${request.envelopeId}=${request.amount}`,
            );
        });
}

/** Manual clock: nothing runs until the test advances it. */
class ManualClock implements SaveClock {
    private readonly tasks = new Map<unknown, () => void>();

    private nextId = 0;

    schedule = (callback: () => void): unknown => {
        const id = this.nextId++;
        this.tasks.set(id, callback);

        return id;
    };

    cancel = (handle: unknown): void => {
        this.tasks.delete(handle);
    };

    /** Runs every scheduled callback, in scheduling order. */
    advance(): void {
        const pending = [...this.tasks.values()];
        this.tasks.clear();
        for (const callback of pending) {
            callback();
        }
    }

    get size(): number {
        return this.tasks.size;
    }
}

const configuredQueue = (
    transport: AmountSaveTransport,
    options: { revisions?: Map<string, number>; delayMs?: number } = {},
): { queue: BudgetSaveQueue; clock: ManualClock } => {
    const clock = new ManualClock();
    const queue = new BudgetSaveQueue();
    queue.setClock(clock);
    queue.configure({
        transport,
        revisionProvider: (_budgetId, envelopeId) =>
            options.revisions?.get(`b:${envelopeId}`),
        delayMs: options.delayMs,
    });

    return { queue, clock };
};

const flushMicrotasks = (): Promise<void> =>
    new Promise((resolve) => setTimeout(resolve, 0));

describe('BudgetSaveQueue', () => {
    it('saves the latest amount once, after the debounce', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        queue.queue(10, 7, 400);
        expect(transport.requests).toHaveLength(0);
        expect(clock.size).toBe(1); // the debounce was replaced, not stacked

        clock.advance();
        expect(transport.requests).toHaveLength(1);
        expect(transport.requests[0]).toMatchObject({
            budgetId: 10,
            envelopeId: 7,
            amount: 400,
            revision: 1,
        });

        transport.requests[0].settle(2);
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saved');
        expect(queue.hasPending(10)).toBe(false);
    });

    it('never runs two requests for one identity at the same time', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance(); // first request starts (in flight)
        queue.queue(10, 7, 400);
        clock.advance(); // debounce elapses: still no second request
        expect(transport.requests).toHaveLength(1);

        transport.requests[0].settle(2);
        await flushMicrotasks();

        // Only the newest generation is sent next.
        expect(transport.requests).toHaveLength(2);
        expect(transport.requests[1]?.amount).toBe(400);
        expect(transport.requests[1]?.revision).toBe(2);
        transport.requests[1]?.settle(3);
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saved');
    });

    it('advances same-tab saves to the revision just acknowledged', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 5]]),
        });

        queue.queue(10, 7, 300);
        clock.advance();
        transport.requests[0]?.settle(6);
        await flushMicrotasks();

        queue.queue(10, 7, 400);
        clock.advance();
        expect(transport.requests[1]?.revision).toBe(6);
    });

    it('keeps different identities independent', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([
                ['b:1', 1],
                ['b:2', 1],
            ]),
        });

        queue.queue(10, 1, 100);
        queue.queue(10, 2, 200);
        clock.advance();
        expect(transport.requests).toHaveLength(2); // parallel is fine here

        // Only E2 settles: E1 stays pending and failed later.
        transport.requests[1]?.settle(2);
        await flushMicrotasks();
        expect(queue.hasPending(10)).toBe(true);
        expect(queue.status(10)).toBe('saving');

        transport.requests[0]?.fail();
        await flushMicrotasks();
        expect(queue.status(10)).toBe('error');
        expect(queue.hasPending(10)).toBe(true);
    });

    it('a stale success cannot acknowledge a newer amount', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance(); // sends 300
        queue.queue(10, 7, 400); // newer generation queued while in flight

        transport.requests[0]?.settle(2); // the older save lands
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saving'); // 400 is still unsaved
        expect(queue.hasPending(10)).toBe(true);

        transport.requests[1]?.settle(3);
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saved');
    });

    it('flush sends immediately, drains the chain and reports failures', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        queue.queue(10, 7, 400);
        const flushed = queue.flush(10);
        expect(clock.size).toBe(0); // timers cancelled
        expect(transport.requests).toHaveLength(1);
        expect(transport.requests[0]?.amount).toBe(400);

        transport.requests[0]?.fail();
        await flushed;
        // The failure is retained: publishing must refuse to proceed.
        expect(queue.status(10)).toBe('error');
        expect(queue.hasPending(10)).toBe(true);

        // A later flush retries the dirty value; a second failure still
        // refuses.
        const retried = queue.flush(10);
        expect(transport.requests).toHaveLength(2);
        transport.requests[1]?.fail();
        expect(await retried).toBe(false);
    });

    it('flush keeps draining across generations before resolving', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance(); // 300 in flight
        queue.queue(10, 7, 400);

        const flushed = queue.flush(10);
        transport.requests[0]?.settle(2);
        await flushMicrotasks(); // the chained send of 400 starts
        expect(transport.requests.map((r) => r.amount)).toEqual([300, 400]);

        transport.requests[1]?.settle(3);
        await flushed;
        expect(queue.status(10)).toBe('saved');
    });

    it('a settled failure is retried by the next flush and then confirms', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 900);
        clock.advance();
        transport.requests[0]?.fail();
        await flushMicrotasks();
        // The failure has settled: the request is gone, the value stays
        // dirty. A publish flush at this point must not claim success.
        expect(queue.status(10)).toBe('error');
        expect(queue.hasPending(10)).toBe(true);

        // Connectivity restored, nothing re-edited: the publish flush
        // resends the desired amount through the ordered path and only then
        // reports success (#87).
        const flushed = queue.flush(10);
        expect(transport.requests[1]?.amount).toBe(900);
        transport.requests[1]?.settle(2);
        expect(await flushed).toBe(true);
        expect(queue.status(10)).toBe('saved');
        expect(queue.hasPending(10)).toBe(false);
    });

    it('flush includes an edit queued while it was draining', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        const flushed = queue.flush(10); // 300 in flight
        // A newer edit lands during the flush: its debounce timer is
        // pending while the in-flight save settles.
        queue.queue(10, 7, 400);

        transport.requests[0]?.settle(2);
        await flushMicrotasks(); // the chained send of 400 starts
        expect(transport.requests.map((r) => r.amount)).toEqual([300, 400]);

        transport.requests[1]?.settle(3);
        expect(await flushed).toBe(true);
        // #87: the flush re-scanned after draining and cancelled the stale
        // debounce timer of the generation it already sent — publish cannot
        // proceed while any generation of this budget is still queued.
        expect(clock.size).toBe(0);
    });

    it('flush sends an edit that arrives for a clean identity while it drains', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([
                ['b:1', 1],
                ['b:2', 1],
            ]),
        });

        queue.queue(10, 1, 100);
        const flushed = queue.flush(10); // E1's save in flight
        // A second envelope is edited while the flush drains: it was clean
        // when the flush snapshotted, so only a re-scan can include it.
        queue.queue(10, 2, 200);

        transport.requests[0]?.settle(2);
        await flushMicrotasks(); // the flush's re-scan sends E2's edit
        expect(transport.requests.map((r) => r.envelopeId)).toEqual([1, 2]);
        expect(transport.requests[1]?.amount).toBe(200);

        transport.requests[1]?.settle(3);
        // The gate opens only once the late edit is confirmed too — without
        // the re-scan the flush would report success while 200 was still
        // debounced (or refuse and stall the publish for the debounce).
        expect(await flushed).toBe(true);
        expect(clock.size).toBe(0);
    });

    it('a scoped flush refuses while one envelope of the budget failed', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([
                ['b:1', 1],
                ['b:2', 1],
            ]),
        });

        queue.queue(10, 1, 100);
        queue.queue(10, 2, 200);
        const flushed = queue.flush(10);
        // Only E2 confirms; E1's save fails and settles.
        transport.requests[1]?.settle(2);
        transport.requests[0]?.fail();
        expect(await flushed).toBe(false);
        expect(queue.status(10)).toBe('error');
        expect(queue.hasPending(10)).toBe(true);

        // E2's acknowledged value is not disturbed by the retry; E1 is
        // resent once and confirms — then the gate opens.
        const retried = queue.flush(10);
        expect(transport.requests.map((r) => r.envelopeId)).toEqual([1, 2, 1]);
        expect(transport.requests[2]?.amount).toBe(100);
        transport.requests[2]?.settle(3);
        expect(await retried).toBe(true);
        expect(clock.size).toBe(0);
    });

    it('a failure survives leaving the page and publish still refuses after returning', async () => {
        // The queue is a module singleton: it outlives the budget page, so
        // a settled failure must still be unresolved when the user comes
        // back and asks to publish.
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 900);
        clock.advance();
        transport.requests[0]?.fail();
        await flushMicrotasks();

        // The user leaves: the page-leave flush drains everything it can —
        // it retries the dirty value, which fails again and is retained.
        const leaving = queue.flush();
        transport.requests[1]?.fail();
        expect(await leaving).toBe(false);
        // …and returns, switching budgets away and back. Nothing resets.
        expect(queue.status(10)).toBe('error');
        expect(queue.hasPending(10)).toBe(true);

        // The publish flush retries the still-dirty value and only then
        // reports success.
        const flushed = queue.flush(10);
        expect(transport.requests[2]?.amount).toBe(900);
        transport.requests[2]?.settle(2);
        expect(await flushed).toBe(true);
        expect(queue.status(10)).toBe('saved');
    });

    it('a re-edit clears the failure state and re-saves', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance();
        transport.requests[0]?.fail();
        await flushMicrotasks();
        expect(queue.status(10)).toBe('error');

        queue.queue(10, 7, 350);
        expect(queue.status(10)).toBe('saving'); // failed state cleared
        clock.advance();
        transport.requests[1]?.settle(2);
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saved');
    });

    it('flush only waits for the budget it flushes', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([
                ['b:1', 1],
                ['b:2', 1],
            ]),
        });

        queue.queue(10, 1, 100);
        queue.queue(20, 2, 200);
        const flushed = queue.flush(10);
        transport.requests[0]?.settle(2);
        expect(await flushed).toBe(true);

        // The other budget's save is still queued and runs on its own clock.
        expect(clock.size).toBe(1);
        clock.advance();
        transport.requests[1]?.settle(2);
        await flushMicrotasks();
        expect(queue.status(20)).toBe('saved');
    });

    it('keeps different budgets independent within the debounce', async () => {
        // #79: the same envelope can be assigned to drafts in two profiles.
        // Editing it in budget 20 within budget 10's debounce window must
        // not cancel budget 10's pending save.
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 100);
        queue.queue(20, 7, 200); // another budget's edit, same envelope
        expect(clock.size).toBe(2); // one debounce per identity

        clock.advance();
        expect(transport.requests.map((r) => [r.budgetId, r.amount])).toEqual([
            [10, 100],
            [20, 200],
        ]);

        transport.requests[0]?.settle(2);
        transport.requests[1]?.settle(3);
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saved');
        expect(queue.status(20)).toBe('saved');
    });

    it('an old budget save settling does not disturb a newer budget edit', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance(); // budget 10's save is in flight
        queue.queue(20, 7, 400); // budget 20's edit queued meanwhile

        transport.requests[0]?.settle(2); // the old budget's save lands
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saved');
        expect(queue.hasPending(10)).toBe(false);

        // Budget 20's edit is untouched by budget 10's acknowledgment.
        expect(queue.status(20)).toBe('saving');
        expect(queue.hasPending(20)).toBe(true);
        expect(clock.size).toBe(1);
        clock.advance();
        expect(transport.requests[1]).toMatchObject({
            budgetId: 20,
            envelopeId: 7,
            amount: 400,
            revision: 1,
        });
    });

    it('flush without a scope drains every budget and reports any failure', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([
                ['b:1', 1],
                ['b:2', 1],
            ]),
        });

        queue.queue(10, 1, 100);
        queue.queue(20, 2, 200);
        const flushed = queue.flush();
        expect(clock.size).toBe(0);
        expect(transport.requests).toHaveLength(2);

        transport.requests[0]?.settle(2);
        transport.requests[1]?.fail();
        await flushMicrotasks();
        // A failure in any flushed budget refuses the flush — e.g. a page
        // leave cannot claim every pending edit was saved.
        expect(await flushed).toBe(false);
        expect(queue.status(10)).toBe('saved');
        expect(queue.status(20)).toBe('error');
    });

    it('reports each state transition to subscribers', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });
        const listener = vi.fn();
        queue.subscribe(listener);

        queue.queue(10, 7, 300);
        const callsAfterQueue = listener.mock.calls.length;
        expect(callsAfterQueue).toBeGreaterThan(0);

        clock.advance();
        await flushMicrotasks();
        expect(listener.mock.calls.length).toBeGreaterThan(callsAfterQueue);

        transport.requests[0]?.settle(2);
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saved');
    });

    it('a conflicted save is retained and never resent by an automatic flush', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance();
        transport.requests[0]?.fail(409); // another writer won the revision
        await flushMicrotasks();
        expect(queue.status(10)).toBe('error');
        expect(queue.hasPending(10)).toBe(true);

        // Neither a publish flush nor a page-leave flush may silently
        // rebase and overwrite the other tab's value.
        expect(await queue.flush(10)).toBe(false);
        expect(transport.requests).toHaveLength(1);

        // An explicit re-edit reconciles: it clears the conflict and saves
        // against the revision the app re-read.
        queue.queue(10, 7, 350);
        expect(queue.status(10)).toBe('saving');
        clock.advance();
        expect(transport.requests).toHaveLength(2);
        expect(transport.requests[1]?.amount).toBe(350);
        transport.requests[1]?.settle(2);
        await flushMicrotasks();
        expect(queue.status(10)).toBe('saved');
    });

    it('hasPendingEdits excludes the save being acknowledged', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance(); // the save is in flight
        // TanStack runs the mutation's onSuccess before mutateAsync
        // resolves: at that moment the save still looks pending, but it is
        // exactly the one being acknowledged.
        expect(queue.hasPendingEdits(10)).toBe(true);
        expect(queue.hasPendingEdits(10, { envelopeId: 7, amount: 300 })).toBe(
            false,
        );

        transport.requests[0]?.settle(2);
        await flushMicrotasks();
        expect(queue.hasPendingEdits(10, { envelopeId: 7, amount: 300 })).toBe(
            false,
        );
        expect(queue.hasPendingEdits(10)).toBe(false);

        // A newer generation for the same identity counts again.
        queue.queue(10, 7, 400);
        expect(queue.hasPendingEdits(10, { envelopeId: 7, amount: 300 })).toBe(
            true,
        );
    });

    it('sends ordinary saves without the unload transport', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance();
        transport.requests[0]?.settle(2);
        await flushMicrotasks();

        // A debounced save outlives the component, not the page: a normal
        // request (issue #54).
        expect(transport.requests[0]?.unload).toBe(false);
    });

    it('a pagehide flush sends its saves as keepalive', async () => {
        const transport = new DeferredTransport();
        const { queue } = configuredQueue(transport.send, {
            revisions: new Map([
                ['b:7', 1],
                ['b:8', 1],
            ]),
        });

        queue.queue(10, 7, 300);
        queue.queue(10, 8, 500);
        // The edits are still waiting on their debounce when the page
        // unloads: the flush cancels the timers and sends now.
        expect(transport.requests).toHaveLength(0);

        // The page is unloading: no continuation will run, so the flush's
        // saves ride the unload transport (issue #54).
        const flushPromise = queue.flush(undefined, { unload: true });
        for (const request of transport.requests) {
            request.settle(2);
        }
        expect(await flushPromise).toBe(true);

        expect(transport.requests[0]?.unload).toBe(true);
        expect(transport.requests[1]?.unload).toBe(true);
    });

    it('an unload flush keeps the unload transport for late arrivals', async () => {
        const transport = new DeferredTransport();
        const { queue, clock } = configuredQueue(transport.send, {
            revisions: new Map([['b:7', 1]]),
        });

        queue.queue(10, 7, 300);
        clock.advance();
        expect(transport.requests).toHaveLength(1);

        // The page unloads while the first save is in flight; an edit
        // typed right before it arrives mid-flush (#87). Its save must
        // ride the unload transport too — no continuation runs after the
        // handler once the page is really gone (issue #54).
        const flushPromise = queue.flush(undefined, { unload: true });
        queue.queue(10, 7, 400);
        transport.requests[0]?.settle(2);
        await flushMicrotasks();
        transport.requests[1]?.settle(3);
        expect(await flushPromise).toBe(true);
        await flushMicrotasks();

        expect(transport.requests[1]?.unload).toBe(true);
        expect(transport.requests[1]?.amount).toBe(400);
    });
});
