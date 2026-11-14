<script setup lang="ts">
defineProps<{ variant?: 'default' | 'locked' }>();
</script>

<template>
    <!-- Money in (+), money out (−), recorded on one sheet; a week of habit below. -->
    <svg
        viewBox="0 0 320 180"
        fill="none"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="h-auto w-full rtl:-scale-x-100"
    >
        <g :class="variant === 'locked' && 'opacity-60'">
            <path
                d="M120 30a6 6 0 0 1 6-6h68a6 6 0 0 1 6 6v108l-10-6-10 6-10-6-10 6-10-6-10 6-10-6-10 6Z"
                class="fill-card stroke-foreground"
                stroke-width="2.4"
            />
            <path
                d="M136 50h48M136 66h36M136 82h44M136 98h28"
                class="stroke-muted-foreground"
                stroke-width="2"
            />
            <path
                d="M28 74H104M96 66l8 8-8 8"
                class="stroke-chart-revenue"
                stroke-width="3"
            />
            <path
                d="M34 50h12M40 44v12"
                class="stroke-chart-revenue"
                stroke-width="2.4"
            />
            <path
                d="M216 58H288M280 50l8 8-8 8"
                class="stroke-chart-expense"
                stroke-width="3"
            />
            <path
                d="M216 98H272M264 90l8 8-8 8"
                class="stroke-chart-expense"
                stroke-width="2.4"
            />
            <path
                d="M282 122h12"
                class="stroke-chart-expense"
                stroke-width="2.4"
            />
            <g stroke-width="2">
                <circle
                    cx="104"
                    cy="164"
                    r="5"
                    class="fill-brand stroke-brand"
                />
                <circle
                    cx="122"
                    cy="164"
                    r="5"
                    class="fill-brand stroke-brand"
                />
                <circle
                    cx="140"
                    cy="164"
                    r="5"
                    class="fill-brand stroke-brand"
                />
                <circle
                    cx="160"
                    cy="164"
                    r="5"
                    class="fill-brand stroke-brand"
                />
                <circle
                    cx="178"
                    cy="164"
                    r="5"
                    class="fill-brand stroke-brand"
                />
                <circle
                    cx="196"
                    cy="164"
                    r="5"
                    class="fill-card stroke-brand"
                />
                <circle
                    cx="214"
                    cy="164"
                    r="5"
                    class="fill-card stroke-muted-foreground"
                    stroke-dasharray="2 3"
                />
            </g>
        </g>
    </svg>
</template>
