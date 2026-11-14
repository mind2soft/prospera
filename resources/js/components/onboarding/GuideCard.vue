<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Icon from '@/components/Icon.vue';
import OnboardingIllustration from '@/components/onboarding/OnboardingIllustration.vue';
import Postmark from '@/components/onboarding/Postmark.vue';
import { GUIDE_ILLUSTRATIONS, type Guide, guideIndex } from '@/lib/helpGuides';

defineProps<{
    guide: Guide;
    /** The matching setup stage is done. */
    done?: boolean;
}>();
</script>

<template>
    <Link
        :href="`/help/${guide.topic}`"
        class="group flex min-h-18 items-center gap-4 px-4 py-3 transition-colors outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
    >
        <span
            class="grid h-10 w-16 shrink-0 place-items-center overflow-hidden rounded-md bg-brand-container px-1.5"
            aria-hidden="true"
        >
            <OnboardingIllustration :name="GUIDE_ILLUSTRATIONS[guide.topic]" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-xs text-muted-foreground">{{
                $t('onboarding.guides.guideN', {
                    n: guideIndex(guide.topic) + 1,
                })
            }}</span>
            <span class="block text-base leading-6 font-medium">{{
                $t(`help.${guide.topic}.title`)
            }}</span>
            <span
                class="line-clamp-2 text-sm text-muted-foreground sm:line-clamp-1"
                >{{
                    $t(`help.${guide.topic}.promise`)
                }}</span
            >
        </span>
        <span
            class="flex shrink-0 items-center gap-2 text-sm text-muted-foreground tabular-nums"
        >
            {{ $t('onboarding.guides.minutes', { n: guide.minutes }) }}
            <template v-if="done">
                <Postmark size="sm" />
                <span class="sr-only">{{
                    $t('onboarding.guides.completed')
                }}</span>
            </template>
            <Icon
                v-else
                name="arrow-forward"
                class="size-4 transition-transform group-hover:translate-x-0.5 rtl:rotate-180"
                aria-hidden="true"
            />
        </span>
    </Link>
</template>
