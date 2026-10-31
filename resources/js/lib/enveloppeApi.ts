import { api, setResourceShare } from '@/lib/api';
import type { Enveloppe, ResourceMember } from '@/types';

export type TransferEnveloppeOwnershipInput = { user_id: number };
export type EnveloppeShareInput = { user_id: number; permission: string };

export const enveloppeApi = {
    list: (): Promise<{ enveloppes: Enveloppe[] }> => api('/api/enveloppes'),

    shares: (id: number): Promise<{ shares: ResourceMember[] }> =>
        api(`/api/enveloppes/${id}/shares`),

    setShare: (id: number, userId: number, permission: string): Promise<void> =>
        setResourceShare(`/api/enveloppes/${id}/shares`, userId, permission),

    removeShare: (id: number, userId: number): Promise<void> =>
        api(`/api/enveloppes/${id}/shares/${userId}`, { method: 'DELETE' }),

    transferOwnership: (
        id: number,
        input: TransferEnveloppeOwnershipInput,
    ): Promise<{ enveloppe: Enveloppe }> =>
        api(`/api/enveloppes/${id}/transfer-ownership`, {
            method: 'POST',
            body: input,
        }),
};
