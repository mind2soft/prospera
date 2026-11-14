import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { useQuery } from '@/composables/query';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { onboardingApi } from '@/lib/onboardingApi';

export const onboardingKeys = {
    progress: (profileId: number) =>
        ['onboarding', 'progress', profileId] as const,
};

/**
 * Setup progress is private to the profile owner (`manage`): the query only
 * runs for profiles the user owns, so members never hit the 403 path.
 */
export function useOnboardingProgress(
    profileId?: MaybeRefOrGetter<number | null>,
) {
    const current = useCurrentProfile();
    const id = computed(() =>
        profileId === undefined ? current.profileId.value : toValue(profileId),
    );
    const isOwner = computed(
        () =>
            current.profiles.value.find((profile) => profile.id === id.value)
                ?.is_owner === true,
    );

    return useQuery({
        queryKey: computed(() => onboardingKeys.progress(toValue(id) ?? 0)),
        queryFn: () => onboardingApi.progress(toValue(id) ?? 0),
        enabled: computed(() => toValue(id) !== null && isOwner.value),
        select: (data) => data.progress,
    });
}
