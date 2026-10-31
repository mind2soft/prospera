import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import { useQueryClient } from 'vue-query';
import { enveloppeApi } from '@/lib/enveloppeApi';
import type { Enveloppe } from '@/types';
import { useMutation, useQuery } from '@/composables/query';

export const enveloppeKeys = {
    all: ['enveloppes'] as const,
    options: ['enveloppes', 'options'] as const,
    shares: (id: number) => ['enveloppes', 'shares', id] as const,
};

/**
 * All enveloppes visible to the user (own + shared via profile membership),
 * each with its effective permission ("none" = metadata-only).
 */
export function useEnveloppeOptions() {
    return useQuery({
        queryKey: enveloppeKeys.options,
        queryFn: () => enveloppeApi.list(),
        select: (data) => data.enveloppes,
    });
}

export function useEnveloppeShares(id: MaybeRefOrGetter<number | null>) {
    return useQuery({
        queryKey: computed(() => enveloppeKeys.shares(toValue(id) ?? 0)),
        queryFn: () => enveloppeApi.shares(toValue(id) ?? 0),
        enabled: computed(() => toValue(id) !== null),
        select: (data) => data.shares,
    });
}

export function useSetEnveloppeShare() {
    const queryClient = useQueryClient();

    return useMutation(
        ({
            enveloppeId,
            userId,
            permission,
        }: {
            enveloppeId: number;
            userId: number;
            permission: string;
        }) => enveloppeApi.setShare(enveloppeId, userId, permission),
        {
            onSuccess: (_data, { enveloppeId }) => {
                void queryClient.invalidateQueries(
                    enveloppeKeys.shares(enveloppeId),
                );
                void queryClient.invalidateQueries(enveloppeKeys.all);
            },
        },
    );
}

export function useRemoveEnveloppeShare() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ enveloppeId, userId }: { enveloppeId: number; userId: number }) =>
            enveloppeApi.removeShare(enveloppeId, userId),
        {
            onSuccess: (_data, { enveloppeId }) => {
                void queryClient.invalidateQueries(
                    enveloppeKeys.shares(enveloppeId),
                );
                void queryClient.invalidateQueries(enveloppeKeys.all);
            },
        },
    );
}

export function useTransferEnveloppeOwnership() {
    const queryClient = useQueryClient();
    const invalidateAll = () => {
        void queryClient.invalidateQueries();
    };

    return useMutation(
        ({ enveloppeId, userId }: { enveloppeId: number; userId: number }) =>
            enveloppeApi.transferOwnership(enveloppeId, { user_id: userId }),
        {
            onSuccess: (data) => {
                const enveloppe: Enveloppe = data.enveloppe;
                void queryClient.invalidateQueries(
                    enveloppeKeys.shares(enveloppe.id),
                );
                invalidateAll();
            },
        },
    );
}
