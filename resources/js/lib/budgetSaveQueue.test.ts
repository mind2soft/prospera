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
    settle: (revision: number) => void;
    /** Rejects the request; a status marks the error like an ApiError. */
    fail: (status?: number) => void;
};

class DeferredTransport {
    readonly requests: TrackedRequest[] = [];

    readonly calls: string[] = [];

    send: AmountSaveTransport = (request) =>
        new Promise<number>((resolve, reject) => {
            this.requests.push({
                ...request,
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
});
