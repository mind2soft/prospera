import { computed } from 'vue';
import { useBudgets } from '@/composables/budgetQueries';
import { useOnboardingProgress } from '@/composables/onboardingQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { deriveJourney, type Journey } from '@/lib/onboardingJourney';

export type JourneyState =
    | 'notStarted'
    | 'inProgress'
    | 'complete'
    | 'readOnly';

/**
 * The current profile's setup journey (lib/onboardingJourney.ts). Setup
 * belongs to the profile owner: for anyone else `state` is `readOnly` and no
 * progress is fetched.
 */
export function useOnboardingJourney() {
    const { currentProfile } = useCurrentProfile();
    const progressQuery = useOnboardingProgress();
    const budgetsQuery = useBudgets();

    const isOwner = computed(() => currentProfile.value?.is_owner === true);

    const journey = computed<Journey | null>(() => {
        const progress = progressQuery.data.value;
        // Wait for the budget list too, so a profile that already has a
        // budget never flashes an incomplete journey.
        const budgetsSettled =
            budgetsQuery.data.value !== undefined || budgetsQuery.isError.value;
        if (!isOwner.value || progress === undefined || !budgetsSettled) {
            return null;
        }

        return deriveJourney(progress, {
            hasBudget: (budgetsQuery.data.value?.length ?? 0) > 0,
        });
    });

    const state = computed<JourneyState | null>(() => {
        if (currentProfile.value === null) {
            return null;
        }
        if (!isOwner.value) {
            return 'readOnly';
        }
        const j = journey.value;
        if (j === null) {
            return null;
        }
        if (j.complete) {
            return 'complete';
        }
        return j.current === 'envelopes' &&
            j.envelopes.revenue + j.envelopes.expense === 0
            ? 'notStarted'
            : 'inProgress';
    });

    return {
        progress: computed(() => progressQuery.data.value),
        journey,
        state,
        isOwner,
        /** Owner of the current profile, with progress loaded. */
        enabled: computed(() => journey.value !== null),
        /** Owner, progress not loaded yet (and not failed). */
        isPending: computed(
            () =>
                isOwner.value &&
                journey.value === null &&
                !progressQuery.isError.value,
        ),
        isError: computed(
            () =>
                isOwner.value &&
                progressQuery.isError.value &&
                progressQuery.data.value === undefined,
        ),
        isFetching: progressQuery.isFetching,
        refetch: () => progressQuery.refetch(),
    };
}
