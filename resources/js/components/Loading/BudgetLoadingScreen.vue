<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

withDefaults(
    defineProps<{
        /**
         * False when the real budget selector is already rendered above the
         * skeleton (the detail query is still loading).
         */
        selector?: boolean;
    }>(),
    { selector: true },
);
</script>

<template>
    <!--
        Minimal stand-in for the Budget page, shown while the data loads so
        the page never appears as if it had no data (#22).
    -->
    <div
        class="flex flex-col gap-6"
        role="status"
        :aria-label="$t('layout.loading.budgets')"
        data-testid="budget-loading"
    >
        <div v-if="selector" class="flex items-center justify-between gap-4">
            <Skeleton class="h-9 w-56" />
            <Skeleton class="h-9 w-28" />
        </div>

        <div v-for="bar in 2" :key="bar" class="flex flex-col gap-2">
            <div class="flex items-baseline justify-between gap-4">
                <Skeleton class="h-4 w-20" />
                <Skeleton class="h-4 w-16" />
            </div>
            <Skeleton class="h-3 w-full rounded-full" />
        </div>

        <div class="overflow-hidden rounded-lg border">
            <div
                class="flex items-center gap-4 border-b bg-muted/50 px-4 py-2.5"
            >
                <Skeleton class="h-3 w-24" />
                <Skeleton class="ml-auto h-3 w-16" />
                <Skeleton class="h-3 w-16" />
            </div>
            <div
                v-for="row in 5"
                :key="row"
                class="flex items-center gap-4 border-b px-4 py-2.5 last:border-b-0"
            >
                <Skeleton class="h-4 w-36" />
                <Skeleton class="ml-auto h-4 w-24" />
                <Skeleton class="size-6 rounded-md" />
            </div>
        </div>
    </div>
</template>
