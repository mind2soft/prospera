import { describe, expect, it } from 'vitest';
import { canDeleteTransaction } from './transactionPermissions';

describe('transaction deletion permissions', () => {
    it('never allows deleting generated entries', () => {
        for (const permission of ['owner', 'contributor', 'viewer', null]) {
            expect(
                canDeleteTransaction(
                    { scheduled: true, user: { id: 1 } },
                    permission,
                    1,
                    true,
                ),
            ).toBe(false);
        }
    });

    it('preserves manual-entry permissions and closed-ledger protection', () => {
        const own = { scheduled: false, user: { id: 1 } };
        const other = { scheduled: false, user: { id: 2 } };
        const former = { scheduled: false, user: { id: null } };
        expect(canDeleteTransaction(own, 'owner', 1, true)).toBe(true);
        expect(canDeleteTransaction(other, 'owner', 1, true)).toBe(true);
        expect(canDeleteTransaction(former, 'owner', 1, true)).toBe(true);
        expect(canDeleteTransaction(own, 'contributor', 1, true)).toBe(true);
        expect(canDeleteTransaction(other, 'contributor', 1, true)).toBe(false);
        expect(canDeleteTransaction(former, 'contributor', 1, true)).toBe(
            false,
        );
        expect(canDeleteTransaction(own, 'viewer', 1, true)).toBe(false);
        expect(canDeleteTransaction(own, null, 1, true)).toBe(false);
        expect(canDeleteTransaction(own, 'owner', 1, false)).toBe(false);
        // Existing cached/manual API rows from a rolling deploy have no provenance.
        expect(
            canDeleteTransaction({ user: { id: 1 } }, 'owner', 1, true),
        ).toBe(true);
    });
});
