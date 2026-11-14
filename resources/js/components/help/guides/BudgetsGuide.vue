<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BudgetVsActualExample from '@/components/help/BudgetVsActualExample.vue';
import DoDont from '@/components/help/DoDont.vue';
import FlowSteps from '@/components/help/FlowSteps.vue';
import GuideSection from '@/components/help/GuideSection.vue';
import TryItNow from '@/components/help/TryItNow.vue';
import Icon from '@/components/Icon.vue';
import BudgetDialIllustration from '@/components/onboarding/illustrations/BudgetDialIllustration.vue';
import RequirementList from '@/components/onboarding/RequirementList.vue';
import { useLocale } from '@/composables/useLocale';
import type { Journey } from '@/lib/onboardingJourney';

const props = defineProps<{ journey: Journey | null; readOnly: boolean }>();

const { t } = useI18n();
const { locale } = useLocale();

const keys = ['a', 'b', 'c'] as const;
const linkClass =
    'font-medium underline decoration-brand/60 underline-offset-4 hover:decoration-brand';

const done = computed(() => props.journey?.complete === true);
const ready = computed(() => props.journey?.canCreateBudget === true);

const requirements = computed(() => {
    const c = props.journey?.challenge;
    return [
        {
            label: t('onboarding.requirements.history'),
            value: c
                ? t('onboarding.requirements.historyValue', { days: c.days })
                : undefined,
            done: c ? c.days >= c.total : null,
        },
        {
            label: t('onboarding.requirements.envelopes'),
            value: c
                ? t(
                      'onboarding.requirements.envelopesValue',
                      { covered: c.covered, n: c.totalEnvelopes },
                      c.totalEnvelopes,
                  )
                : undefined,
            done: c ? c.missing === 0 && c.totalEnvelopes > 0 : null,
        },
    ];
});

const unlockDate = computed(() => {
    const date = props.journey?.challenge.unlockDate;
    return date
        ? new Intl.DateTimeFormat(locale.value, {
              month: 'long',
              day: 'numeric',
          }).format(date)
        : null;
});

const challenge = computed(() => {
    if (done.value) {
        return {
            text: t('help.budgets.challenge.done'),
            cta: {
                label: t('help.budgets.challenge.openCta'),
                href: '/budget',
            },
        };
    }
    if (ready.value) {
        return {
            text: t('help.budgets.challenge.ready'),
            cta: {
                label: t('help.budgets.challenge.createCta'),
                href: '/budget?create=1',
            },
        };
    }
    const c = props.journey?.challenge;
    return {
        text: unlockDate.value
            ? t('help.budgets.challenge.notReady', { date: unlockDate.value })
            : c?.started
              ? t('help.budgets.challenge.waitingEnvelopes')
              : t('help.budgets.challenge.notStarted'),
        cta: {
            label: t('help.budgets.challenge.recordCta'),
            href: '/ledger#add-transaction',
        },
    };
});
</script>

<template>
    <GuideSection
        id="plan"
        :title="$t('help.budgets.sections.plan.title')"
        :caption="$t('help.budgets.sections.plan.caption')"
    >
        <p>{{ $t('help.budgets.sections.plan.body') }}</p>
        <template #figure>
            <div
                class="grid items-center gap-6 rounded-xl border bg-card p-5 sm:grid-cols-[10rem_minmax(0,1fr)]"
            >
                <div class="mx-auto w-full max-w-40">
                    <BudgetDialIllustration />
                </div>
                <BudgetVsActualExample bare />
            </div>
        </template>
    </GuideSection>

    <GuideSection id="draft" :title="$t('help.budgets.sections.draft.title')">
        <i18n-t keypath="help.budgets.sections.draft.body" tag="p">
            <template #page>
                <Link href="/budget" :class="linkClass">{{
                    $t('help.budgets.page')
                }}</Link>
            </template>
        </i18n-t>
        <template #figure>
            <FlowSteps
                :steps="
                    keys.map((k) => t(`help.budgets.sections.draft.steps.${k}`))
                "
            />
            <ul class="grid gap-2 text-base leading-6">
                <li v-for="k in keys" :key="k" class="flex gap-2">
                    <Icon
                        name="check"
                        class="mt-1 size-4 shrink-0 text-success"
                        aria-hidden="true"
                    />
                    {{ $t(`help.budgets.sections.draft.points.${k}`) }}
                </li>
            </ul>
        </template>
    </GuideSection>

    <GuideSection id="tune" :title="$t('help.budgets.sections.tune.title')">
        <template #figure>
            <DoDont
                :dos="keys.map((k) => t(`help.budgets.dos.${k}`))"
                :avoids="keys.map((k) => t(`help.budgets.avoids.${k}`))"
            />
        </template>
    </GuideSection>

    <GuideSection id="unlock" :title="$t('help.budgets.sections.unlock.title')">
        <p>{{ $t('help.budgets.sections.unlock.body') }}</p>
        <template #figure>
            <div class="rounded-xl border bg-card p-5">
                <RequirementList :items="requirements" />
            </div>
        </template>
    </GuideSection>

    <TryItNow
        topic="budgets"
        :text="challenge.text"
        :done="false"
        :read-only="readOnly"
        page-href="/budget"
        :cta="challenge.cta"
    />
</template>
