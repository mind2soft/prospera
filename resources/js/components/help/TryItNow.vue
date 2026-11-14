<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Icon from '@/components/Icon.vue';
import Postmark from '@/components/onboarding/Postmark.vue';
import { Button } from '@/components/ui/button';
import type { GuideTopic } from '@/lib/helpGuides';

/**
 * "Your challenge": the guide's one concrete next move, with live progress
 * for the profile owner.
 */
defineProps<{
    topic: GuideTopic;
    text: string;
    cta?: { label: string; href: string } | null;
    /** The step is done: point to the next guide instead. */
    done?: boolean;
    /** Not the profile owner: explain who completes it, no live data. */
    readOnly?: boolean;
    /** The app page for this step (read-only users can still look). */
    pageHref: string;
    next?: { label: string; href: string } | null;
}>();
</script>

<template>
    <section
        class="grid gap-4 rounded-xl bg-brand-container p-6 text-brand-container-foreground"
        aria-labelledby="challenge-title"
    >
        <h2 id="challenge-title" class="text-2xl leading-8 font-semibold">
            {{ $t('help.common.yourChallenge') }}
        </h2>

        <template v-if="readOnly">
            <p class="text-base leading-7">{{ text }}</p>
            <p class="text-sm">{{ $t('help.common.ownerCompletes') }}</p>
            <Button
                variant="outline"
                as-child
                class="w-full sm:w-auto sm:justify-self-start"
            >
                <Link :href="pageHref">{{ $t(`help.${topic}.page`) }}</Link>
            </Button>
        </template>

        <template v-else-if="done">
            <p
                class="inline-flex items-center gap-2 text-base leading-7 font-medium"
            >
                {{ text }}
                <span class="inline-flex items-center gap-1.5">
                    <Postmark size="sm" />
                    {{ $t('help.common.done') }}
                </span>
            </p>
            <Button
                v-if="next"
                as-child
                class="w-full gap-2 sm:w-auto sm:justify-self-start"
            >
                <Link :href="next.href">
                    {{ next.label }}
                    <Icon
                        name="arrow-forward"
                        class="size-4 rtl:rotate-180"
                        aria-hidden="true"
                    />
                </Link>
            </Button>
        </template>

        <template v-else>
            <div
                class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end"
            >
                <div class="grid gap-3">
                    <p class="text-base leading-7">{{ text }}</p>
                    <slot />
                </div>
                <Button
                    v-if="cta"
                    as-child
                    size="lg"
                    class="w-full gap-2 md:w-auto"
                >
                    <Link :href="cta.href">
                        {{ cta.label }}
                        <Icon
                            name="arrow-forward"
                            class="size-4 rtl:rotate-180"
                            aria-hidden="true"
                        />
                    </Link>
                </Button>
            </div>
        </template>
    </section>
</template>
