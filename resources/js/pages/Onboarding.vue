<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/Icon.vue';
import GuideCard from '@/components/onboarding/GuideCard.vue';
import JourneyRoute from '@/components/onboarding/JourneyRoute.vue';
import OnboardingIllustration from '@/components/onboarding/OnboardingIllustration.vue';
import PledgeCard from '@/components/onboarding/PledgeCard.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useOnboardingJourney } from '@/composables/useOnboardingJourney';
import { GUIDES } from '@/lib/helpGuides';
import { stageHref } from '@/lib/onboardingJourney';
import { onboarding as onboardingRoute } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'onboarding.title', href: onboardingRoute() }],
    },
});

const { t } = useI18n();
const page = usePage();
const { currentProfile } = useCurrentProfile();
const { journey, state, isPending, isError, isFetching, refetch } =
    useOnboardingJourney();

const firstName = computed(
    () => (page.props.auth?.user?.name ?? '').trim().split(/\s+/)[0] ?? '',
);

const hero = computed(() => {
    const j = journey.value;
    switch (state.value) {
        case 'inProgress':
            return {
                title: t('onboarding.hero.inProgress.title', {
                    name: firstName.value,
                    step: j?.stepNumber ?? 1,
                }),
                lead: j?.current
                    ? t(`onboarding.route.stages.${j.current}.why`)
                    : '',
                cta: j?.current
                    ? t(`onboarding.route.stages.${j.current}.cta`)
                    : null,
                href: j?.current ? stageHref(j.current, j) : '/envelopes',
            };
        case 'complete':
            return {
                title: t('onboarding.hero.complete.title'),
                lead: t('onboarding.hero.complete.lead'),
                cta: t('onboarding.hero.complete.cta'),
                href: '/budget',
            };
        case 'readOnly':
            return {
                title: t('onboarding.hero.readOnly.title'),
                lead: t('onboarding.hero.readOnly.lead', {
                    profile: currentProfile.value?.name ?? '',
                }),
                cta: null,
                href: '/dashboard',
            };
        default:
            return {
                title: t('onboarding.hero.notStarted.title'),
                lead: t('onboarding.hero.notStarted.lead'),
                cta:
                    state.value === 'notStarted'
                        ? t('onboarding.hero.notStarted.cta')
                        : null,
                href: '/envelopes?new=revenue',
            };
    }
});

const meta = computed(() => {
    const j = journey.value;
    if (state.value === 'notStarted') {
        return t('onboarding.hero.aboutMinutes');
    }
    if (state.value !== 'inProgress' || j === null) {
        return '';
    }
    const step = t('onboarding.route.stepOf', { step: j.stepNumber, total: 4 });
    return j.challenge.started && j.current === 'challenge'
        ? `${step} · ${t('onboarding.route.dayOf', { day: j.challenge.day, total: j.challenge.total })}`
        : step;
});

const routeMeta = computed(() => {
    const j = journey.value;
    if (j === null || j.complete) {
        return '';
    }
    const step = t('onboarding.route.stepOf', { step: j.stepNumber, total: 4 });
    return j.challenge.started && !j.challenge.ready
        ? `${step} · ${t('onboarding.route.dayOf', { day: j.challenge.day, total: j.challenge.total })}`
        : step;
});

const stageDone = (stage: string): boolean =>
    journey.value?.stages.find((s) => s.id === stage)?.status === 'done';
</script>

