import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import { useQueryClient } from 'vue-query';
import {
    ledgerApi,
    type AddTransactionInput,
    type CreateLedgerInput,
} from '@/lib/ledgerApi';
import type { Ledger } from '@/types';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useMutation, useQuery, type QueryKey } from '@/composables/query';

export const ledgerKeys = {
    all: (profileId: number) => ['ledgers', profileId] as const,
    detail: (id: number) => ['ledgers', 'detail', id] as const,
    transactions: (id: number) => ['ledgers', 'transactions', id] as const,
    shares: (id: number) => ['ledgers', 'shares', id] as const,
};

function ledgerKey(id: MaybeRefOrGetter<number | null>): QueryKey {
    return ledgerKeys.detail(toValue(id) ?? 0);
}

export function useLedgers() {
    const { profileId } = useCurrentProfile();

    return useQuery({
        queryKey: computed(() => ledgerKeys.all(toValue(profileId) ?? 0)),
        queryFn: () => ledgerApi.list(toValue(profileId) ?? 0),
        enabled: computed(() => toValue(profileId) !== null),
        select: (data) => data.ledgers,
    });
}

export function useLedgerTransactions(
    ledgerId: MaybeRefOrGetter<number | null>,
    fromDate: MaybeRefOrGetter<string>,
    toDate: MaybeRefOrGetter<string>,
) {
    return useQuery({
        queryKey: computed(() => [
            ...ledgerKeys.transactions(toValue(ledgerId) ?? 0),
            toValue(fromDate),
            toValue(toDate),
        ]),
        queryFn: () =>
            ledgerApi.transactions(
                toValue(ledgerId) ?? 0,
                toValue(fromDate),
                toValue(toDate),
            ),
        enabled: computed(() => toValue(ledgerId) !== null),
        select: (data) => data.transactions,
    });
}

export function useLedgerShares(
    ledgerId: MaybeRefOrGetter<number | null>,
    enabled?: MaybeRefOrGetter<boolean>,
) {
    return useQuery({
        queryKey: computed(() => ledgerKeys.shares(toValue(ledgerId) ?? 0)),
        queryFn: () => ledgerApi.shares(toValue(ledgerId) ?? 0),
        enabled: computed(
            () => (toValue(enabled) ?? true) && toValue(ledgerId) !== null,
        ),
        select: (data) => data.shares,
    });
}

export function useCreateLedger() {
    const queryClient = useQueryClient();
    const { profileId } = useCurrentProfile();

    return useMutation(
        (input: Omit<CreateLedgerInput, 'profile_id'>) =>
            ledgerApi.create({ ...input, profile_id: toValue(profileId) ?? 0 }),
        {
            onSuccess: (data) => {
                queryClient.setQueryData(ledgerKey(data.ledger.id), {
                    ledger: data.ledger,
                });
                void queryClient.invalidateQueries({ queryKey: ['ledgers'] });
            },
        },
    );
}

export function useDeleteLedger() {
    const queryClient = useQueryClient();

    return useMutation((id: number) => ledgerApi.destroy(id), {
        onSuccess: (_data, id) => {
            queryClient.removeQueries(ledgerKey(id));
            queryClient.removeQueries(ledgerKeys.transactions(id));
            queryClient.removeQueries(ledgerKeys.shares(id));
            void queryClient.invalidateQueries({ queryKey: ['ledgers'] });
        },
    });
}

export function useCloseLedger() {
    const queryClient = useQueryClient();

    return useMutation((id: number) => ledgerApi.close(id), {
        onSuccess: (data) => {
            queryClient.setQueryData(ledgerKey(data.ledger.id), {
                ledger: data.ledger,
            });
            void queryClient.invalidateQueries({ queryKey: ['ledgers'] });
        },
    });
}

export function useReopenLedger() {
    const queryClient = useQueryClient();

    return useMutation((id: number) => ledgerApi.reopen(id), {
        onSuccess: (data) => {
            queryClient.setQueryData(ledgerKey(data.ledger.id), {
                ledger: data.ledger,
            });
            void queryClient.invalidateQueries({ queryKey: ['ledgers'] });
        },
    });
}

export function useAddTransaction(ledgerId: MaybeRefOrGetter<number | null>) {
    const queryClient = useQueryClient();

    return useMutation(
        ({ input }: { input: AddTransactionInput }) =>
            ledgerApi.addTransaction(toValue(ledgerId) ?? 0, input),
        {
            onSuccess: () => {
                void queryClient.invalidateQueries({
                    queryKey: ledgerKeys.transactions(toValue(ledgerId) ?? 0),
                });
            },
        },
    );
}

export function useDeleteTransaction(
    ledgerId: MaybeRefOrGetter<number | null>,
) {
    const queryClient = useQueryClient();

    return useMutation(
        ({ transactionId }: { transactionId: number }) =>
            ledgerApi.destroyTransaction(transactionId),
        {
            onSuccess: () => {
                void queryClient.invalidateQueries({
                    queryKey: ledgerKeys.transactions(toValue(ledgerId) ?? 0),
                });
            },
        },
    );
}

// --- sharing (profile members only) -----------------------------------------

export function useSetLedgerShare() {
    const queryClient = useQueryClient();

    return useMutation(
        ({
            ledgerId,
            userId,
            permission,
        }: {
            ledgerId: number;
            userId: number;
            permission: string;
        }) => ledgerApi.setShare(ledgerId, userId, permission),
        {
            onSuccess: (_data, { ledgerId }) => {
                void queryClient.invalidateQueries(ledgerKeys.shares(ledgerId));
            },
        },
    );
}

export function useRemoveLedgerShare() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ ledgerId, userId }: { ledgerId: number; userId: number }) =>
            ledgerApi.removeShare(ledgerId, userId),
        {
            onSuccess: (_data, { ledgerId }) => {
                void queryClient.invalidateQueries(ledgerKeys.shares(ledgerId));
            },
        },
    );
}

export function useTransferLedgerOwnership() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ ledgerId, userId }: { ledgerId: number; userId: number }) =>
            ledgerApi.transferOwnership(ledgerId, userId),
        {
            onSuccess: (data) => {
                const ledger: Ledger = data.ledger;
                void queryClient.setQueryData(ledgerKey(ledger.id), {
                    ledger,
                });
                void queryClient.invalidateQueries({
                    queryKey: ledgerKeys.shares(ledger.id),
                });
                void queryClient.invalidateQueries({ queryKey: ['ledgers'] });
            },
        },
    );
}
