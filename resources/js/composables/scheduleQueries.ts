import { useQueryClient } from '@tanstack/vue-query';
import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { invalidateDashboardData } from '@/composables/dashboardQueries';
import { envelopeKeys } from '@/composables/envelopeQueries';
import { invalidateLedgerTransactions } from '@/composables/ledgerQueries';
import {
    useMutation,
    useOptimisticMutation,
    useQuery,
} from '@/composables/query';
import { type ScheduleInput, scheduleApi } from '@/lib/scheduleApi';
import type { EnvelopeSchedule } from '@/types';

export const scheduleKeys = {
    list: (envelopeId: number) =>
        [...envelopeKeys.all, 'schedules', envelopeId] as const,
};

/** The envelope's schedules — owner only (docs/scheduled-transactions.md). */
export function useEnvelopeSchedules(
    envelopeId: MaybeRefOrGetter<number | null>,
) {
    return useQuery({
        queryKey: computed(() => scheduleKeys.list(toValue(envelopeId) ?? 0)),
        queryFn: () => scheduleApi.list(toValue(envelopeId) ?? 0),
        enabled: computed(() => toValue(envelopeId) !== null),
    });
}

/**
 * Schedule writes change generated ledger rows (reconciled after commit,
 * synchronously for deletions), so cached ledger reports and dashboard
 * figures are refreshed along with the list.
 */
function useInvalidateSchedules() {
    const queryClient = useQueryClient();

    return (envelopeId: number): void => {
        void queryClient.invalidateQueries({
            queryKey: scheduleKeys.list(envelopeId),
        });
        invalidateLedgerTransactions(queryClient);
        invalidateDashboardData(queryClient);
    };
}

export function useSaveSchedule() {
    const invalidate = useInvalidateSchedules();

    return useMutation({
        mutationFn: ({
            envelopeId,
            scheduleId,
            input,
        }: {
            envelopeId: number;
            scheduleId: number | null;
            input: ScheduleInput;
        }) =>
            scheduleId === null
                ? scheduleApi.create(envelopeId, input)
                : scheduleApi.update(envelopeId, scheduleId, input),
        onSuccess: (_data, { envelopeId }) => invalidate(envelopeId),
    });
}

export function useDeleteSchedule() {
    const invalidate = useInvalidateSchedules();

    return useOptimisticMutation({
        mutationFn: ({
            envelopeId,
            scheduleId,
        }: {
            envelopeId: number;
            scheduleId: number;
        }) => scheduleApi.destroy(envelopeId, scheduleId),
        optimistic: {
            targets: ({ envelopeId, scheduleId }) => [
                {
                    queryKey: scheduleKeys.list(envelopeId),
                    update: (previous: EnvelopeSchedule[] | undefined) =>
                        previous === undefined
                            ? undefined
                            : previous.filter(
                                  (schedule) => schedule.id !== scheduleId,
                              ),
                },
            ],
            // The existing invalidation covers the list plus the generated
            // ledger rows and dashboard aggregates; do not refetch twice.
            invalidateOnSuccess: false,
        },
        onSuccess: (_data, { envelopeId }) => invalidate(envelopeId),
    });
}
