import { api } from '@/lib/api';
import * as invites from '@/routes/api/invites';
import * as profiles from '@/routes/api/profiles';
import * as profileInvites from '@/routes/api/profiles/invites';
import * as profileMembers from '@/routes/api/profiles/members';
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
    list: (): Promise<{ profiles: FinancialProfile[] }> =>
        api(profiles.index()),

    create: (name: string): Promise<{ profile: FinancialProfile }> =>
        api(profiles.store(), { body: { name } }),

    rename: (
        profileId: number,
        name: string,
    ): Promise<{ profile: FinancialProfile }> =>
        api(profiles.update(profileId), { body: { name } }),

    destroy: (profileId: number): Promise<void> =>
        api(profiles.destroy(profileId)),

    members: (profileId: number): Promise<{ members: ProfileMember[] }> =>
        api(profileMembers.index(profileId)),

    removeMember: (profileId: number, userId: number): Promise<void> =>
        api(profileMembers.destroy({ profile: profileId, user: userId })),

    transferOwnership: (
        profileId: number,
        userId: number,
    ): Promise<{ profile: FinancialProfile }> =>
        api(profiles.transferOwnership(profileId), {
            body: { user_id: userId },
        }),

    setDefault: (profileId: number): Promise<{ profile: FinancialProfile }> =>
        api(profiles.setDefault(profileId)),

    /** Pending (non-expired) invites — owner only. */
    pendingInvites: (
        profileId: number,
    ): Promise<{ invites: ProfileInvite[] }> =>
        api(profileInvites.index(profileId)),

    createInvite: (
        profileId: number,
        email: string,
    ): Promise<CreateInviteResult> =>
        api(profileInvites.store(profileId), { body: { email } }),

    revokeInvite: (profileId: number, inviteId: number): Promise<void> =>
        api(profileInvites.destroy({ profile: profileId, invite: inviteId })),

    inviteContext: (inviteKey: string): Promise<{ invite: InviteContext }> =>
        api(invites.show(inviteKey)),

    acceptInvite: (inviteKey: string): Promise<{ profile: FinancialProfile }> =>
        api(invites.accept(inviteKey)),
};
