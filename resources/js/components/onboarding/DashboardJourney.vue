<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';
import Icon from '@/components/Icon.vue';
import JourneyRoute from '@/components/onboarding/JourneyRoute.vue';
import MilestoneDialog from '@/components/onboarding/MilestoneDialog.vue';
import { Button } from '@/components/ui/button';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLocalJson } from '@/composables/useLocalJson';
import { useOnboardingJourney } from '@/composables/useOnboardingJourney';
import { useOnceFlag } from '@/composables/useOnceFlag';

/**
 * The setup route on the dashboard (docs/onboarding.md): owners only, until
 * the first budget exists. Not a widget — it can't be moved or removed.
 */
const { profileId } = useCurrentProfile();
const { journey } = useOnboardingJourney();

const bodyId = useId();
const stored = useLocalJson<boolean>(() =>
    profileId.value === null
        ? null
        : `prospera.onboarding.journey-collapsed.${profileId.value}`,
);
const collapsed = computed(() => stored.value.value ?? false);

const visible = computed(
    () => journey.value !== null && !journey.value.complete,
);

const budgetReady = useOnceFlag(() =>
    profileId.value === null
        ? null
        : `prospera.onboarding.budget-ready.${profileId.value}`,
);
const budgetReadyOpen = ref(false);

watch(
    () =>
        [
            journey.value?.current === 'budget' &&
                journey.value.canCreateBudget,
            budgetReady.loaded.value,
        ] as const,
    ([ready, loaded]) => {
        if (ready && loaded && !budgetReady.seen.value) {
            budgetReady.markSeen();
            budgetReadyOpen.value = true;
        }
    },
    { immediate: true },
);
</script>

<template>
    <section
        v-if="visible && journey"
        class="rounded-xl border bg-card p-4 sm:p-5"
        aria-labelledby="dashboard-journey-title"
        data-test="dashboard-journey"
    >
        <div class="flex min-h-10 items-center gap-3">
            <h2
                id="dashboard-journey-title"
                class="min-w-0 flex-1 text-base leading-6 font-medium"
            >
                {{ $t('onboarding.journey.label') }}
                <span class="text-muted-foreground">
                    ·
                    {{
                        $t('onboarding.journey.statusShort', {
                            step: journey.stepNumber,
                        })
                    }}</span
                >
            </h2>
            <Button
                variant="ghost"
                size="sm"
                as-child
                class="shrink-0 text-muted-foreground hover:text-foreground"
            >
                <Link href="/onboarding">
                    <Icon name="menu-book" class="size-4" aria-hidden="true" />
                    {{ $t('onboarding.journey.gettingStarted') }}
                </Link>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0"
                :aria-expanded="!collapsed"
                :aria-controls="bodyId"
                :aria-label="
                    collapsed
                        ? $t('onboarding.journey.expand')
                        : $t('onboarding.journey.collapse')
                "
                @click="stored.set(!collapsed)"
            >
                <Icon
                    :name="collapsed ? 'expand-more' : 'expand-less'"
                    class="size-5"
                    aria-hidden="true"
                />
            </Button>
        </div>
        <div
            :id="bodyId"
            class="grid transition-[grid-template-rows] duration-200"
            :class="collapsed ? 'grid-rows-[0fr]' : 'grid-rows-[1fr]'"
            :inert="collapsed"
        >
            <div class="min-h-0 overflow-hidden">
                <div class="mt-4 border-t pt-4">
                    <JourneyRoute :journey="journey" variant="compact" />
                </div>
            </div>
        </div>
    </section>

    <MilestoneDialog
        v-model:open="budgetReadyOpen"
        icon="event-available"
        :title="$t('onboarding.milestones.budgetReady.title')"
        :description="$t('onboarding.milestones.budgetReady.description')"
        :secondary-label="$t('onboarding.milestones.budgetReady.secondary')"
        :primary-label="$t('onboarding.milestones.budgetReady.primary')"
        primary-href="/budget?create=1"
        :journey="journey"
    />
</template>
