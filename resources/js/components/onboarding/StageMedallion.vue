<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import type { StageStatus } from '@/lib/onboardingJourney';

defineProps<{
    /** `neutral`: read-only overview, no progress. */
    status: StageStatus | 'neutral';
    step: number;
}>();
</script>

<template>
    <!-- Decorative: the stage's status line states the same in text. -->
    <span
        class="grid size-10 shrink-0 place-items-center rounded-full text-sm font-semibold tabular-nums"
        :class="{
            'bg-brand text-brand-foreground': status === 'done',
            'bg-brand-container text-brand-container-foreground ring-2 ring-brand ring-offset-2 ring-offset-background':
                status === 'current',
            'border-2 border-dashed border-muted-foreground/50 bg-background text-muted-foreground':
                status === 'locked',
            'border-2 border-border bg-card text-foreground':
                status === 'neutral',
        }"
        aria-hidden="true"
    >
        <Icon v-if="status === 'done'" name="check" class="size-5" />
        <template v-else-if="status === 'locked'">
            <Icon name="lock" class="size-4 sm:hidden" />
            <span class="hidden sm:inline">{{ step }}</span>
        </template>
        <span v-else>{{ step }}</span>
    </span>
</template>
