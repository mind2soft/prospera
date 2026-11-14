<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import type { GuideTopic } from '@/lib/helpGuides';

defineProps<{ topic: GuideTopic }>();
</script>

<template>
    <section class="rounded-xl border bg-card p-5" aria-labelledby="tldr-title">
        <h2
            id="tldr-title"
            class="inline-flex items-center gap-2 text-base leading-6 font-medium"
        >
            <Icon name="timer-outline" class="size-5" aria-hidden="true" />
            {{ $t('help.common.inThirtySeconds') }}
        </h2>
        <ol class="mt-4 grid gap-4 sm:grid-cols-3">
            <li
                v-for="(item, index) in ['a', 'b', 'c']"
                :key="item"
                class="flex gap-3 text-base leading-6"
            >
                <span
                    class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-container text-xs font-semibold text-brand-container-foreground"
                    aria-hidden="true"
                    >{{
                        index + 1
                    }}</span
                >
                <span>{{ $t(`help.${topic}.tldr.${item}`) }}</span>
            </li>
        </ol>
    </section>
</template>
