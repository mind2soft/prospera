<script setup lang="ts">
import Icon from '@/components/Icon.vue';

defineProps<{
    name: string;
    /** User-picked envelope color: shown as a swatch, never as text color. */
    color: string;
    direction: 'expense' | 'revenue';
}>();
</script>

<template>
    <span
        class="inline-flex max-w-full items-center gap-1.5 rounded-full border bg-background px-2 py-0.5 text-xs font-medium text-foreground"
    >
        <span
            class="size-2.5 shrink-0 rounded-full"
            :style="{ backgroundColor: color }"
            aria-hidden="true"
        />
        <Icon
            :name="direction === 'revenue' ? 'north' : 'south'"
            class="size-3 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <span class="truncate">{{ name }}</span>
    </span>
</template>
