<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check, CircleDollarSign, ListChecks } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useOnboardingProgress } from '@/composables/onboardingQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';

const { currentProfile } = useCurrentProfile();
const progressQuery = useOnboardingProgress();
const progress = computed(() => progressQuery.data.value);
const topics = ['envelopes', 'ledgers', 'transactions', 'budgets'] as const;
</script>

<template>
    <Head :title="$t('onboarding.title')" />
    <div
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 p-4 py-8 sm:p-8"
    >
        <header class="max-w-3xl space-y-4">
            <div
                class="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary"
            >
                <CircleDollarSign class="size-6" aria-hidden="true" />
            </div>
            <h1
                class="font-display text-3xl leading-9 tracking-tight sm:text-4xl"
            >
                {{ $t('onboarding.title') }}
            </h1>
            <p class="text-base leading-7 text-muted-foreground">
                {{ $t('onboarding.intro') }}
            </p>
        </header>

        <Card class="border-primary/20 bg-primary/5">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-xl">
                    <ListChecks
                        class="size-5 text-primary"
                        aria-hidden="true"
                    />
                    {{ $t('onboarding.steps.title') }}
                </CardTitle>
            </CardHeader>
            <CardContent class="grid gap-5 sm:grid-cols-3">
                <div class="flex gap-3">
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-background text-sm font-semibold"
                        >1</span
                    >
                    <p class="text-sm leading-6">
                        {{ $t('onboarding.steps.envelopes') }}
                    </p>
                </div>
                <div class="flex gap-3">
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-background text-sm font-semibold"
                        >2</span
                    >
                    <p class="text-sm leading-6">
                        {{ $t('onboarding.steps.ledger') }}
                    </p>
                </div>
                <div class="flex gap-3">
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-background text-sm font-semibold"
                        >3</span
                    >
                    <p class="text-sm leading-6">
                        {{ $t('onboarding.steps.budget') }}
                    </p>
                </div>
            </CardContent>
        </Card>

        <section
            class="grid gap-4 sm:grid-cols-2"
            :aria-label="$t('onboarding.help.title')"
        >
            <Card v-for="topic in topics" :key="topic" class="h-full">
                <CardContent class="flex h-full flex-col gap-3 p-5">
                    <h2 class="text-lg font-semibold">
                        {{ $t(`onboarding.help.${topic}.title`) }}
                    </h2>
                    <p class="flex-1 text-sm leading-6 text-muted-foreground">
                        {{ $t(`onboarding.help.${topic}.summary`) }}
                    </p>
                    <Link
                        :href="`/help/${topic}`"
                        class="inline-flex min-h-11 items-center gap-2 self-start text-sm font-medium text-primary underline-offset-4 hover:underline"
                    >
                        {{ $t('onboarding.readGuide') }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </CardContent>
            </Card>
        </section>

        <Card v-if="progress && currentProfile" aria-live="polite">
            <CardContent
                class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="font-semibold">
                        {{ $t('onboarding.progress.title') }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            $t('onboarding.progress.envelopes', {
                                revenue: progress.missing_revenue_envelopes,
                                expenses: progress.missing_expense_envelopes,
                            })
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t('onboarding.progress.budget', {
                                days: progress.history_days_remaining,
                                envelopes:
                                    progress.envelopes_missing_transactions,
                            })
                        }}
                    </p>
                </div>
                <Button as-child class="gap-2">
                    <Link href="/envelopes">
                        {{ $t('onboarding.progress.action') }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </CardContent>
        </Card>

        <p class="text-sm text-muted-foreground">
            <Check class="mr-1 inline size-4 text-success" aria-hidden="true" />
            {{ $t('onboarding.encouragement') }}
        </p>
    </div>
</template>
