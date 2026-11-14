<script setup lang="ts">
defineProps<{ variant?: 'default' | 'locked' }>();

const ticks = Array.from({ length: 13 }, (_, i) => 32 + (i * 256) / 12);
</script>

<template>
    <!-- A start flag, a finish flag, and a balance that ends above where it began. -->
    <svg
        viewBox="0 0 320 160"
        fill="none"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="h-auto w-full rtl:-scale-x-100"
    >
        <g :class="variant === 'locked' && 'opacity-60'">
            <line
                x1="32"
                y1="124"
                x2="288"
                y2="124"
                class="stroke-muted-foreground"
                stroke-width="2"
            />
            <path
                v-for="x in ticks"
                :key="x"
                :d="`M${x} 120v8`"
                class="stroke-muted-foreground"
                stroke-width="1.5"
            />
            <line
                x1="32"
                y1="96"
                x2="288"
                y2="96"
                class="stroke-muted-foreground"
                stroke-width="1.5"
                stroke-dasharray="4 6"
            />
            <path d="M32 124V56" class="stroke-foreground" stroke-width="2.4" />
            <path
                d="M32 58h24l-6 8 6 8H32"
                class="fill-brand/15 stroke-brand"
                stroke-width="2"
            />
            <path
                d="M288 124V36"
                class="stroke-foreground"
                stroke-width="2.4"
            />
            <path
                d="M288 38h-24l6 8-6 8h24"
                class="fill-brand stroke-brand"
                stroke-width="2"
            />
            <path
                d="M32 96 C64 102 92 86 120 90 S172 70 204 76 S256 58 288 56"
                class="stroke-brand"
                stroke-width="3"
            />
            <circle
                cx="32"
                cy="96"
                r="4.5"
                class="fill-card stroke-brand"
                stroke-width="2"
            />
            <circle cx="288" cy="56" r="5" class="fill-brand" />
            <path d="M298 96h6V56h-6" class="stroke-brand" stroke-width="2" />
            <path d="M308 70l4-4 4 4" class="stroke-brand" stroke-width="2" />
        </g>
    </svg>
</template>
