<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <!--
        Minimal stand-in for the Ledger page, shown while the data loads so
        the page never appears as if it had no data (#22).
    -->
    <div
        class="flex flex-col gap-6"
        role="status"
        aria-label="Loading ledgers"
        data-testid="ledger-loading"
    >
        <div class="flex items-center justify-between gap-4">
            <Skeleton class="h-9 w-64" />
            <Skeleton class="h-9 w-28" />
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[auto_1fr]">
            <Skeleton class="h-80 w-full max-w-sm rounded-xl" />

            <div class="flex flex-col gap-4">
                <Skeleton class="h-44 w-full rounded-lg" />

                <div class="overflow-hidden rounded-lg border">
                    <div
                        class="flex items-center gap-4 border-b bg-muted/50 px-4 py-2.5"
                    >
                        <Skeleton class="h-3 w-16" />
                        <Skeleton class="h-3 w-24" />
                        <Skeleton class="ml-auto h-3 w-16" />
                        <Skeleton class="h-3 w-16" />
                    </div>
                    <div
                        v-for="row in 4"
                        :key="row"
                        class="flex items-center gap-4 border-b px-4 py-2.5 last:border-b-0"
                    >
                        <Skeleton class="h-4 w-20" />
                        <Skeleton class="h-4 w-32" />
                        <Skeleton class="ml-auto h-4 w-24" />
                        <Skeleton class="size-6 rounded-md" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
