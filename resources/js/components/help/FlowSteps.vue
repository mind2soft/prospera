<script setup lang="ts">
import Icon from '@/components/Icon.vue';

defineProps<{ steps: string[] }>();
</script>

<template>
    <ol class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center">
        <li
            v-for="(step, index) in steps"
            :key="step"
            class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center"
        >
            <span
                class="flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm font-medium"
            >
                <span
                    class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-container text-xs font-semibold text-brand-container-foreground"
                    aria-hidden="true"
                    >{{
                        index + 1
                    }}</span
                >
                {{ step }}
            </span>
            <span
                v-if="index < steps.length - 1"
                class="flex justify-center text-muted-foreground"
                aria-hidden="true"
            >
                <Icon name="south" class="size-4 sm:hidden" />
                <Icon
                    name="arrow-forward"
                    class="hidden size-4 sm:block rtl:rotate-180"
                />
            </span>
        </li>
    </ol>
</template>
