<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/Icon.vue';
import EnvelopeSlotMeter from '@/components/onboarding/EnvelopeSlotMeter.vue';
import JourneyMiniRoute from '@/components/onboarding/JourneyMiniRoute.vue';
import StarterEnvelopeChips from '@/components/onboarding/StarterEnvelopeChips.vue';
import ThirtyDayStrip from '@/components/onboarding/ThirtyDayStrip.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLocale } from '@/composables/useLocale';
import { useLocalJson } from '@/composables/useLocalJson';
import { useOnboardingJourney } from '@/composables/useOnboardingJourney';
import { usePledge } from '@/composables/usePledge';
import { focusAnchor } from '@/lib/focusAnchor';
import type { StarterDirection } from '@/lib/helpGuides';
import { type StageId, stageHref } from '@/lib/onboardingJourney';

/**
 * The setup journey, under the page header of Envelopes, Ledger and Budget
 * (docs/onboarding.md — journey bar). Owners only; it disappears once the
 * route is complete. Counts and days only — never money.
 */
const props = withDefaults(
    defineProps<{
        page: 'envelopes' | 'ledger' | 'budget';
        /** A teaching empty state already explains the step: stay collapsed. */
        suppressBody?: boolean;
        /** Envelope names the owner already uses (starter chips). */
        existingNames?: string[];
    }>(),
    { suppressBody: false, existingNames: () => [] },
);

const emit = defineEmits<{
    pick: [suggestion: { name: string; direction: StarterDirection }];
}>();

const { t } = useI18n();
const { locale } = useLocale();
const { profileId } = useCurrentProfile();
const { journey, isOwner, isPending, isError, isFetching, refetch } =
    useOnboardingJourney();
const { has: pledged } = usePledge();

const bodyId = useId();

const stored = useLocalJson<boolean>(() =>
    profileId.value === null
        ? null
        : `prospera.onboarding.journey-collapsed.${profileId.value}`,
);

const PAGE_STAGES: Record<typeof props.page, StageId[]> = {
    envelopes: ['envelopes'],
    ledger: ['challenge', 'budget'],
    budget: [],
};

const current = computed(() => journey.value?.current ?? null);
const ownsStage = computed(
    () =>
        current.value !== null &&
        PAGE_STAGES[props.page].includes(current.value),
);

const collapsed = computed(() => {
    if (props.suppressBody) {
        return true;
    }
    return stored.value.value ?? !ownsStage.value;
});

const toggle = (): void => {
    stored.set(!collapsed.value);
};

const visible = computed(
    () => isOwner.value && journey.value?.complete !== true,
);

const stageTitle = (id: StageId): string =>
    t(`onboarding.route.stages.${id}.title`);

const status = computed(() => {
    const j = journey.value;
    if (j === null || j.current === null) {
        return '';
    }
    const base = t('onboarding.journey.status', {
        step: j.stepNumber,
        title: stageTitle(j.current),
    });
    return j.current === 'challenge' && j.challenge.started
        ? `${base} · ${t('onboarding.route.dayOf', { day: j.challenge.day, total: j.challenge.total })}`
        : base;
});

const unlockDate = computed(() => {
    const date = journey.value?.challenge.unlockDate;
    return date
        ? new Intl.DateTimeFormat(locale.value, {
              month: 'long',
              day: 'numeric',
          }).format(date)
        : null;
});

const nextHref = computed(() =>
    journey.value && current.value
        ? stageHref(current.value, journey.value)
        : '',
);

const otherStageMessage = computed(() => {
    const id = current.value;
    if (id === null) {
        return '';
    }
    if (props.page === 'envelopes' && id !== 'envelopes') {
        return t('onboarding.journey.envelopesDone', { stage: stageTitle(id) });
    }
    if (id === 'envelopes' || id === 'ledger') {
        return t(`onboarding.route.stages.${id}.why`);
    }
    return t('onboarding.journey.nextStep', { stage: stageTitle(id) });
});

const addTransaction = (): void => {
    focusAnchor('add-transaction');
};
</script>

