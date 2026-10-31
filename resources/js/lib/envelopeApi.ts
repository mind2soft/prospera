import { api, setResourceShare } from '@/lib/api';
import type { Envelope, ResourceMember } from '@/types';

export type TransferEnvelopeOwnershipInput = { user_id: number };
export type EnvelopeShareInput = { user_id: number; permission: string };

export const envelopeApi = {
    list: (): Promise<{ envelopes: Envelope[] }> => api('/api/envelopes'),

    shares: (id: number): Promise<{ shares: ResourceMember[] }> =>
        api(`/api/envelopes/${id}/shares`),

    setShare: (id: number, userId: number, permission: string): Promise<void> =>
        setResourceShare(`/api/envelopes/${id}/shares`, userId, permission),

    removeShare: (id: number, userId: number): Promise<void> =>
        api(`/api/envelopes/${id}/shares/${userId}`, { method: 'DELETE' }),

    transferOwnership: (
        id: number,
        input: TransferEnvelopeOwnershipInput,
    ): Promise<{ envelope: Envelope }> =>
        api(`/api/envelopes/${id}/transfer-ownership`, {
            method: 'POST',
            body: input,
        }),
};
