import { api, setResourceShare } from '@/lib/api';
import * as envelopes from '@/routes/api/envelopes';
import * as envelopeShares from '@/routes/api/envelopes/shares';
import type { Envelope, ResourceMember } from '@/types';

export type TransferEnvelopeOwnershipInput = { user_id: number };
export type EnvelopeShareInput = { user_id: number; permission: string };

export const envelopeApi = {
    list: (): Promise<{ envelopes: Envelope[] }> => api(envelopes.index()),

    shares: (id: number): Promise<{ shares: ResourceMember[] }> =>
        api(envelopeShares.index(id)),

    setShare: (id: number, userId: number, permission: string): Promise<void> =>
        setResourceShare(
            {
                update: envelopeShares.update({ envelope: id, userId }),
                store: envelopeShares.store(id),
            },
            userId,
            permission,
        ),

    removeShare: (id: number, userId: number): Promise<void> =>
        api(envelopeShares.destroy({ envelope: id, userId })),

    transferOwnership: (
        id: number,
        input: TransferEnvelopeOwnershipInput,
    ): Promise<{ envelope: Envelope }> =>
        api(envelopes.transferOwnership(id), { body: input }),
};
