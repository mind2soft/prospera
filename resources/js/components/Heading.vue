<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    /** Heading level; defaults to the page title (1) or a section (2). */
    level?: 1 | 2 | 3;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'default',
    level: undefined,
});

const tag = computed(
    () => `h${props.level ?? (props.variant === 'small' ? 2 : 1)}`,
);
</script>

<template>
    <header :class="variant === 'small' ? '' : 'space-y-1'">
        <!-- `title-after` (e.g. a help link) sits on the title's line, but
             outside the heading so it stays out of its accessible name. -->
        <div v-if="$slots['title-after']" class="flex items-center gap-1.5">
            <component
                :is="tag"
                :class="
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium'
                        : 'font-display text-3xl tracking-tight'
                "
            >
                {{ title }}
            </component>
            <slot name="title-after" />
        </div>
        <component
            :is="tag"
            v-else
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'font-display text-3xl tracking-tight'
            "
        >
            {{ title }}
        </component>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
