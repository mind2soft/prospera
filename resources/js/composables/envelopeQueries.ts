import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import { useQueryClient } from 'vue-query';
import { envelopeApi } from '@/lib/envelopeApi';
import type { Envelope } from '@/types';
import { useMutation, useQuery } from '@/composables/query';

export const envelopeKeys = {
    all: ['envelopes'] as const,
    options: ['envelopes', 'options'] as const,
    shares: (id: number) => ['envelopes', 'shares', id] as const,
};

/**
 * All envelopes visible to the user (own + shared via profile membership),
 * each with its effective permission ("none" = metadata-only).
 */
export function useEnvelopeOptions() {
    return useQuery({
        queryKey: envelopeKeys.options,
        queryFn: () => envelopeApi.list(),
        select: (data) => data.envelopes,
    });
}

export function useEnvelopeShares(id: MaybeRefOrGetter<number | null>) {
    return useQuery({
        queryKey: computed(() => envelopeKeys.shares(toValue(id) ?? 0)),
        queryFn: () => envelopeApi.shares(toValue(id) ?? 0),
        enabled: computed(() => toValue(id) !== null),
        select: (data) => data.shares,
    });
}

export function useSetEnvelopeShare() {
    const queryClient = useQueryClient();

    return useMutation(
        ({
            envelopeId,
            userId,
            permission,
        }: {
            envelopeId: number;
            userId: number;
            permission: string;
        }) => envelopeApi.setShare(envelopeId, userId, permission),
        {
            onSuccess: (_data, { envelopeId }) => {
                void queryClient.invalidateQueries(
                    envelopeKeys.shares(envelopeId),
                );
                void queryClient.invalidateQueries(envelopeKeys.all);
            },
        },
    );
}

export function useRemoveEnvelopeShare() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ envelopeId, userId }: { envelopeId: number; userId: number }) =>
            envelopeApi.removeShare(envelopeId, userId),
        {
            onSuccess: (_data, { envelopeId }) => {
                void queryClient.invalidateQueries(
                    envelopeKeys.shares(envelopeId),
                );
                void queryClient.invalidateQueries(envelopeKeys.all);
            },
        },
    );
}

export function useTransferEnvelopeOwnership() {
    const queryClient = useQueryClient();
    const invalidateAll = () => {
        void queryClient.invalidateQueries();
    };

    return useMutation(
        ({ envelopeId, userId }: { envelopeId: number; userId: number }) =>
            envelopeApi.transferOwnership(envelopeId, { user_id: userId }),
        {
            onSuccess: (data) => {
                const envelope: Envelope = data.envelope;
                void queryClient.invalidateQueries(
                    envelopeKeys.shares(envelope.id),
                );
                invalidateAll();
            },
        },
    );
}
