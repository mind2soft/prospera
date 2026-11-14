/**
 * Serialized autosave of budget envelope amounts (docs/budget.md).
 *
 * Every amount edit belongs to one assignment identity —
 * `(budgetId, envelopeId)` — and the identity's saves run strictly one at a
 * time: while a request is in flight, no second request for that identity is
 * started. When it settles, only the newest queued amount is sent, so an
 * older save can never land after a newer one and win. Different identities
 * progress independently.
 *
 * The queue is a module singleton: it survives the budget page being
 * unmounted and remounted by an Inertia visit, and it retains the failed
 * state of an identity until a newer edit confirms the save, so publishing
 * can refuse to proceed (see #78, and #87 which builds on this queue).
 *
 * Transport and clock are injected: unit tests drive the production code
 * with deferred promises and a manual clock instead of fake globals.
 */

/** One amount save as the transport receives it. */
export type AmountSaveRequest = {
    budgetId: number;
    envelopeId: number;
    amount: number; // positive; the server signs it by direction
    /**
     * The assignment revision the amount was edited against. `0` means the
     * app has no revision for the assignment and the server must refuse the
     * write.
     */
    revision: number;
};

/**
 * Sends one save and resolves with the assignment revision the server
 * acknowledged — even when the amount it carried was no longer the newest.
 */
export type AmountSaveTransport = (
    request: AmountSaveRequest,
) => Promise<number>;

/** Reads the assignment revision currently known to the app's cache. */
export type RevisionProvider = (
    budgetId: number,
    envelopeId: number,
) => number | undefined;

export type SaveClock = {
    schedule: (callback: () => void, delayMs: number) => unknown;
    cancel: (handle: unknown) => void;
};

export type BudgetSaveStatus = 'idle' | 'saving' | 'saved' | 'error';

type IdentityKey = `${number}:${number}`;

type IdentityState = {
    budgetId: number;
    envelopeId: number;
    /** The latest amount the user typed. */
    desired: number;
    /** The latest amount the server confirmed; null before the first save. */
    acknowledged: number | null;
    /** Last revision known to have been acknowledged by any save of this identity. */
    revision: number;
    active: boolean;
    /** True when the latest desired amount failed and was not retried. */
    failed: boolean;
    /**
     * True when the failure was a conflict (409): the server holds a
     * different value. A conflict is never retried automatically — the user
     * re-edits to reconcile, so the other tab's change is not overwritten.
     */
    conflicted: boolean;
    timer: unknown;
    /** Settles when the identity has drained all work queued when read. */
    drained: Promise<void>;
};

const identityKey = (budgetId: number, envelopeId: number): IdentityKey =>
    `${budgetId}:${envelopeId}`;

const missingTransport = (): Promise<number> => {
    throw new Error('BudgetSaveQueue.configure() was never called.');
};

export class BudgetSaveQueue {
    private readonly identities = new Map<IdentityKey, IdentityState>();

    private transport: AmountSaveTransport = missingTransport;

    private revisionProvider: RevisionProvider = () => undefined;

    private readonly listeners = new Set<() => void>();

    private clock: SaveClock = {
        schedule: (callback, delayMs) => setTimeout(callback, delayMs),
        cancel: (handle) =>
            clearTimeout(handle as ReturnType<typeof setTimeout>),
    };

    private delayMs = DEFAULT_SAVE_DELAY_MS;

    /**
     * Wires the transport and the cache that knows each assignment's
     * revision. Must be called before any edit is queued.
     */
    configure(options: {
        transport: AmountSaveTransport;
        revisionProvider: RevisionProvider;
        delayMs?: number;
    }): void {
        this.transport = options.transport;
        this.revisionProvider = options.revisionProvider;
        if (options.delayMs !== undefined) {
            this.delayMs = options.delayMs;
        }
    }

    /** Replaces the clock (unit tests pass a manual one). */
    setClock(clock: SaveClock): void {
        this.clock = clock;
    }

    subscribe(listener: () => void): () => void {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }

    /**
     * The user edited an amount: it becomes the identity's newest desired
     * value and is sent after the debounce (or right away on flush).
     */
    queue(budgetId: number, envelopeId: number, amount: number): void {
        const state = this.identities.get(
            identityKey(budgetId, envelopeId),
        ) ?? {
            budgetId,
            envelopeId,
            desired: amount,
            acknowledged: null,
            // Seeded from the app's cache; advanced by every acknowledged save.
            revision: this.revisionProvider(budgetId, envelopeId) ?? 0,
            active: false,
            failed: false,
            conflicted: false,
            timer: null,
            drained: Promise.resolve(),
        };

        this.clock.cancel(state.timer);
        state.desired = amount;
        state.failed = false;
        state.conflicted = false;
        state.timer = this.clock.schedule(() => this.send(state), this.delayMs);
        this.identities.set(identityKey(budgetId, envelopeId), state);
        this.emit();
    }

