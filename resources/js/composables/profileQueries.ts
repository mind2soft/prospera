import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useQueryClient } from 'vue-query';
import { profileApi } from '@/lib/profileApi';
import type { FinancialProfile, ProfileMember } from '@/types';
import { useMutation, useQuery } from '@/composables/query';

export const profileKeys = {
    all: ['profiles'] as const,
    members: (id: number) => ['profiles', 'members', id] as const,
    invites: (id: number) => ['profiles', 'invites', id] as const,
};

export function useProfiles() {
    const page = usePage();
    return useQuery({
        queryKey: profileKeys.all,
        // Guests (e.g. the registration page) have no profiles to load.
        enabled: computed(() => Boolean(page.props.auth?.user)),
        queryFn: () => profileApi.list(),
        select: (data) => data.profiles,
    });
}

export function useProfileMembers(profileId: MaybeRefOrGetter<number | null>) {
    return useQuery({
        queryKey: computed(() => profileKeys.members(toValue(profileId) ?? 0)),
        queryFn: () => profileApi.members(toValue(profileId) ?? 0),
        enabled: computed(() => toValue(profileId) !== null),
        select: (data) => data.members,
    });
}

/**
 * Pending (non-expired) invites of a profile — owner only, so `enabled`
 * should reflect profile ownership.
 */
export function usePendingInvites(
    profileId: MaybeRefOrGetter<number | null>,
    enabled?: MaybeRefOrGetter<boolean>,
) {
    return useQuery({
        queryKey: computed(() => profileKeys.invites(toValue(profileId) ?? 0)),
        queryFn: () => profileApi.pendingInvites(toValue(profileId) ?? 0),
        enabled: computed(
            () => (toValue(enabled) ?? true) && toValue(profileId) !== null,
        ),
        select: (data) => data.invites,
    });
}

/**
 * Deduplicated members across all the user's profiles — the candidate pool
 * for sharing user-owned resources (enveloppes): a user may grant a
 * permission only to members who share a profile with the resource owner.
 */
export function useProfileCircle(enabled?: MaybeRefOrGetter<boolean>) {
    const profilesQuery = useProfiles();

    return useQuery({
        queryKey: computed(() => [
            'profiles',
            'circle',
            (profilesQuery.data.value ?? []).map((p) => p.id),
        ]),
        queryFn: async (): Promise<ProfileMember[]> => {
            const profiles: FinancialProfile[] = profilesQuery.data.value ?? [];
            const results = await Promise.all(
                profiles.map((profile) => profileApi.members(profile.id)),
            );
            const byId = new Map<number, ProfileMember>();
            for (const { members } of results) {
                for (const member of members) {
                    if (!byId.has(member.id)) {
                        byId.set(member.id, member);
                    }
                }
            }
            return [...byId.values()];
        },
        enabled: computed(
            () =>
                (toValue(enabled) ?? true) &&
                (profilesQuery.data.value?.length ?? 0) > 0,
        ),
    });
}

function useInvalidateAll() {
    const queryClient = useQueryClient();

    return () => {
        void queryClient.invalidateQueries();
    };
}

export function useCreateProfile() {
    const invalidateAll = useInvalidateAll();

    return useMutation((name: string) => profileApi.create(name), {
        onSuccess: () => invalidateAll(),
    });
}

export function useRenameProfile() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ profileId, name }: { profileId: number; name: string }) =>
            profileApi.rename(profileId, name),
        {
            onSuccess: () => {
                void queryClient.invalidateQueries(profileKeys.all);
            },
        },
    );
}

export function useDeleteProfile() {
    const invalidateAll = useInvalidateAll();

    return useMutation((profileId: number) => profileApi.destroy(profileId), {
        onSuccess: () => invalidateAll(),
    });
}

export function useRemoveMember() {
    const invalidateAll = useInvalidateAll();

    return useMutation(
        ({ profileId, userId }: { profileId: number; userId: number }) =>
            profileApi.removeMember(profileId, userId),
        {
            onSuccess: () => invalidateAll(),
        },
    );
}

export function useTransferProfileOwnership() {
    const invalidateAll = useInvalidateAll();

    return useMutation(
        ({ profileId, userId }: { profileId: number; userId: number }) =>
            profileApi.transferOwnership(profileId, userId),
        {
            onSuccess: () => invalidateAll(),
        },
    );
}

export function useCreateInvite() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ profileId, email }: { profileId: number; email: string }) =>
            profileApi.createInvite(profileId, email),
        {
            onSuccess: (_data, { profileId }) => {
                void queryClient.invalidateQueries(
                    profileKeys.invites(profileId),
                );
            },
        },
    );
}

export function useRevokeInvite() {
    const queryClient = useQueryClient();

    return useMutation(
        ({ profileId, inviteId }: { profileId: number; inviteId: number }) =>
            profileApi.revokeInvite(profileId, inviteId),
        {
            onSuccess: (_data, { profileId }) => {
                void queryClient.invalidateQueries(
                    profileKeys.invites(profileId),
                );
            },
        },
    );
}

/** Accepts an invite as the signed-in user (docs — link-based invites). */
export function useAcceptInvite() {
    const invalidateAll = useInvalidateAll();

    return useMutation(
        (inviteKey: string) => profileApi.acceptInvite(inviteKey),
        {
            onSuccess: () => invalidateAll(),
        },
    );
}
