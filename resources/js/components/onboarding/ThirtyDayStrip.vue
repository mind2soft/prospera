<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/Icon.vue';
import { CHALLENGE_DAYS } from '@/lib/onboardingJourney';

const props = withDefaults(
    defineProps<{
        /** Full days of history (0–30). */
        days: number;
        started: boolean;
        total?: number;
        /** A static illustration (the transactions guide), not live progress. */
        demo?: boolean;
    }>(),
    { total: CHALLENGE_DAYS, demo: false },
);

const { t } = useI18n();

const done = computed(() =>
    props.started ? Math.min(props.total, props.days) : 0,
);
const day = computed(() =>
    props.started ? Math.min(props.total, done.value + 1) : 0,
);

const cellClass = (index: number): string => {
    if (index < done.value) {
        return 'bg-brand';
    }
    if (props.started && index === done.value) {
        return 'bg-background ring-2 ring-brand ring-inset';
    }
    return 'border border-border bg-muted';
};

const valueText = computed(() =>
    props.demo
        ? t('onboarding.strip.example', { day: day.value, total: props.total })
        : props.started
          ? t('onboarding.strip.dayOf', { day: day.value, total: props.total })
          : t('onboarding.route.clockNotStarted'),
);
</script>

<template>
    <div class="grid gap-1.5">
        <div
            role="progressbar"
            :aria-valuemin="0"
            :aria-valuemax="total"
            :aria-valuenow="done"
            :aria-valuetext="valueText"
            :aria-label="$t('onboarding.route.stages.challenge.title')"
            class="grid grid-cols-15 gap-0.5 sm:grid-cols-30"
        >
            <span
                v-for="index in total"
                :key="index"
                class="h-2.5 rounded-[2px]"
                :class="cellClass(index - 1)"
                aria-hidden="true"
            />
        </div>
        <div
            class="grid grid-cols-3 text-xs text-muted-foreground tabular-nums"
            aria-hidden="true"
        >
            <span>{{ $t('onboarding.strip.start') }}</span>
            <span class="text-center">{{ $t('onboarding.strip.middle') }}</span>
            <span class="inline-flex items-center justify-end gap-1">
                {{ $t('onboarding.strip.end') }}
                <Icon name="flag" class="size-3.5" />
            </span>
        </div>
        <p v-if="!started && !demo" class="text-sm text-muted-foreground">
            {{ $t('onboarding.route.clockNotStarted') }}
        </p>
    </div>
</template>
