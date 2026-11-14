<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import OnboardingIllustration from '@/components/onboarding/OnboardingIllustration.vue';
import { Badge } from '@/components/ui/badge';
import {
    GUIDE_ILLUSTRATIONS,
    type GuideTopic,
    guideFor,
    guideIndex,
} from '@/lib/helpGuides';
import type { MaterialSymbol } from '@/lib/materialSymbols';

defineProps<{
    topic: GuideTopic;
    /** The owner's live status for this step; omitted while loading. */
    badge?: { text: string; icon?: MaterialSymbol } | null;
}>();
</script>

<template>
    <header
        class="grid items-center gap-6 rounded-xl bg-brand-container p-6 text-brand-container-foreground lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] lg:gap-10 lg:p-10"
    >
        <div class="grid gap-3">
            <p class="text-sm">
                {{ $t('help.common.guideOf', { n: guideIndex(topic) + 1 }) }}
                · {{ $t(`help.${topic}.name`) }}
            </p>
            <h1
                class="font-display text-3xl leading-9 tracking-tight text-balance lg:text-[2.5rem] lg:leading-[3rem]"
            >
                {{ $t(`help.${topic}.title`) }}
            </h1>
            <p class="max-w-prose text-base leading-7">
                {{ $t(`help.${topic}.promise`) }}
            </p>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="inline-flex items-center gap-1.5">
                    <Icon name="schedule" class="size-4" aria-hidden="true" />
                    {{
                        $t(
                            'help.common.readingTime',
                            { n: guideFor(topic).minutes },
                            guideFor(topic).minutes,
                        )
                    }}
                </span>
                <template v-if="badge">
                    <span aria-hidden="true">·</span>
                    <Badge variant="secondary" class="gap-1 text-sm">
                        <Icon
                            v-if="badge.icon"
                            :name="badge.icon"
                            aria-hidden="true"
                        />
                        <span class="sr-only">{{
                            $t('help.common.progressLabel')
                        }}</span>
                        {{ badge.text }}
                    </Badge>
                </template>
            </div>
        </div>
        <div class="mx-auto w-full max-w-64 lg:max-w-sm" aria-hidden="true">
            <OnboardingIllustration :name="GUIDE_ILLUSTRATIONS[topic]" />
        </div>
    </header>
</template>
