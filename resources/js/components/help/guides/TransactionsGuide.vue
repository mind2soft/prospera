<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DoDont from '@/components/help/DoDont.vue';
import ExampleTransactions from '@/components/help/ExampleTransactions.vue';
import FlowSteps from '@/components/help/FlowSteps.vue';
import GuideSection from '@/components/help/GuideSection.vue';
import TryItNow from '@/components/help/TryItNow.vue';
import TransactionFlowIllustration from '@/components/onboarding/illustrations/TransactionFlowIllustration.vue';
import ThirtyDayStrip from '@/components/onboarding/ThirtyDayStrip.vue';
import type { Journey } from '@/lib/onboardingJourney';

const props = defineProps<{ journey: Journey | null; readOnly: boolean }>();

const { t } = useI18n();

const keys = ['a', 'b', 'c'] as const;
const linkClass =
    'font-medium underline decoration-brand/60 underline-offset-4 hover:decoration-brand';

const challenge = computed(() => props.journey?.challenge ?? null);
const done = computed(
    () =>
        props.journey?.stages.find((stage) => stage.id === 'challenge')
            ?.status === 'done',
);

const text = computed(() => {
    const c = challenge.value;
    if (done.value) {
        return t('help.transactions.challenge.done');
    }
    if (c === null || !c.started) {
        return t('help.transactions.challenge.first');
    }
    return c.missing > 0
        ? t(
              'help.transactions.challenge.progress',
              { day: c.day, n: c.missing },
              c.missing,
          )
        : t('help.transactions.challenge.allCovered', { day: c.day });
});

const cta = computed(() =>
    props.journey !== null && !props.journey.hasLedger
        ? {
              label: t('help.transactions.challenge.noLedger'),
              href: '/ledger?create=1',
          }
        : {
              label: t('help.transactions.challenge.cta'),
              href: '/ledger#add-transaction',
          },
);
</script>

<template>
    <GuideSection
        id="flow"
        :title="$t('help.transactions.sections.flow.title')"
        :caption="$t('help.transactions.sections.flow.caption')"
    >
        <p>{{ $t('help.transactions.sections.flow.body') }}</p>
        <template #figure>
            <div class="rounded-xl border bg-card p-6">
                <div class="mx-auto max-w-md">
                    <TransactionFlowIllustration />
                </div>
            </div>
        </template>
    </GuideSection>

    <GuideSection
        id="record"
        :title="$t('help.transactions.sections.record.title')"
    >
        <i18n-t keypath="help.transactions.sections.record.body" tag="p">
            <template #page>
                <Link href="/ledger" :class="linkClass">{{
                    $t('help.transactions.page')
                }}</Link>
            </template>
        </i18n-t>
        <template #figure>
            <FlowSteps
                :steps="
                    keys.map((k) =>
                        t(`help.transactions.sections.record.steps.${k}`),
                    )
                "
            />
            <ExampleTransactions />
        </template>
    </GuideSection>

    <GuideSection
        id="habit"
        :title="$t('help.transactions.sections.habit.title')"
        :caption="$t('help.transactions.sections.habit.caption')"
    >
        <p>{{ $t('help.transactions.sections.habit.body') }}</p>
        <template #figure>
            <div class="rounded-xl border bg-card p-5">
                <ThirtyDayStrip :days="11" started demo />
            </div>
        </template>
    </GuideSection>

    <DoDont
        :dos="keys.map((k) => t(`help.transactions.dos.${k}`))"
        :avoids="keys.map((k) => t(`help.transactions.avoids.${k}`))"
    />

    <TryItNow
        topic="transactions"
        :text="text"
        :done="done"
        :read-only="readOnly"
        page-href="/ledger"
        :cta="cta"
        :next="{
            label: $t('help.common.nextGuide', {
                title: $t('help.budgets.title'),
            }),
            href: '/help/budgets',
        }"
    >
        <ThirtyDayStrip
            v-if="challenge"
            :days="challenge.days"
            :started="challenge.started"
        />
    </TryItNow>
</template>
