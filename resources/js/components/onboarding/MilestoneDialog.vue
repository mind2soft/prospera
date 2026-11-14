<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import JourneyMiniRoute from '@/components/onboarding/JourneyMiniRoute.vue';
import Postmark from '@/components/onboarding/Postmark.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { MaterialSymbol } from '@/lib/materialSymbols';
import type { Journey } from '@/lib/onboardingJourney';

/**
 * A one-time milestone (docs/onboarding.md — milestones): a pressed
 * postmark, one sentence, and the next move as the primary action.
 */
defineProps<{
    icon: MaterialSymbol;
    title: string;
    description: string;
    secondaryLabel: string;
    /** Secondary action as a link; otherwise it just closes. */
    secondaryHref?: string;
    primaryLabel: string;
    /** Primary action as a link; otherwise it emits `primary`. */
    primaryHref?: string;
    journey?: Journey | null;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ primary: [] }>();

const primaryRef = ref<{ $el: HTMLElement } | null>(null);

const focusPrimary = async (event: Event): Promise<void> => {
    event.preventDefault();
    await nextTick();
    const el = primaryRef.value?.$el;
    (el?.matches('a, button')
        ? el
        : el?.querySelector<HTMLElement>('a, button')
    )?.focus();
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" @open-auto-focus="focusPrimary">
            <DialogHeader class="items-center gap-3 text-center sm:text-center">
                <Postmark :icon="icon" size="lg" animate class="my-2" />
                <DialogTitle
                    class="font-display text-3xl leading-9 font-normal tracking-tight"
                >
                    {{ title }}
                </DialogTitle>
                <DialogDescription class="text-base leading-6">
                    {{ description }}
                </DialogDescription>
                <JourneyMiniRoute
                    v-if="journey"
                    :journey="journey"
                    :interactive="false"
                    class="mt-1"
                />
            </DialogHeader>
            <DialogFooter class="gap-2 pt-2">
                <Button v-if="secondaryHref" variant="outline" as-child>
                    <Link :href="secondaryHref" @click="open = false">{{
                        secondaryLabel
                    }}</Link>
                </Button>
                <Button v-else variant="outline" @click="open = false">
                    {{ secondaryLabel }}
                </Button>
                <Button
                    v-if="primaryHref"
                    ref="primaryRef"
                    as-child
                    class="gap-2"
                >
                    <Link :href="primaryHref" @click="open = false">
                        {{ primaryLabel }}
                    </Link>
                </Button>
                <Button
                    v-else
                    ref="primaryRef"
                    class="gap-2"
                    @click="emit('primary')"
                >
                    {{ primaryLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
