import { api, setResourceShare } from '@/lib/api';
import * as ledgers from '@/routes/api/ledgers';
import * as ledgerShares from '@/routes/api/ledgers/shares';
import * as ledgerTransactions from '@/routes/api/ledgers/transactions';
import * as transactions from '@/routes/api/transactions';
import type { Ledger, ResourceMember, Transaction } from '@/types';

export type CreateLedgerInput = {
    profile_id: number;
    name: string;
    start_date: string;
    end_date: string;
    starting_balance: number; // non-negative integer (0 allowed)
    starting_balance_type: 'cash' | 'debt';
};

export type AddTransactionInput = {
    envelope_id: number;
    amount: number; // positive currency units, up to two decimal places
    event_at: string; // ISO date ("2026-10-30")
};

/** An envelope offered by the transaction picker (contributor+; no budget required). */
export type PickerEnvelope = {
    envelope_id: number;
    name: string;
    color: string;
    direction: 'expense' | 'revenue';
};

export const ledgerApi = {
    list: (profileId: number): Promise<{ ledgers: Ledger[] }> =>
        api(ledgers.index({ query: { profile_id: profileId } })),

    create: (input: CreateLedgerInput): Promise<{ ledger: Ledger }> =>
        api(ledgers.store(), { body: input }),

    destroy: (id: number): Promise<void> => api(ledgers.destroy(id)),

    close: (id: number): Promise<{ ledger: Ledger }> => api(ledgers.close(id)),

    reopen: (id: number): Promise<{ ledger: Ledger }> =>
        api(ledgers.reopen(id)),

    transactions: (
        id: number,
        fromDate: string,
        toDate: string,
    ): Promise<{ transactions: Transaction[] }> =>
        api(
            ledgerTransactions.index(id, {
                query: { from_date: fromDate, to_date: toDate },
            }),
        ),

    addTransaction: (
        id: number,
        input: AddTransactionInput,
    ): Promise<{ transaction: Transaction }> =>
        api(ledgerTransactions.store(id), { body: input }),

    destroyTransaction: (transactionId: number): Promise<void> =>
        api(transactions.destroy(transactionId)),

    /** Undo a deletion, within the server's undo window. */
    restoreTransaction: (transactionId: number): Promise<void> =>
        api(transactions.restore(transactionId)),

    shares: (id: number): Promise<{ shares: ResourceMember[] }> =>
        api(ledgerShares.index(id)),

    setShare: (id: number, userId: number, permission: string): Promise<void> =>
        setResourceShare(
            {
                update: ledgerShares.update({ ledger: id, userId }),
                store: ledgerShares.store(id),
            },
            userId,
            permission,
        ),

    removeShare: (id: number, userId: number): Promise<void> =>
        api(ledgerShares.destroy({ ledger: id, userId })),

    transferOwnership: (
        id: number,
        userId: number,
    ): Promise<{ ledger: Ledger }> =>
        api(ledgers.transferOwnership(id), { body: { user_id: userId } }),
};