<template>
    <Head :title="$t('onboarding.title')" />
    <div
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-12 p-4 py-6 sm:p-8"
    >
        <!-- Hero: the promise and the next move. -->
        <section
            class="grid items-center gap-8 rounded-xl bg-brand-container p-6 text-brand-container-foreground sm:p-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:gap-10 lg:p-12"
            aria-labelledby="onboarding-title"
        >
            <div class="grid gap-5">
                <h1
                    id="onboarding-title"
                    class="font-display text-3xl leading-9 tracking-tight text-balance lg:text-5xl lg:leading-[1.167]"
                >
                    {{ hero.title }}
                </h1>
                <p class="max-w-xl text-base leading-7">
                    {{ hero.lead }}
                </p>
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Button
                        v-if="hero.cta"
                        as-child
                        size="lg"
                        class="w-full gap-2 sm:w-auto"
                    >
                        <Link :href="hero.href">
                            {{ hero.cta }}
                            <Icon
                                name="arrow-forward"
                                class="size-4 rtl:rotate-180"
                                aria-hidden="true"
                            />
                        </Link>
                    </Button>
                    <Button
                        as-child
                        size="lg"
                        :variant="hero.cta ? 'ghost' : 'default'"
                        class="w-full sm:w-auto"
                        :class="
                            hero.cta &&
                            'hover:bg-brand-container-foreground/10 hover:text-brand-container-foreground'
                        "
                    >
                        <Link href="/dashboard">{{
                            $t('onboarding.hero.dashboard')
                        }}</Link>
                    </Button>
                </div>
                <p v-if="meta" class="text-sm tabular-nums">
                    {{ meta }}
                </p>
            </div>
            <figure class="mx-auto grid w-full max-w-md gap-3">
                <div class="mx-auto w-full max-w-60 sm:max-w-sm lg:max-w-md">
                    <OnboardingIllustration name="envelopeFlow" />
                </div>
                <figcaption class="text-center text-sm">
                    {{ $t('onboarding.hero.caption') }}
                </figcaption>
            </figure>
        </section>

        <!-- The route: four stages, live progress on the current one. -->
        <section class="grid gap-6" aria-labelledby="route-title">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="route-title" class="text-xl leading-7 font-semibold">
                    {{ $t('onboarding.route.title') }}
                </h2>
                <p
                    v-if="routeMeta"
                    class="text-sm text-muted-foreground tabular-nums"
                >
                    {{ routeMeta }}
                </p>
            </div>

            <div
                v-if="isPending"
                class="grid gap-4"
                role="status"
                :aria-label="$t('onboarding.route.loading')"
            >
                <div class="flex items-center gap-4">
                    <Skeleton
                        v-for="i in 4"
                        :key="i"
                        class="size-10 shrink-0 rounded-full"
                    />
                </div>
                <Skeleton class="h-40 w-full rounded-xl" />
            </div>
            <div
                v-else-if="isError"
                class="flex flex-wrap items-center gap-2 rounded-xl border px-4 py-3 text-sm"
                role="alert"
            >
                <Icon
                    name="error"
                    class="size-5 text-destructive"
                    aria-hidden="true"
                />
                {{ $t('onboarding.journey.error') }}
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="isFetching"
                    @click="refetch()"
                >
                    {{ $t('common.actions.retry') }}
                </Button>
            </div>
            <JourneyRoute v-else :journey="journey" />
        </section>

        <div
            class="grid gap-8"
            :class="
                state !== 'readOnly' &&
                'lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]'
            "
        >
            <section
                v-if="state !== 'readOnly'"
                aria-labelledby="pledge-title"
                class="grid content-start gap-4"
            >
                <PledgeCard :first-name="firstName" />
            </section>

            <section
                id="guides"
                class="grid scroll-mt-20 content-start gap-4"
                aria-labelledby="guides-title"
            >
                <div>
                    <h2
                        id="guides-title"
                        class="text-xl leading-7 font-semibold"
                    >
                        {{ $t('onboarding.guides.title') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('onboarding.guides.description') }}
                    </p>
                </div>
                <ul class="divide-y overflow-hidden rounded-xl border bg-card">
                    <li v-for="guide in GUIDES" :key="guide.topic">
                        <GuideCard
                            :guide="guide"
                            :done="stageDone(guide.stage)"
                        />
                    </li>
                </ul>
            </section>
        </div>

        <p class="text-sm text-muted-foreground">
            {{ $t('onboarding.footer') }}
        </p>
    </div>
</template>
