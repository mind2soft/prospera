<script setup lang="ts">
defineProps<{
    id: string;
    title: string;
    caption?: string;
}>();
</script>

<template>
    <section
        class="grid scroll-mt-20 gap-4"
        :id="id"
        :aria-labelledby="`${id}-title`"
    >
        <h2 :id="`${id}-title`" class="text-xl leading-7 font-semibold">
            {{ title }}
        </h2>
        <div v-if="$slots.default" class="max-w-prose text-base leading-7">
            <slot />
        </div>
        <figure v-if="$slots.figure" class="grid gap-3">
            <slot name="figure" />
            <figcaption v-if="caption" class="text-sm text-muted-foreground">
                {{ caption }}
            </figcaption>
        </figure>
    </section>
</template>
