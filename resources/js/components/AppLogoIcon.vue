<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Prospera mark: an envelope whose flap rises into a growth chevron. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        fill="none"
        stroke="currentColor"
        stroke-width="2.4"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="className"
        v-bind="$attrs"
    >
        <path d="M5 12.5v11A2.5 2.5 0 0 0 7.5 26h17a2.5 2.5 0 0 0 2.5-2.5v-11" />
        <path d="m5 12.5 11 8 11-8" />
        <path d="m10 9.5 6-4.5 6 4.5" />
    </svg>
</template>
