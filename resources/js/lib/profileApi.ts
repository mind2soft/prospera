import { api } from '@/lib/api';
import type {
    FinancialProfile,
    InviteContext,
    ProfileInvite,
    ProfileMember,
} from '@/types';

export type CreateInviteResult = {
    invite: ProfileInvite;
    url: string; // "/invites/{rawKey}"
};

export const profileApi = {
    list: (): Promise<{ profiles: FinancialProfile[] }> => api('/api/profiles'),

    create: (name: string): Promise<{ profile: FinancialProfile }> =>
        api('/api/profiles', { method: 'POST', body: { name } }),

    rename: (
        profileId: number,
        name: string,
    ): Promise<{ profile: FinancialProfile }> =>
        api(`/api/profiles/${profileId}`, { method: 'PATCH', body: { name } }),

    destroy: (profileId: number): Promise<void> =>
        api(`/api/profiles/${profileId}`, { method: 'DELETE' }),

    members: (profileId: number): Promise<{ members: ProfileMember[] }> =>
        api(`/api/profiles/${profileId}/members`),

    removeMember: (profileId: number, userId: number): Promise<void> =>
        api(`/api/profiles/${profileId}/members/${userId}`, {
            method: 'DELETE',
        }),

    transferOwnership: (
        profileId: number,
        userId: number,
    ): Promise<{ profile: FinancialProfile }> =>
        api(`/api/profiles/${profileId}/transfer-ownership`, {
            method: 'POST',
            body: { user_id: userId },
        }),

    /** Pending (non-expired) invites — owner only. */
    pendingInvites: (
        profileId: number,
    ): Promise<{ invites: ProfileInvite[] }> =>
        api(`/api/profiles/${profileId}/invites`),

    createInvite: (
        profileId: number,
        email: string,
    ): Promise<CreateInviteResult> =>
        api(`/api/profiles/${profileId}/invites`, {
            method: 'POST',
            body: { email },
        }),

    revokeInvite: (profileId: number, inviteId: number): Promise<void> =>
        api(`/api/profiles/${profileId}/invites/${inviteId}`, {
            method: 'DELETE',
        }),

    inviteContext: (inviteKey: string): Promise<{ invite: InviteContext }> =>
        api(`/api/invites/${inviteKey}`),

    acceptInvite: (inviteKey: string): Promise<{ profile: FinancialProfile }> =>
        api(`/api/invites/${inviteKey}/accept`, { method: 'POST' }),
};
