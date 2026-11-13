<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

const props = defineProps<{
    topic: 'envelopes' | 'ledgers' | 'transactions' | 'budgets';
}>();
const { t } = useI18n();
const guide = computed(() =>
    t(`onboarding.help.${props.topic}.body`).split('\n\n'),
);
const destinations: Record<typeof props.topic, string> = {
    envelopes: '/envelopes',
    ledgers: '/ledger',
    transactions: '/ledger',
    budgets: '/budget',
};
</script>

<template>
    <Head :title="$t(`onboarding.help.${topic}.title`)" />
    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 py-8 sm:p-8"
    >
        <Link
            href="/onboarding"
            class="inline-flex min-h-11 items-center gap-2 self-start text-sm font-medium text-primary underline-offset-4 hover:underline"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ $t('onboarding.back') }}
        </Link>
        <header class="space-y-3">
            <h1
                class="font-display text-3xl leading-9 tracking-tight sm:text-4xl"
            >
                {{ $t(`onboarding.help.${topic}.title`) }}
            </h1>
            <p class="text-base leading-7 text-muted-foreground">
                {{ $t(`onboarding.help.${topic}.summary`) }}
            </p>
        </header>
        <Card>
            <CardContent class="space-y-4 p-6">
                <p
                    v-for="(paragraph, index) in guide"
                    :key="index"
                    class="text-base leading-7"
                >
                    {{ paragraph }}
                </p>
            </CardContent>
        </Card>
        <Button as-child class="gap-2 self-start">
            <Link :href="destinations[topic]">
                {{ $t(`onboarding.help.${topic}.action`) }}
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </Button>
    </main>
</template>
