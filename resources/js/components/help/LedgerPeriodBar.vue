<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import { useExampleMoney } from '@/composables/useExampleMoney';
import { useLocale } from '@/composables/useLocale';
import { EXAMPLES } from '@/lib/helpGuides';

const { locale } = useLocale();
const money = useExampleMoney();

const year = new Date().getFullYear() + 1;
const months = computed(() => {
    const format = new Intl.DateTimeFormat(locale.value, { month: 'narrow' });
    return Array.from({ length: 12 }, (_, month) =>
        format.format(new Date(year, month, 1)),
    );
});
const dateFormat = computed(
    () =>
        new Intl.DateTimeFormat(locale.value, {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }),
);
const opens = computed(() => dateFormat.value.format(new Date(year, 0, 1)));
const closes = computed(() => dateFormat.value.format(new Date(year, 11, 31)));
</script>

<template>
    <div class="grid gap-4 rounded-xl border bg-card p-5">
        <div class="flex items-baseline justify-between gap-2">
            <p class="text-base font-medium tabular-nums">{{ year }}</p>
            <span class="text-xs font-medium text-muted-foreground uppercase">{{
                $t('help.common.example')
            }}</span>
        </div>
        <div
            class="grid grid-cols-12 gap-0.5"
            role="img"
            :aria-label="
                $t('help.ledgers.sections.example.periodLabel', {
                    from: opens,
                    to: closes,
                })
            "
        >
            <span
                v-for="(month, index) in months"
                :key="index"
                class="relative grid h-9 place-items-center rounded-sm bg-muted text-xs text-muted-foreground"
                :class="index === 11 && 'bg-brand text-brand-foreground'"
                aria-hidden="true"
            >
                <Icon
                    v-if="index === 0"
                    name="flag-outline"
                    class="absolute start-0.5 -top-2 size-4 text-brand"
                />
                {{ month }}
            </span>
        </div>
        <div
            class="flex flex-wrap justify-between gap-2 text-sm text-muted-foreground"
        >
            <span>{{
                $t('help.ledgers.sections.example.opens', { date: opens })
            }}</span>
            <span>{{
                $t('help.ledgers.sections.example.closes', { date: closes })
            }}</span>
        </div>
        <dl class="grid grid-cols-2 gap-4 border-t pt-4 sm:grid-cols-3">
            <div>
                <dt class="text-xs text-muted-foreground">
                    {{ $t('help.ledgers.sections.example.start') }}
                </dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ money.format(EXAMPLES.ledger.start) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    {{ $t('help.ledgers.sections.example.close') }}
                </dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ money.format(EXAMPLES.ledger.close) }}
                </dd>
            </div>
            <div class="col-span-2 sm:col-span-1">
                <dt class="sr-only">{{ $t('help.common.example') }}</dt>
                <dd
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-success"
                >
                    <Icon name="north" class="size-4" aria-hidden="true" />
                    {{
                        $t('help.ledgers.sections.example.ahead', {
                            amount: money.format(
                                EXAMPLES.ledger.close - EXAMPLES.ledger.start,
                            ),
                        })
                    }}
                </dd>
            </div>
        </dl>
    </div>
</template>
