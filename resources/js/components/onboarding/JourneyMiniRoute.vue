<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { type Journey, stagePage } from '@/lib/onboardingJourney';

withDefaults(
    defineProps<{
        journey: Journey;
        /** Dots link to their stage's page; static dots are decorative. */
        interactive?: boolean;
    }>(),
    { interactive: true },
);

const { t } = useI18n();

const statusLabel = (status: string): string =>
    t(
        status === 'done'
            ? 'onboarding.route.done'
            : status === 'current'
              ? 'onboarding.route.current'
              : 'onboarding.route.locked',
    );

const dotClass = (status: string): string =>
    ({
        done: 'bg-brand',
        current: 'bg-brand-container ring-2 ring-brand',
        locked: 'border border-dashed border-muted-foreground/60',
    })[status] ?? '';
</script>

<template>
    <ol
        class="flex shrink-0 items-center"
        :aria-label="
            interactive ? $t('onboarding.journey.routeLabel') : undefined
        "
        :aria-hidden="interactive ? undefined : 'true'"
    >
        <li
            v-for="(stage, index) in journey.stages"
            :key="stage.id"
            class="flex items-center"
            :aria-current="stage.status === 'current' ? 'step' : undefined"
        >
            <span
                v-if="index > 0"
                class="h-0.5 w-2 sm:w-4"
                :class="
                    journey.stages[index - 1].status === 'done'
                        ? 'bg-brand'
                        : 'border-t-2 border-dashed border-muted-foreground/40'
                "
                aria-hidden="true"
            />
            <Link
                v-if="interactive"
                :href="stagePage(stage.id)"
                class="relative grid size-5 place-items-center rounded-full outline-none after:absolute after:-inset-x-1.5 after:-inset-y-3 after:content-[''] focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                :aria-label="
                    $t('onboarding.route.stepLabel', {
                        step: stage.step,
                        title: $t(`onboarding.route.stages.${stage.id}.title`),
                        status: statusLabel(stage.status),
                    })
                "
            >
                <span
                    class="size-3 rounded-full"
                    :class="dotClass(stage.status)"
                />
            </Link>
            <span v-else class="grid size-5 place-items-center">
                <span
                    class="size-3 rounded-full"
                    :class="dotClass(stage.status)"
                />
            </span>
        </li>
    </ol>
</template>