    /**
     * One save in flight per identity; when it settles, the newest desired
     * amount is sent next — never a second request in parallel. Resolves
     * when the identity has settled.
     */
    private send(state: IdentityState): Promise<void> {
        if (state.active || state.desired === state.acknowledged) {
            // Nothing to persist: an in-flight save settles through its own
            // chain, and a timer that fired late must not re-send an amount
            // that is already acknowledged.
            return state.drained;
        }
        state.active = true;
        this.emit();

        const amount = state.desired;
        let request: Promise<number>;
        try {
            request = this.transport({
                budgetId: state.budgetId,
                envelopeId: state.envelopeId,
                amount,
                revision: state.revision,
            });
        } catch (error) {
            // A transport that fails synchronously (never configured) must
            // settle the identity like a rejected promise would.
            request = Promise.reject(error);
        }
        const settled = request.then(
            (revision) => {
                // The server advanced no matter which amount it received: a
                // later save must edit against the revision this one
                // acknowledged, or the server would refuse it as stale.
                state.revision = revision;
                if (state.desired === amount) {
                    // A stale success cannot acknowledge a newer amount.
                    state.acknowledged = amount;
                    state.failed = false;
                }
                this.emit();
            },
            (error: unknown) => {
                if (state.desired === amount) {
                    // The newest value did not land: keep it dirty. The
                    // revision is only refreshed once the app's cache has
                    // re-read the assignment (syncRevisions).
                    state.failed = true;
                    // A conflict (409, another writer won the
                    // compare-and-write) must be reconciled by the user:
                    // resending would silently overwrite the other tab.
                    state.conflicted =
                        (error as { status?: unknown } | undefined)?.status ===
                        409;
                    this.emit();
                }
            },
        );

        state.drained = settled
            .catch(() => undefined)
            .then(() => {
                state.active = false;
                // Only a newer generation that arrived while this request was
                // in flight is sent next, in order. A failed save stops
                // here: it is retried by the next flush (publish, page
                // leave) or the next edit — never in a hot loop.
                const newerQueued =
                    !state.failed && state.desired !== state.acknowledged;

                return newerQueued ? this.send(state) : undefined;
            })
            .finally(() => this.emit());
        this.emit();

        return state.drained;
    }

    /**
     * Sends every queued amount for one budget (or all budgets) now and
     * waits until each identity drained its queue; false when one of them
     * failed. An identity that is only awaiting its in-flight request is
     * waited for without being sent again.
     */
    async flush(budgetId?: number): Promise<boolean> {
        const states = [...this.identities.values()].filter(
            (state) =>
                (budgetId === undefined || state.budgetId === budgetId) &&
                (state.active || state.desired !== state.acknowledged),
        );
        for (const state of states) {
            this.clock.cancel(state.timer);
            // A conflicted save is never resent by an automatic flush: the
            // user re-edits to reconcile (the other tab's value must not be
            // overwritten silently). Publish still refuses via `failed`.
            if (!state.active && !state.conflicted) {
                this.send(state);
            }
        }
        await Promise.all(states.map((state) => state.drained));

        return [...this.identities.values()]
            .filter(
                (state) =>
                    budgetId === undefined || state.budgetId === budgetId,
            )
            .every(
                (state) =>
                    !state.failed && state.desired === state.acknowledged,
            );
    }

    /** True while the budget has unsaved work (queued, in flight or failed). */
    hasPending(budgetId: number): boolean {
        return [...this.identities.values()].some(
            (state) =>
                state.budgetId === budgetId &&
                (state.active ||
                    state.desired !== state.acknowledged ||
                    state.failed),
        );
    }

    /**
     * Like `hasPending`, but the save currently being acknowledged does not
     * count: TanStack runs the mutation's `onSuccess` before `mutateAsync`
     * resolves, and the queue records the acknowledgment only after — so at
     * `onSuccess` time that one save still looks pending, although it
     * carries exactly `saved.amount`. A newer generation for that identity
     * (desired has moved on) still counts, so a refetch cannot stomp it.
     */
    hasPendingEdits(
        budgetId: number,
        saved?: { envelopeId: number; amount: number },
    ): boolean {
        return [...this.identities.values()].some((state) => {
            if (state.budgetId !== budgetId) {
                return false;
            }
            if (
                saved !== undefined &&
                state.envelopeId === saved.envelopeId &&
                state.desired === saved.amount
            ) {
                return false;
            }

            return state.desired !== state.acknowledged;
        });
    }

    /** The save status of one budget's identities, for the page's indicator. */
    status(budgetId: number): BudgetSaveStatus {
        const states = [...this.identities.values()].filter(
            (state) => state.budgetId === budgetId,
        );
        if (states.some((state) => state.failed)) {
            return 'error';
        }
        if (
            states.some(
                (state) => state.active || state.desired !== state.acknowledged,
            )
        ) {
            return 'saving';
        }
        if (states.some((state) => state.acknowledged !== null)) {
            return 'saved';
        }

        return 'idle';
    }

    /**
     * The cache learned fresh revisions (initial load, refetch after a
     * conflict or failure): queued saves edit against them again.
     */
    syncRevisions(
        budgetId: number,
        envelopes: Iterable<{ envelope_id: number; revision: number }>,
    ): void {
        for (const envelope of envelopes) {
            const state = this.identities.get(
                identityKey(budgetId, envelope.envelope_id),
            );
            if (state !== undefined && !state.active) {
                state.revision = envelope.revision;
            }
        }
        this.emit();
    }

    private emit(): void {
        for (const listener of this.listeners) {
            listener();
        }
    }
}

export const DEFAULT_SAVE_DELAY_MS = 600;

export const budgetSaveQueue = new BudgetSaveQueue();
