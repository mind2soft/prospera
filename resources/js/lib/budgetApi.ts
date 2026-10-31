import { api, setResourceShare } from '@/lib/api';
import type { Budget, BudgetDetail, BudgetEnvelope } from '@/types';

export type EnvelopeOption = {
    id: number;
    name: string;
    direction: 'expense' | 'revenue';
    color: string;
    permission: 'owner' | 'contributor' | 'viewer' | 'none';
};

export type AddEnvelopeInput = {
    envelope_id: number;
    type: BudgetEnvelope['type'];
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

    addEnvelope: (
        id: number,
        input: AddEnvelopeInput,
    ): Promise<{ envelope: BudgetEnvelope }> =>
        api(`/api/budgets/${id}/envelopes`, { method: 'POST', body: input }),

    updateEnvelope: (
        id: number,
        envelopeId: number,
        input: { amount: number },
    ): Promise<{ envelope: BudgetEnvelope }> =>
        api(`/api/budgets/${id}/envelopes/${envelopeId}`, {
            method: 'PATCH',
            body: input,
        }),

    removeEnvelope: (id: number, envelopeId: number): Promise<void> =>
        api(`/api/budgets/${id}/envelopes/${envelopeId}`, {
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

    envelopeOptions: (): Promise<{ envelopes: EnvelopeOption[] }> =>
        api('/api/envelopes'),
};
