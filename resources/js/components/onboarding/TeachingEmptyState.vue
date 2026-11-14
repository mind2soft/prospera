<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { Card, CardContent } from '@/components/ui/card';

/**
 * An empty state that teaches the next setup step: illustration, one line,
 * an optional live meter, and one primary action.
 */
defineProps<{
    title: string;
    description: string;
}>();
</script>

<template>
    <Card class="py-0">
        <CardContent
            class="grid items-center gap-6 p-6 sm:p-8 md:grid-cols-[200px_minmax(0,1fr)]"
        >
            <div
                class="mx-auto w-full max-w-[200px] rounded-xl bg-brand-container p-4"
            >
                <slot name="illustration" />
            </div>
            <div class="grid gap-4 text-center md:text-start">
                <div class="grid gap-2">
                    <h2
                        class="text-2xl leading-8 font-semibold text-foreground"
                    >
                        {{ title }}
                    </h2>
                    <p
                        class="mx-auto max-w-prose text-base leading-6 text-muted-foreground md:mx-0"
                    >
                        {{ description }}
                    </p>
                </div>
                <div
                    v-if="$slots.body"
                    class="mx-auto w-full max-w-md text-start md:mx-0"
                >
                    <slot name="body" />
                </div>
                <div
                    v-if="$slots.actions"
                    class="flex flex-col items-stretch gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-center md:justify-start"
                >
                    <slot name="actions" />
                </div>
                <p
                    v-if="$slots.tip"
                    class="inline-flex items-start justify-center gap-1.5 text-sm text-muted-foreground md:justify-start"
                >
                    <Icon
                        name="lightbulb-outline"
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span><slot name="tip" /></span>
                </p>
            </div>
        </CardContent>
    </Card>
</template>
