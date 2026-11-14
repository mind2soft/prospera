<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { type Component, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import GuideHero from '@/components/help/GuideHero.vue';
import GuidePager from '@/components/help/GuidePager.vue';
import GuideTldr from '@/components/help/GuideTldr.vue';
import BudgetsGuide from '@/components/help/guides/BudgetsGuide.vue';
import EnvelopesGuide from '@/components/help/guides/EnvelopesGuide.vue';
import LedgersGuide from '@/components/help/guides/LedgersGuide.vue';
import TransactionsGuide from '@/components/help/guides/TransactionsGuide.vue';
import Icon from '@/components/Icon.vue';
import { useOnboardingJourney } from '@/composables/useOnboardingJourney';
import { type GuideTopic, guideFor } from '@/lib/helpGuides';
import type { MaterialSymbol } from '@/lib/materialSymbols';
import { REQUIRED_EXPENSE, REQUIRED_REVENUE } from '@/lib/onboardingJourney';

/** The field-guide shell: hero, TL;DR, the guide itself, prev/next. */
const props = defineProps<{ topic: GuideTopic }>();

const { t } = useI18n();
const { journey, state } = useOnboardingJourney();

const GUIDE_COMPONENTS: Record<GuideTopic, Component> = {
    envelopes: EnvelopesGuide,
    ledgers: LedgersGuide,
    transactions: TransactionsGuide,
    budgets: BudgetsGuide,
};

const stageStatus = computed(
    () =>
        journey.value?.stages.find(
            (stage) => stage.id === guideFor(props.topic).stage,
        )?.status ?? null,
);

/** Owner-only live status; omitted while loading or on error. */
const badge = computed<{ text: string; icon?: MaterialSymbol } | null>(() => {
    const j = journey.value;
    if (j === null) {
        return null;
    }
    if (stageStatus.value === 'done') {
        return { text: t('help.common.done'), icon: 'check' };
    }
    switch (props.topic) {
        case 'envelopes':
            return {
                text: t('help.envelopes.badge', {
                    have:
                        Math.min(j.envelopes.revenue, REQUIRED_REVENUE) +
                        Math.min(j.envelopes.expense, REQUIRED_EXPENSE),
                    need: REQUIRED_REVENUE + REQUIRED_EXPENSE,
                }),
            };
        case 'ledgers':
            return j.envelopes.ready
                ? { text: t('help.ledgers.badgeReady') }
                : {
                      text: t('help.common.unlocksAfter', { step: 1 }),
                      icon: 'lock',
                  };
        case 'transactions':
        case 'budgets':
            if (j.challenge.ready || j.canCreateBudget) {
                return {
                    text: t('help.common.readyToUnlock'),
                    icon: 'check',
                };
            }
            return j.challenge.started
                ? {
                      text: t('help.common.dayOf', {
                          day: j.challenge.day,
                          total: j.challenge.total,
                      }),
                  }
                : { text: t('help.common.notStarted') };
    }
    return null;
});
</script>

<template>
    <article
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 p-4 py-6 sm:p-8"
    >
        <Link
            href="/onboarding"
            class="inline-flex min-h-11 items-center gap-2 self-start rounded-sm text-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
        >
            <Icon
                name="arrow-back"
                class="size-4 rtl:rotate-180"
                aria-hidden="true"
            />
            {{ $t('help.common.backToStart') }}
        </Link>

        <GuideHero :topic="topic" :badge="badge" />

        <div class="mx-auto flex w-full max-w-3xl flex-col gap-10">
            <GuideTldr :topic="topic" />
            <component
                :is="GUIDE_COMPONENTS[topic]"
                :journey="journey"
                :read-only="state === 'readOnly'"
            />
            <GuidePager :topic="topic" />
        </div>
    </article>
</template>
