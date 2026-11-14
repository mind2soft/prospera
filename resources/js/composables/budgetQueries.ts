import { useQueryClient } from '@tanstack/vue-query';
import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { invalidateDashboardData } from '@/composables/dashboardQueries';
import { type QueryKey, useMutation, useQuery } from '@/composables/query';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { type AddEnvelopeInput, budgetApi } from '@/lib/budgetApi';
import { budgetSaveQueue } from '@/lib/budgetSaveQueue';

export const budgetKeys = {
    all: (profileId: number) => ['budgets', profileId] as const,
    detail: (id: number) => ['budgets', 'detail', id] as const,
};

function budgetKey(id: MaybeRefOrGetter<number | null>): QueryKey {
    return budgetKeys.detail(toValue(id) ?? 0);
}

export function useBudgets() {
    const { profileId } = useCurrentProfile();

    return useQuery({
        queryKey: computed(() => budgetKeys.all(toValue(profileId) ?? 0)),
        queryFn: () => budgetApi.list(toValue(profileId) ?? 0),
        enabled: computed(() => toValue(profileId) !== null),
        select: (data) => data.budgets,
    });
}

export function useBudget(id: MaybeRefOrGetter<number | null>) {
    return useQuery({
        queryKey: computed(() => budgetKey(id)),
        queryFn: () => budgetApi.get(toValue(id) ?? 0),
        enabled: computed(() => toValue(id) !== null),
        select: (data) => data.budget,
    });
}

export function useCreateBudget() {
    const queryClient = useQueryClient();
    const { profileId } = useCurrentProfile();

    return useMutation({
        mutationFn: (currency: string) =>
            budgetApi.create({ profile_id: toValue(profileId) ?? 0, currency }),
        onSuccess: (data) => {
            queryClient.setQueryData(budgetKey(data.budget.id), {
                budget: data.budget,
            });
            void queryClient.invalidateQueries({
                queryKey: ['budgets'],
            });
            void queryClient.invalidateQueries({
                queryKey: ['onboarding', 'progress'],
            });
            invalidateDashboardData(queryClient);
        },
    });
}

export function usePublishBudget() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, month }: { id: number; month: string }) =>
            budgetApi.publish(id, month),
        onSuccess: (data) => {
            queryClient.setQueryData(budgetKey(data.budget.id), {
                budget: data.budget,
            });
            void queryClient.invalidateQueries({ queryKey: ['budgets'] });
            invalidateDashboardData(queryClient);
        },
    });
}

export function useCloneBudget() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, currency }: { id: number; currency?: string }) =>
            budgetApi.clone(id, currency),
        onSuccess: (data) => {
            queryClient.setQueryData(budgetKey(data.budget.id), {
                budget: data.budget,
            });
            void queryClient.invalidateQueries({ queryKey: ['budgets'] });
        },
    });
}

export function useDeleteBudget() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => budgetApi.destroy(id),
        onSuccess: (_data, id) => {
            queryClient.removeQueries({ queryKey: budgetKeys.detail(id) });
            void queryClient.invalidateQueries({ queryKey: ['budgets'] });
            void queryClient.invalidateQueries({
                queryKey: ['onboarding', 'progress'],
            });
            invalidateDashboardData(queryClient);
        },
    });
}

export function useAddEnvelope() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({
            budgetId,
            input,
        }: {
            budgetId: number;
            input: AddEnvelopeInput;
        }) => budgetApi.addEnvelope(budgetId, input),
        onSuccess: (_data, { budgetId }) => {
            void queryClient.invalidateQueries({
                queryKey: budgetKeys.detail(budgetId),
            });
            invalidateDashboardData(queryClient);
        },
    });
}

export function useUpdateEnvelope() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({
            budgetId,
            envelopeId,
            amount,
            expectedRevision,
        }: {
            budgetId: number;
            envelopeId: number;
            amount: number;
            expectedRevision: number;
        }) =>
            budgetApi.updateEnvelope(budgetId, envelopeId, {
                amount,
                expected_revision: expectedRevision,
            }),
        onSuccess: (_data, { budgetId, envelopeId, amount }) => {
            // TanStack runs this onSuccess before `mutateAsync` resolves, and
            // the queue records the acknowledgment only after — so this save
            // still reports itself as pending at this point. Ask the queue
            // whether anything besides it is unsaved: a queued or in-flight
            // newer amount must survive this older save's refetch.
            if (
                budgetSaveQueue.hasPendingEdits(budgetId, {
                    envelopeId,
                    amount,
                })
            ) {
                return;
            }
            void queryClient.invalidateQueries({
                queryKey: budgetKeys.detail(budgetId),
            });
            invalidateDashboardData(queryClient);
        },
    });
}

export function useRemoveEnvelope() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({
            budgetId,
            envelopeId,
        }: {
            budgetId: number;
            envelopeId: number;
        }) => budgetApi.removeEnvelope(budgetId, envelopeId),
        onSuccess: (_data, { budgetId }) => {
            void queryClient.invalidateQueries({
                queryKey: budgetKeys.detail(budgetId),
            });
            invalidateDashboardData(queryClient);
        },
    });
}

// --- sharing (profile members only) -----------------------------------------

export function useSetBudgetShare() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({
            budgetId,
            userId,
            permission,
        }: {
            budgetId: number;
            userId: number;
            permission: string;
        }) => budgetApi.setShare(budgetId, userId, permission),
        onSuccess: (_data, { budgetId }) => {
            void queryClient.invalidateQueries({
                queryKey: budgetKeys.detail(budgetId),
            });
        },
    });
}

export function useRemoveBudgetShare() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({
            budgetId,
            userId,
        }: {
            budgetId: number;
            userId: number;
        }) => budgetApi.removeShare(budgetId, userId),
        onSuccess: (_data, { budgetId }) => {
            void queryClient.invalidateQueries({
                queryKey: budgetKeys.detail(budgetId),
            });
        },
    });
}

export function useTransferBudgetOwnership() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({
            budgetId,
            userId,
        }: {
            budgetId: number;
            userId: number;
        }) => budgetApi.transferOwnership(budgetId, userId),
        onSuccess: (data) => {
            void queryClient.invalidateQueries({
                queryKey: budgetKeys.detail(data.budget.id),
            });
            void queryClient.invalidateQueries({ queryKey: ['budgets'] });
        },
    });
}
