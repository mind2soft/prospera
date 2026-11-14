<script setup lang="ts">
defineProps<{ variant?: 'default' | 'locked' }>();
</script>

<template>
    <!-- A half dial: spent so far (arc), the plan (tick), where you are (needle). -->
    <svg
        viewBox="0 0 240 150"
        fill="none"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="h-auto w-full"
    >
        <g :class="variant === 'locked' && 'opacity-60'">
            <path
                d="M32 124A88 88 0 0 1 208 124"
                class="stroke-muted-foreground/25"
                stroke-width="14"
            />
            <path
                d="M32 124A88 88 0 0 1 171.7 52.8"
                class="stroke-brand"
                stroke-width="14"
            />
            <path
                d="M183.1 78.2L200.9 65.2"
                class="stroke-foreground"
                stroke-width="2.4"
            />
            <path
                d="M120 124L157.6 72.2"
                class="stroke-foreground"
                stroke-width="3"
            />
            <circle cx="120" cy="124" r="7" class="fill-foreground" />
            <rect
                x="96"
                y="134"
                width="48"
                height="12"
                rx="3"
                class="fill-card stroke-foreground"
                stroke-width="1.5"
            />
        </g>
    </svg>
</template>
