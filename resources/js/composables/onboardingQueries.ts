import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { useQuery } from '@/composables/query';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { onboardingApi } from '@/lib/onboardingApi';

export const onboardingKeys = {
    progress: (profileId: number) =>
        ['onboarding', 'progress', profileId] as const,
};

export function useOnboardingProgress(
    profileId?: MaybeRefOrGetter<number | null>,
) {
    const current = useCurrentProfile();
    const id = computed(() =>
        profileId === undefined ? current.profileId.value : toValue(profileId),
    );

    return useQuery({
        queryKey: computed(() => onboardingKeys.progress(toValue(id) ?? 0)),
        queryFn: () => onboardingApi.progress(toValue(id) ?? 0),
        enabled: computed(() => toValue(id) !== null),
        select: (data) => data.progress,
    });
}
