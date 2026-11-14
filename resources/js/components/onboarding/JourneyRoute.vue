<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/Icon.vue';
import EnvelopeSlotMeter from '@/components/onboarding/EnvelopeSlotMeter.vue';
import Postmark from '@/components/onboarding/Postmark.vue';
import RequirementList from '@/components/onboarding/RequirementList.vue';
import StageMedallion from '@/components/onboarding/StageMedallion.vue';
import ThirtyDayStrip from '@/components/onboarding/ThirtyDayStrip.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import { GUIDES } from '@/lib/helpGuides';
import {
    type Journey,
    STAGE_IDS,
    type StageId,
    type StageStatus,
    stageHref,
} from '@/lib/onboardingJourney';

/**
 * The four-stage route (docs/onboarding.md). `full` on the getting-started
 * page, `compact` on the dashboard; without a journey it is a read-only
 * overview of how setup works.
 */
const props = withDefaults(
    defineProps<{
        journey: Journey | null;
        variant?: 'full' | 'compact';
    }>(),
    { variant: 'full' },
);

const { t } = useI18n();
const { locale } = useLocale();

type Row = {
    id: StageId;
    step: number;
    status: StageStatus | 'neutral';
};

const rows = computed<Row[]>(() =>
    props.journey
        ? props.journey.stages
        : STAGE_IDS.map((id, index) => ({
              id,
              step: index + 1,
              status: 'neutral' as const,
          })),
);

const guideFor = (id: StageId) =>
    GUIDES.find((guide) => guide.stage === id) ?? GUIDES[0];

const statusText = (row: Row): string => {
    switch (row.status) {
        case 'done':
            return t('onboarding.route.done');
        case 'current':
            return t('onboarding.route.current');
        case 'locked':
            return t('onboarding.route.unlocksAfter', { step: row.step - 1 });
        default:
            return t('onboarding.route.readOnlyStatus');
    }
};

const unlockDate = computed(() => {
    const date = props.journey?.challenge.unlockDate;
    return date
        ? new Intl.DateTimeFormat(locale.value, {
              month: 'long',
              day: 'numeric',
          }).format(date)
        : null;
});

const connectorClass = (row: Row): string =>
    row.status === 'done'
        ? 'border-brand'
        : 'border-dashed border-muted-foreground/40';

const requirements = computed(() => {
    const c = props.journey?.challenge;
    if (!c) {
        return [];
    }
    return [
        {
            label: t('onboarding.requirements.history'),
            value: t('onboarding.requirements.historyValue', { days: c.days }),
            done: c.days >= c.total,
        },
        {
            label: t('onboarding.requirements.envelopes'),
            value: t(
                'onboarding.requirements.envelopesValue',
                { covered: c.covered, n: c.totalEnvelopes },
                c.totalEnvelopes,
            ),
            done: c.missing === 0 && c.totalEnvelopes > 0,
        },
    ];
});
</script>

