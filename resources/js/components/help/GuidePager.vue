<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import {
    GUIDE_TOPICS,
    type GuideTopic,
    guideFor,
    guideIndex,
} from '@/lib/helpGuides';

const props = defineProps<{ topic: GuideTopic }>();

const previous = computed(
    () => GUIDE_TOPICS[guideIndex(props.topic) - 1] ?? null,
);
const next = computed(() => GUIDE_TOPICS[guideIndex(props.topic) + 1] ?? null);
const related = computed(() => guideFor(props.topic).related);

const cardClass =
    'flex min-h-16 flex-col justify-center gap-0.5 rounded-lg border bg-card p-4 outline-none transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';
</script>

<template>
    <nav class="grid gap-4" :aria-label="$t('help.common.pager')">
        <div class="grid gap-3 sm:grid-cols-2">
            <Link
                :href="previous ? `/help/${previous}` : '/onboarding'"
                :class="cardClass"
            >
                <span
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                >
                    <Icon
                        name="arrow-back"
                        class="size-3.5 rtl:rotate-180"
                        aria-hidden="true"
                    />
                    {{ $t('help.common.previous') }}
                </span>
                <span class="text-sm font-medium">{{
                    previous
                        ? $t(`help.${previous}.title`)
                        : $t('help.common.gettingStarted')
                }}</span>
            </Link>
            <Link
                :href="next ? `/help/${next}` : '/onboarding'"
                :class="cardClass"
                class="sm:text-end"
            >
                <span
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground sm:justify-end"
                >
                    {{ $t('help.common.next') }}
                    <Icon
                        name="arrow-forward"
                        class="size-3.5 rtl:rotate-180"
                        aria-hidden="true"
                    />
                </span>
                <span class="text-sm font-medium">{{
                    next
                        ? $t(`help.${next}.title`)
                        : $t('help.common.backToStart')
                }}</span>
            </Link>
        </div>
        <p class="text-sm text-muted-foreground">
            {{ $t('help.common.related') }}
            <Link
                :href="`/help/${related}`"
                class="font-medium text-foreground underline decoration-brand/60 underline-offset-4 hover:decoration-brand"
                >{{
                    $t(`help.${related}.guideName`)
                }}</Link
            >
            ·
            <Link
                href="/onboarding"
                class="font-medium text-foreground underline decoration-brand/60 underline-offset-4 hover:decoration-brand"
                >{{
                    $t('help.common.gettingStarted')
                }}</Link
            >
        </p>
    </nav>
</template>
