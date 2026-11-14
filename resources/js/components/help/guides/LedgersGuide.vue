<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DoDont from '@/components/help/DoDont.vue';
import GuideSection from '@/components/help/GuideSection.vue';
import LedgerPeriodBar from '@/components/help/LedgerPeriodBar.vue';
import TryItNow from '@/components/help/TryItNow.vue';
import Icon from '@/components/Icon.vue';
import LedgerPeriodIllustration from '@/components/onboarding/illustrations/LedgerPeriodIllustration.vue';
import type { MaterialSymbol } from '@/lib/materialSymbols';
import type { Journey } from '@/lib/onboardingJourney';

const props = defineProps<{ journey: Journey | null; readOnly: boolean }>();

const { t } = useI18n();

const locked = computed(() => props.journey?.envelopes.ready === false);
const done = computed(() => props.journey?.hasLedger === true);
const keys = ['a', 'b', 'c'] as const;
const recipe: {
    key: 'balance' | 'opening' | 'closing';
    icon: MaterialSymbol;
}[] = [
    { key: 'balance', icon: 'savings' },
    { key: 'opening', icon: 'flag-outline' },
    { key: 'closing', icon: 'flag' },
];
const linkClass =
    'font-medium underline decoration-brand/60 underline-offset-4 hover:decoration-brand';
</script>

<template>
    <p
        v-if="locked && !readOnly"
        class="inline-flex items-start gap-2 rounded-xl border bg-card px-4 py-3 text-sm"
    >
        <Icon
            name="lock"
            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <span>
            {{ $t('help.ledgers.unlockNote') }}
            <Link href="/help/envelopes" :class="linkClass">{{
                $t('help.ledgers.unlockLink')
            }}</Link>
        </span>
    </p>

    <GuideSection
        id="goal"
        :title="$t('help.ledgers.sections.goal.title')"
        :caption="$t('help.ledgers.sections.goal.caption')"
    >
        <i18n-t keypath="help.ledgers.sections.goal.body" tag="p">
            <template #page>
                <Link href="/ledger" :class="linkClass">{{
                    $t('help.ledgers.page')
                }}</Link>
            </template>
        </i18n-t>
        <template #figure>
            <div class="rounded-xl border bg-card p-6">
                <div class="mx-auto max-w-md">
                    <LedgerPeriodIllustration />
                </div>
            </div>
        </template>
    </GuideSection>

    <GuideSection
        id="recipe"
        :title="$t('help.ledgers.sections.recipe.title')"
        :caption="$t('help.ledgers.sections.recipe.caption')"
    >
        <template #figure>
            <ol class="divide-y rounded-xl border bg-card">
                <li
                    v-for="(item, index) in recipe"
                    :key="item.key"
                    class="flex items-start gap-3 p-4"
                >
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-full bg-muted"
                        aria-hidden="true"
                    >
                        <Icon :name="item.icon" class="size-5" />
                    </span>
                    <p class="text-base leading-6">
                        <span class="sr-only">{{ index + 1 }}.</span>
                        <strong class="font-semibold">{{
                            $t(`help.ledgers.sections.recipe.${item.key}.term`)
                        }}</strong>
                        {{
                            $t(`help.ledgers.sections.recipe.${item.key}.text`)
                        }}
                    </p>
                </li>
            </ol>
        </template>
    </GuideSection>

    <GuideSection
        id="example"
        :title="$t('help.ledgers.sections.example.title')"
        :caption="$t('help.ledgers.sections.example.caption')"
    >
        <template #figure>
            <LedgerPeriodBar />
        </template>
    </GuideSection>

    <GuideSection id="win" :title="$t('help.ledgers.sections.win.title')">
        <template #figure>
            <DoDont
                :dos="keys.map((k) => t(`help.ledgers.dos.${k}`))"
                :avoids="keys.map((k) => t(`help.ledgers.avoids.${k}`))"
            />
        </template>
    </GuideSection>

    <TryItNow
        topic="ledgers"
        :text="
            done
                ? $t('help.ledgers.challenge.done')
                : $t('help.ledgers.challenge.text')
        "
        :done="done"
        :read-only="readOnly"
        page-href="/ledger"
        :cta="
            locked
                ? {
                      label: $t('help.ledgers.challenge.lockedCta'),
                      href: '/envelopes',
                  }
                : {
                      label: $t('help.ledgers.challenge.cta'),
                      href: '/ledger?create=1',
                  }
        "
        :next="{
            label: $t('help.common.nextGuide', {
                title: $t('help.transactions.title'),
            }),
            href: '/help/transactions',
        }"
    />
</template>