<template>
    <ol
        class="grid gap-y-0 lg:gap-x-4"
        :class="
            variant === 'full' ? 'lg:grid-cols-4' : 'xl:grid-cols-4 xl:gap-x-4'
        "
    >
        <li
            v-for="(row, index) in rows"
            :key="row.id"
            class="flex gap-4"
            :class="variant === 'full' ? 'lg:flex-col' : 'xl:flex-col'"
            :aria-current="row.status === 'current' ? 'step' : undefined"
        >
            <!-- Medallion + connector: vertical on narrow screens, horizontal on wide. -->
            <div
                class="flex flex-col items-center"
                :class="variant === 'full' ? 'lg:flex-row' : 'xl:flex-row'"
                aria-hidden="true"
            >
                <StageMedallion :status="row.status" :step="row.step" />
                <span
                    v-if="index < rows.length - 1"
                    class="my-1 min-h-6 w-0 flex-1 border-s-2"
                    :class="[
                        connectorClass(row),
                        variant === 'full'
                            ? 'lg:my-0 lg:ms-3 lg:-me-4 lg:min-h-0 lg:w-auto lg:border-s-0 lg:border-t-2'
                            : 'xl:my-0 xl:ms-3 xl:-me-4 xl:min-h-0 xl:w-auto xl:border-s-0 xl:border-t-2',
                    ]"
                />
            </div>

            <div
                class="mb-6 min-w-0 flex-1 rounded-xl"
                :class="[
                    row.status === 'current'
                        ? 'bg-brand-container p-4 text-brand-container-foreground'
                        : variant === 'full'
                          ? 'pt-2 lg:border lg:p-4'
                          : 'pt-2',
                ]"
            >
                <h3 class="text-base leading-6 font-medium text-foreground">
                    {{ $t(`onboarding.route.stages.${row.id}.title`) }}
                    <span class="sr-only"> — {{ statusText(row) }}</span>
                </h3>

                <p
                    class="mt-1 text-sm"
                    :class="[
                        row.status === 'current' ? '' : 'text-muted-foreground',
                        row.status === 'current' || row.status === 'neutral'
                            ? ''
                            : variant === 'full'
                              ? 'hidden lg:block'
                              : 'hidden',
                    ]"
                >
                    {{ $t(`onboarding.route.stages.${row.id}.why`) }}
                </p>

                <!-- Status line -->
                <p
                    v-if="row.status !== 'current'"
                    class="mt-2 inline-flex items-center gap-1.5 text-sm"
                    :class="
                        row.status === 'done'
                            ? 'font-medium text-success'
                            : 'text-muted-foreground'
                    "
                    aria-hidden="true"
                >
                    <Postmark v-if="row.status === 'done'" size="sm" />
                    <Icon
                        v-else-if="row.status === 'locked'"
                        name="lock"
                        class="size-4"
                    />
                    {{ statusText(row) }}
                </p>

                <template v-if="row.status === 'neutral'">
                    <p class="mt-2 text-sm">
                        <span class="font-medium">{{
                            $t('onboarding.route.goal')
                        }}</span>
                        · {{ $t(`onboarding.route.stages.${row.id}.goal`) }}
                    </p>
                </template>

                <!-- The current stage: goal, live progress and the next move. -->
                <div
                    v-if="row.status === 'current' && journey"
                    class="mt-3 grid gap-3"
                >
                    <p class="text-sm">
                        <span class="font-medium">{{
                            $t('onboarding.route.goal')
                        }}</span>
                        · {{ $t(`onboarding.route.stages.${row.id}.goal`) }}
                    </p>

                    <EnvelopeSlotMeter
                        v-if="row.id === 'envelopes'"
                        :revenue="journey.envelopes.revenue"
                        :expense="journey.envelopes.expense"
                    />

                    <p v-else-if="row.id === 'ledger'" class="text-sm">
                        {{ $t('onboarding.route.notOpened') }}
                    </p>

                    <template v-else-if="row.id === 'challenge'">
                        <ThirtyDayStrip
                            :days="journey.challenge.days"
                            :started="journey.challenge.started"
                        />
                        <p v-if="journey.challenge.started" class="text-sm">
                            {{
                                $t(
                                    'onboarding.route.covered',
                                    {
                                        covered: journey.challenge.covered,
                                        n: journey.challenge.totalEnvelopes,
                                    },
                                    journey.challenge.totalEnvelopes,
                                )
                            }}
                        </p>
                        <p v-if="unlockDate" class="text-sm">
                            {{
                                $t('onboarding.route.unlocksAround', {
                                    date: unlockDate,
                                })
                            }}
                        </p>
                    </template>

                    <RequirementList
                        v-else-if="row.id === 'budget'"
                        :items="requirements"
                    />

                    <Button
                        as-child
                        class="w-full gap-2 sm:w-auto sm:justify-self-start"
                    >
                        <Link :href="stageHref(row.id, journey)">
                            {{ $t(`onboarding.route.stages.${row.id}.cta`) }}
                            <Icon
                                name="arrow-forward"
                                class="size-4 rtl:rotate-180"
                                aria-hidden="true"
                            />
                        </Link>
                    </Button>
                </div>

                <Link
                    v-if="variant === 'full'"
                    :href="`/help/${guideFor(row.id).topic}`"
                    class="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-sm text-sm font-medium text-foreground underline decoration-brand/60 underline-offset-4 outline-none hover:decoration-brand focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                >
                    <Icon name="menu-book" class="size-4" aria-hidden="true" />
                    {{
                        $t('onboarding.route.guideLink', {
                            guide: $t(
                                `help.${guideFor(row.id).topic}.guideName`,
                            ),
                            minutes: guideFor(row.id).minutes,
                        })
                    }}
                </Link>
            </div>
        </li>
    </ol>
</template>
