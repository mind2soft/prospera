import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import { useQueryClient } from 'vue-query';
import { budgetApi, type AddEnveloppeInput } from '@/lib/budgetApi';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useMutation, useQuery, type QueryKey } from '@/composables/query';

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

    return useMutation(
        (currency: string) =>
            budgetApi.create({ profile_id: toValue(profileId) ?? 0, currency }),
        {
            onSuccess: (data) => {
                queryClient.setQueryData(budgetKey(data.budget.id), {
                    budget: data.budget,
                });
                void queryClient.invalidateQueries({
                    queryKey: ['budgets'],
                });
            },
        },
    );
}

export function usePublishBudget() {
    const queryClient = useQueryClient();

    return useMutation((id: number) => budgetApi.publish(id), {
        onSuccess: (data) => {
            queryClient.setQueryData(budgetKey(data.budget.id), {
                budget: data.budget,
            });
            void queryClient.invalidateQueries({ queryKey: ['budgets'] });
        },
    });
}

export function useCloneBudget() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ id, currency }: { id: number; currency?: string }) =>
            budgetApi.clone(id, currency),
        {
            onSuccess: (data) => {
                queryClient.setQueryData(budgetKey(data.budget.id), {
                    budget: data.budget,
                });
                void queryClient.invalidateQueries({ queryKey: ['budgets'] });
            },
        },
    );
}

export function useDeleteBudget() {
    const queryClient = useQueryClient();

    return useMutation((id: number) => budgetApi.destroy(id), {
        onSuccess: (_data, id) => {
            queryClient.removeQueries(budgetKeys.detail(id));
            void queryClient.invalidateQueries({ queryKey: ['budgets'] });
        },
    });
}

export function useAddEnveloppe() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ budgetId, input }: { budgetId: number; input: AddEnveloppeInput }) =>
            budgetApi.addEnveloppe(budgetId, input),
        {
            onSuccess: (_data, { budgetId }) => {
                void queryClient.invalidateQueries(budgetKeys.detail(budgetId));
            },
        },
    );
}

export function useUpdateEnveloppe() {
    const queryClient = useQueryClient();

    return useMutation(
        ({
            budgetId,
            enveloppeId,
            amount,
        }: {
            budgetId: number;
            enveloppeId: number;
            amount: number;
        }) => budgetApi.updateEnveloppe(budgetId, enveloppeId, { amount }),
        {
            onSuccess: (_data, { budgetId }) => {
                void queryClient.invalidateQueries(budgetKeys.detail(budgetId));
            },
        },
    );
}

export function useRemoveEnveloppe() {
    const queryClient = useQueryClient();

    return useMutation(
        ({
            budgetId,
            enveloppeId,
        }: {
            budgetId: number;
            enveloppeId: number;
        }) => budgetApi.removeEnveloppe(budgetId, enveloppeId),
        {
            onSuccess: (_data, { budgetId }) => {
                void queryClient.invalidateQueries(budgetKeys.detail(budgetId));
            },
        },
    );
}

// --- sharing (profile members only) -----------------------------------------

export function useSetBudgetShare() {
    const queryClient = useQueryClient();

    return useMutation(
        ({
            budgetId,
            userId,
            permission,
        }: {
            budgetId: number;
            userId: number;
            permission: string;
        }) => budgetApi.setShare(budgetId, userId, permission),
        {
            onSuccess: (_data, { budgetId }) => {
                void queryClient.invalidateQueries(budgetKeys.detail(budgetId));
            },
        },
    );
}

export function useRemoveBudgetShare() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ budgetId, userId }: { budgetId: number; userId: number }) =>
            budgetApi.removeShare(budgetId, userId),
        {
            onSuccess: (_data, { budgetId }) => {
                void queryClient.invalidateQueries(budgetKeys.detail(budgetId));
            },
        },
    );
}

export function useTransferBudgetOwnership() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ budgetId, userId }: { budgetId: number; userId: number }) =>
            budgetApi.transferOwnership(budgetId, userId),
        {
            onSuccess: (data) => {
                void queryClient.invalidateQueries(
                    budgetKeys.detail(data.budget.id),
                );
                void queryClient.invalidateQueries({ queryKey: ['budgets'] });
            },
        },
    );
}
