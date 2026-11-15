import { QueryClient, QueryObserver } from '@tanstack/vue-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    invalidateLedgerTransactions,
    ledgerKeys,
} from '@/composables/ledgerQueries';

/**
 * The ledger transaction-report cache must be invalidated as a family:
 * publishing or deleting a published budget changes the "remaining
 * allocated" every cached month report was derived from, and the keys
 * carry no budget identity an invalidation could target narrowly
 * (docs/ledger.md — Allocation reporting).
 */
describe('invalidateLedgerTransactions', () => {
    let queryClient: QueryClient;

    beforeEach(() => {
        queryClient = new QueryClient();
    });

    afterEach(async () => {
        await queryClient.cancelQueries();
        queryClient.clear();
    });

    const seedQuery = (key: readonly unknown[], data: string) => {
        queryClient.setQueryData(key, data);
    };

    it('marks every ledger transaction report stale, whatever its ledger or range', () => {
        const keys = [
            ledgerKeys.transactions(1),
            [...ledgerKeys.transactions(1), '2026-10-01', '2026-10-31'],
            [...ledgerKeys.transactions(7), '2026-11-01', '2026-11-30'],
        ];
        for (const key of keys) {
            seedQuery(key, 'report');
        }

        invalidateLedgerTransactions(queryClient);

        for (const key of keys) {
            expect(queryClient.getQueryState(key)?.isInvalidated).toBe(true);
        }
    });

    it('does not touch other ledger or budget queries', () => {
        const untouched = [
            ledgerKeys.all(1),
            ledgerKeys.detail(1),
            ledgerKeys.shares(1),
            ['budgets', 1],
            ['onboarding', 'progress'],
        ];
        for (const key of untouched) {
            seedQuery(key, 'value');
        }

        invalidateLedgerTransactions(queryClient);

        for (const key of untouched) {
            expect(queryClient.getQueryState(key)?.isInvalidated).toBe(false);
        }
    });

    it('refetches an actively observed report and marks inactive ones stale', async () => {
        let fetches = 0;
        const queryFn = () => `${++fetches}`;
        const active = new QueryObserver(queryClient, {
            queryKey: ledgerKeys.transactions(1),
            queryFn,
        });
        const unsubscribe = active.subscribe(() => {});
        await vi.waitFor(() => expect(fetches).toBe(1)); // first fetch lands

        // An inactive cached report for another month: no observer, never
        // refetched — the invalidation must only mark it stale.
        const inactiveKey = [...ledgerKeys.transactions(2), 'a', 'b'];
        queryClient.setQueryData(inactiveKey, 'report');

        invalidateLedgerTransactions(queryClient);

        // The active observer refetched (the helper is fire-and-forget, like
        // the dashboard invalidation hook) and now shows the fresh report.
        await vi.waitFor(() => expect(fetches).toBe(2));
        expect(active.getCurrentResult().data).toBe('2');
        // The inactive report was marked stale, not refetched.
        expect(queryClient.getQueryState(inactiveKey)?.isInvalidated).toBe(
            true,
        );
        expect(queryClient.getQueryState(inactiveKey)?.fetchStatus).toBe(
            'idle',
        );

        unsubscribe();
    });
});
