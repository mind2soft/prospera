import { api, setResourceShare } from '@/lib/api';
import type { Budget, BudgetDetail, BudgetEnveloppe } from '@/types';

export type EnveloppeOption = {
    id: number;
    name: string;
    direction: 'expense' | 'revenue';
    color: string;
    permission: 'owner' | 'contributor' | 'viewer' | 'none';
};

export type AddEnveloppeInput = {
    enveloppe_id: number;
    type: BudgetEnveloppe['type'];
    amount: number;
};

export type CreateBudgetInput = {
    profile_id: number;
    currency: string;
};

export const budgetApi = {
    list: (profileId: number): Promise<{ budgets: Budget[] }> =>
        api(`/api/budgets?profile_id=${profileId}`),

    get: (id: number): Promise<{ budget: BudgetDetail }> =>
        api(`/api/budgets/${id}`),

    create: (input: CreateBudgetInput): Promise<{ budget: BudgetDetail }> =>
        api('/api/budgets', { method: 'POST', body: input }),

    publish: (id: number): Promise<{ budget: BudgetDetail }> =>
        api(`/api/budgets/${id}/publish`, { method: 'POST' }),

    destroy: (id: number): Promise<void> =>
        api(`/api/budgets/${id}`, { method: 'DELETE' }),

    clone: (id: number, currency?: string): Promise<{ budget: BudgetDetail }> =>
        api(`/api/budgets/${id}/clone`, {
            method: 'POST',
            body: currency ? { currency } : undefined,
        }),

    addEnveloppe: (
        id: number,
        input: AddEnveloppeInput,
    ): Promise<{ enveloppe: BudgetEnveloppe }> =>
        api(`/api/budgets/${id}/enveloppes`, { method: 'POST', body: input }),

    updateEnveloppe: (
        id: number,
        enveloppeId: number,
        input: { amount: number },
    ): Promise<{ enveloppe: BudgetEnveloppe }> =>
        api(`/api/budgets/${id}/enveloppes/${enveloppeId}`, {
            method: 'PATCH',
            body: input,
        }),

    removeEnveloppe: (id: number, enveloppeId: number): Promise<void> =>
        api(`/api/budgets/${id}/enveloppes/${enveloppeId}`, {
            method: 'DELETE',
        }),

    setShare: (id: number, userId: number, permission: string): Promise<void> =>
        setResourceShare(`/api/budgets/${id}/shares`, userId, permission),

    removeShare: (id: number, userId: number): Promise<void> =>
        api(`/api/budgets/${id}/shares/${userId}`, { method: 'DELETE' }),

    transferOwnership: (
        id: number,
        userId: number,
    ): Promise<{ budget: Budget }> =>
        api(`/api/budgets/${id}/transfer-ownership`, {
            method: 'POST',
            body: { user_id: userId },
        }),

    enveloppeOptions: (): Promise<{ enveloppes: EnveloppeOption[] }> =>
        api('/api/enveloppes'),
};