<template>
    <template v-if="visible">
        <Skeleton
            v-if="isPending"
            class="w-full rounded-xl"
            :class="collapsed ? 'h-12' : 'h-30'"
        />

        <div
            v-else-if="isError"
            class="flex min-h-12 flex-wrap items-center gap-2 rounded-xl border bg-card px-4 py-2 text-sm"
            role="alert"
        >
            <Icon
                name="error"
                class="size-5 text-destructive"
                aria-hidden="true"
            />
            <span>{{ $t('onboarding.journey.error') }}</span>
            <Button
                variant="ghost"
                size="sm"
                :disabled="isFetching"
                @click="refetch()"
            >
                {{ $t('common.actions.retry') }}
            </Button>
        </div>

        <section
            v-else-if="journey"
            class="rounded-xl border bg-card px-4 sm:px-5"
            :class="collapsed ? 'py-1' : 'py-4 sm:py-5'"
            :aria-label="$t('onboarding.journey.label')"
            data-test="setup-journey-bar"
        >
            <div class="flex min-h-10 items-center gap-3">
                <JourneyMiniRoute :journey="journey" />
                <p
                    class="min-w-0 flex-1 truncate text-sm font-medium"
                    aria-live="polite"
                >
                    <span class="sm:hidden">{{
                        $t('onboarding.journey.statusShort', {
                            step: journey.stepNumber,
                        })
                    }}</span>
                    <span class="hidden sm:inline">{{ status }}</span>
                </p>
                <Button
                    variant="ghost"
                    size="sm"
                    as-child
                    class="shrink-0 text-muted-foreground hover:text-foreground"
                >
                    <Link
                        href="/onboarding"
                        :aria-label="$t('onboarding.journey.gettingStarted')"
                    >
                        <Icon
                            name="menu-book"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <span class="hidden md:inline">{{
                            $t('onboarding.journey.gettingStarted')
                        }}</span>
                    </Link>
                </Button>
                <Button
                    v-if="!suppressBody"
                    variant="ghost"
                    size="icon"
                    class="shrink-0"
                    :aria-expanded="!collapsed"
                    :aria-controls="bodyId"
                    :aria-label="
                        collapsed
                            ? $t('onboarding.journey.expand')
                            : $t('onboarding.journey.collapse')
                    "
                    @click="toggle"
                >
                    <Icon
                        :name="collapsed ? 'expand-more' : 'expand-less'"
                        class="size-5"
                        aria-hidden="true"
                    />
                </Button>
            </div>

            <div
                v-if="!suppressBody"
                :id="bodyId"
                class="grid transition-[grid-template-rows] duration-200"
                :class="collapsed ? 'grid-rows-[0fr]' : 'grid-rows-[1fr]'"
                :inert="collapsed"
            >
                <div class="min-h-0 overflow-hidden">
                    <div
                        class="mt-4 flex flex-col gap-4 border-t pt-4 md:flex-row md:items-end md:justify-between"
                    >
                        <!-- Envelopes: fill the slots. -->
                        <div
                            v-if="
                                page === 'envelopes' && current === 'envelopes'
                            "
                            class="grid min-w-0 gap-3"
                        >
                            <EnvelopeSlotMeter
                                :revenue="journey.envelopes.revenue"
                                :expense="journey.envelopes.expense"
                            />
                            <p class="text-sm text-muted-foreground">
                                {{
                                    $t(
                                        'onboarding.slots.moreToUnlock',
                                        { n: journey.envelopes.missing },
                                        journey.envelopes.missing,
                                    )
                                }}
                            </p>
                            <StarterEnvelopeChips
                                mode="emit"
                                :limit="4"
                                :grouped="false"
                                :prefer="
                                    journey.envelopes.missingRevenue > 0
                                        ? 'revenue'
                                        : 'expense'
                                "
                                :existing-names="existingNames"
                                @pick="emit('pick', $event)"
                            />
                        </div>

                        <!-- Ledger: the 30-day challenge, where it happens. -->
                        <div
                            v-else-if="
                                page === 'ledger' && current === 'challenge'
                            "
                            class="grid min-w-0 flex-1 gap-3 md:max-w-xl"
                        >
                            <ThirtyDayStrip
                                :days="journey.challenge.days"
                                :started="journey.challenge.started"
                            />
                            <div
                                class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                            >
                                <span v-if="journey.challenge.day === 1">{{
                                    $t('onboarding.journey.clockRunning')
                                }}</span>
                                <span v-if="journey.challenge.started">{{
                                    $t(
                                        'onboarding.route.covered',
                                        {
                                            covered: journey.challenge.covered,
                                            n: journey.challenge.totalEnvelopes,
                                        },
                                        journey.challenge.totalEnvelopes,
                                    )
                                }}</span>
                                <span
                                    v-if="unlockDate"
                                    class="text-muted-foreground"
                                    >{{
                                        $t('onboarding.route.unlocksAround', {
                                            date: unlockDate,
                                        })
                                    }}</span
                                >
                                <Badge
                                    v-if="journey.challenge.halfway"
                                    variant="secondary"
                                >
                                    <Icon name="flag" aria-hidden="true" />
                                    {{ $t('onboarding.journey.halfway') }}
                                </Badge>
                            </div>
                            <p
                                v-if="pledged('record')"
                                class="inline-flex items-center gap-1.5 text-sm text-muted-foreground"
                            >
                                <Icon
                                    name="draw"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ $t('onboarding.journey.pledgeEcho') }}
                            </p>
                        </div>

                        <!-- Ledger: the budget is unlocked. -->
                        <p
                            v-else-if="
                                page === 'ledger' && current === 'budget'
                            "
                            class="text-sm font-medium"
                        >
                            {{ $t('onboarding.journey.budgetUnlocked') }}
                        </p>

                        <!-- Elsewhere: where to go next. -->
                        <p v-else class="text-sm">
                            {{ otherStageMessage }}
                        </p>

                        <div class="flex shrink-0 flex-wrap gap-2">
                            <slot
                                v-if="
                                    page === 'envelopes' &&
                                    current === 'envelopes'
                                "
                                name="action"
                            />
                            <Button
                                v-else-if="
                                    page === 'ledger' && current === 'challenge'
                                "
                                class="gap-2"
                                @click="addTransaction"
                            >
                                <Icon
                                    name="add"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ $t('onboarding.journey.addTransaction') }}
                            </Button>
                            <Button v-else-if="current" as-child class="gap-2">
                                <Link :href="nextHref">
                                    {{
                                        $t(
                                            `onboarding.route.stages.${current}.cta`,
                                        )
                                    }}
                                    <Icon
                                        name="arrow-forward"
                                        class="size-4 rtl:rotate-180"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </template>
</template>
