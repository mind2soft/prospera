import { api, setResourceShare } from '@/lib/api';
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
    amount: number; // positive integer
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
        api(`/api/ledgers?profile_id=${profileId}`),

    create: (input: CreateLedgerInput): Promise<{ ledger: Ledger }> =>
        api('/api/ledgers', { method: 'POST', body: input }),

    destroy: (id: number): Promise<void> =>
        api(`/api/ledgers/${id}`, { method: 'DELETE' }),

    close: (id: number): Promise<{ ledger: Ledger }> =>
        api(`/api/ledgers/${id}/close`, { method: 'POST' }),

    reopen: (id: number): Promise<{ ledger: Ledger }> =>
        api(`/api/ledgers/${id}/reopen`, { method: 'POST' }),

    transactions: (
        id: number,
        fromDate: string,
        toDate: string,
    ): Promise<{ transactions: Transaction[] }> =>
        api(
            `/api/ledgers/${id}/transactions?from_date=${fromDate}&to_date=${toDate}`,
        ),

    addTransaction: (
        id: number,
        input: AddTransactionInput,
    ): Promise<{ transaction: Transaction }> =>
        api(`/api/ledgers/${id}/transactions`, { method: 'POST', body: input }),

    destroyTransaction: (transactionId: number): Promise<void> =>
        api(`/api/transactions/${transactionId}`, { method: 'DELETE' }),

    shares: (id: number): Promise<{ shares: ResourceMember[] }> =>
        api(`/api/ledgers/${id}/shares`),

    setShare: (id: number, userId: number, permission: string): Promise<void> =>
        setResourceShare(`/api/ledgers/${id}/shares`, userId, permission),

    removeShare: (id: number, userId: number): Promise<void> =>
        api(`/api/ledgers/${id}/shares/${userId}`, { method: 'DELETE' }),

    transferOwnership: (
        id: number,
        userId: number,
    ): Promise<{ ledger: Ledger }> =>
        api(`/api/ledgers/${id}/transfer-ownership`, {
            method: 'POST',
            body: { user_id: userId },
        }),
};
