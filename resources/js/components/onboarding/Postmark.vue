<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import type { MaterialSymbol } from '@/lib/materialSymbols';

/**
 * A finished stage is "stamped": a double-ring postmark with wavy
 * cancellation lines (decorative — the surrounding text states the status).
 */
const props = withDefaults(
    defineProps<{
        icon?: MaterialSymbol;
        size?: 'sm' | 'md' | 'lg';
        /** Press the stamp once (a milestone moment). */
        animate?: boolean;
    }>(),
    { icon: 'check', size: 'md', animate: false },
);

const sizeClass = computed(
    () => ({ sm: 'h-6', md: 'h-[4.5rem]', lg: 'h-30' })[props.size],
);
const iconWrapClass = computed(
    () => ({ sm: 'size-3.5', md: 'size-9', lg: 'size-15' })[props.size],
);
</script>

<template>
    <span
        class="relative inline-block shrink-0 align-middle"
        :class="[
            sizeClass,
            size === 'sm' ? 'aspect-square' : 'aspect-[160/96]',
        ]"
        aria-hidden="true"
    >
        <svg
            aria-hidden="true"
            focusable="false"
            :viewBox="size === 'sm' ? '8 4 88 88' : '0 0 160 96'"
            fill="none"
            stroke-linecap="round"
            class="size-full -rotate-[8deg] text-brand"
            :class="[
                size === 'sm' ? 'origin-center' : 'origin-[32.5%_50%]',
                animate && 'animate-postmark',
            ]"
        >
            <circle
                cx="52"
                cy="48"
                r="40"
                stroke="currentColor"
                :stroke-width="size === 'sm' ? 6 : 2.5"
            />
            <circle
                v-if="size !== 'sm'"
                cx="52"
                cy="48"
                r="33"
                stroke="currentColor"
                stroke-width="1"
                stroke-dasharray="2 4"
            />
            <g
                v-if="size !== 'sm'"
                stroke="currentColor"
                stroke-width="2.5"
                :class="animate && 'animate-postmark-ink'"
            >
                <path d="M100 34q7.5-6 15 0t15 0t15 0" />
                <path d="M100 48q7.5-6 15 0t15 0t15 0" />
                <path d="M100 62q7.5-6 15 0t15 0t15 0" />
            </g>
        </svg>
        <span
            class="absolute top-1/2 grid -translate-y-1/2 place-items-center rounded-full bg-brand text-brand-foreground"
            :class="[
                iconWrapClass,
                size === 'sm'
                    ? 'start-1/2 -translate-x-1/2 rtl:translate-x-1/2'
                    : 'start-[32.5%] -translate-x-1/2 rtl:translate-x-1/2',
            ]"
        >
            <Icon :name="icon" class="size-[70%]" />
        </span>
    </span>
</template>
