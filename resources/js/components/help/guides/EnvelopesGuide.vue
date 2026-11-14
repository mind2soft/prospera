<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EnvelopeChip from '@/components/EnvelopeChip.vue';
import DoDont from '@/components/help/DoDont.vue';
import GuideSection from '@/components/help/GuideSection.vue';
import TryItNow from '@/components/help/TryItNow.vue';
import Icon from '@/components/Icon.vue';
import EnvelopeSlotMeter from '@/components/onboarding/EnvelopeSlotMeter.vue';
import EnvelopeFlowIllustration from '@/components/onboarding/illustrations/EnvelopeFlowIllustration.vue';
import StarterEnvelopeChips from '@/components/onboarding/StarterEnvelopeChips.vue';
import { useEnvelopeOptions } from '@/composables/envelopeQueries';
import { EXAMPLE_COLORS } from '@/lib/helpGuides';
import { type Journey, stageHref } from '@/lib/onboardingJourney';

const props = defineProps<{ journey: Journey | null; readOnly: boolean }>();

const { t } = useI18n();
const envelopesQuery = useEnvelopeOptions();

const ownedNames = computed(() =>
    (envelopesQuery.data.value ?? [])
        .filter((envelope) => envelope.permission === 'owner')
        .map((envelope) => envelope.name),
);

const done = computed(() => props.journey?.envelopes.ready === true);
const keys = ['a', 'b', 'c'] as const;
const linkClass =
    'font-medium underline decoration-brand/60 underline-offset-4 hover:decoration-brand';
</script>

<template>
    <GuideSection
        id="what"
        :title="$t('help.envelopes.sections.what.title')"
        :caption="$t('help.envelopes.sections.what.caption')"
    >
        <p>{{ $t('help.envelopes.sections.what.body') }}</p>
        <template #figure>
            <div class="rounded-xl border bg-card p-6">
                <div class="mx-auto max-w-md">
                    <EnvelopeFlowIllustration />
                </div>
            </div>
        </template>
    </GuideSection>

    <GuideSection
        id="starter"
        :title="$t('help.envelopes.sections.starter.title')"
        :caption="$t('help.envelopes.sections.starter.caption')"
    >
        <p>{{ $t('help.envelopes.sections.starter.body') }}</p>
        <template #figure>
            <div class="grid gap-4 rounded-xl border bg-card p-5">
                <StarterEnvelopeChips
                    :mode="readOnly || journey === null ? 'static' : 'link'"
                    :existing-names="journey ? ownedNames : []"
                />
                <p
                    class="inline-flex items-start gap-1.5 text-sm text-muted-foreground"
                >
                    <Icon
                        name="lightbulb-outline"
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ $t('onboarding.starter.savingsNote') }}
                </p>
            </div>
        </template>
    </GuideSection>

    <GuideSection id="broad" :title="$t('help.envelopes.sections.broad.title')">
        <p>{{ $t('help.envelopes.sections.broad.body') }}</p>
        <template #figure>
            <DoDont
                :dos="keys.map((k) => t(`help.envelopes.dos.${k}`))"
                :avoids="keys.map((k) => t(`help.envelopes.avoids.${k}`))"
            />
        </template>
    </GuideSection>

    <GuideSection
        id="yours"
        :title="$t('help.envelopes.sections.yours.title')"
        :caption="$t('help.envelopes.sections.yours.caption')"
    >
        <i18n-t keypath="help.envelopes.sections.yours.body" tag="p">
            <template #page>
                <Link href="/envelopes" :class="linkClass">{{
                    $t('help.envelopes.page')
                }}</Link>
            </template>
        </i18n-t>
        <template #figure>
            <div class="flex flex-wrap gap-2 rounded-xl border bg-card p-5">
                <EnvelopeChip
                    :name="$t('onboarding.starter.revenue.paycheck')"
                    :color="EXAMPLE_COLORS.paycheck"
                    direction="revenue"
                />
                <EnvelopeChip
                    :name="$t('onboarding.starter.expense.groceries')"
                    :color="EXAMPLE_COLORS.groceries"
                    direction="expense"
                />
                <EnvelopeChip
                    :name="$t('onboarding.starter.expense.gas')"
                    :color="EXAMPLE_COLORS.gas"
                    direction="expense"
                />
            </div>
        </template>
    </GuideSection>

    <TryItNow
        topic="envelopes"
        :text="
            done
                ? $t('help.envelopes.challenge.done')
                : $t('help.envelopes.challenge.text')
        "
        :done="done"
        :read-only="readOnly"
        page-href="/envelopes"
        :cta="{
            label: $t('help.envelopes.challenge.cta'),
            href: journey
                ? stageHref('envelopes', journey)
                : '/envelopes?new=revenue',
        }"
        :next="{
            label: $t('help.common.nextGuide', {
                title: $t('help.ledgers.title'),
            }),
            href: '/help/ledgers',
        }"
    >
        <EnvelopeSlotMeter
            v-if="journey"
            :revenue="journey.envelopes.revenue"
            :expense="journey.envelopes.expense"
        />
    </TryItNow>
</template>
