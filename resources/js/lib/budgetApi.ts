import { api, setResourceShare } from '@/lib/api';
import * as budgets from '@/routes/api/budgets';
import * as budgetEnvelopes from '@/routes/api/budgets/envelopes';
import * as budgetShares from '@/routes/api/budgets/shares';
import * as envelopes from '@/routes/api/envelopes';
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
        api(budgets.index({ query: { profile_id: profileId } })),

    get: (id: number): Promise<{ budget: BudgetDetail }> =>
        api(budgets.show(id)),

    create: (input: CreateBudgetInput): Promise<{ budget: BudgetDetail }> =>
        api(budgets.store(), { body: input }),

    publish: (id: number): Promise<{ budget: BudgetDetail }> =>
        api(budgets.publish(id)),

    destroy: (id: number): Promise<void> => api(budgets.destroy(id)),

    clone: (id: number, currency?: string): Promise<{ budget: BudgetDetail }> =>
        api(budgets.clone(id), {
            body: currency ? { currency } : undefined,
        }),

    addEnvelope: (
        id: number,
        input: AddEnvelopeInput,
    ): Promise<{ envelope: BudgetEnvelope }> =>
        api(budgetEnvelopes.store(id), { body: input }),

    updateEnvelope: (
        id: number,
        envelopeId: number,
        input: { amount: number },
    ): Promise<{ envelope: BudgetEnvelope }> =>
        api(budgetEnvelopes.update({ budget: id, envelopeId }), {
            body: input,
        }),

    removeEnvelope: (id: number, envelopeId: number): Promise<void> =>
        api(budgetEnvelopes.destroy({ budget: id, envelopeId })),

    setShare: (id: number, userId: number, permission: string): Promise<void> =>
        setResourceShare(
            {
                update: budgetShares.update({ budget: id, userId }),
                store: budgetShares.store(id),
            },
            userId,
            permission,
        ),

    removeShare: (id: number, userId: number): Promise<void> =>
        api(budgetShares.destroy({ budget: id, userId })),

    transferOwnership: (
        id: number,
        userId: number,
    ): Promise<{ budget: Budget }> =>
        api(budgets.transferOwnership(id), { body: { user_id: userId } }),

    envelopeOptions: (): Promise<{ envelopes: EnvelopeOption[] }> =>
        api(envelopes.index()),
};
