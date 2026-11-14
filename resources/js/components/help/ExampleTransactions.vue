<script setup lang="ts">
import { computed } from 'vue';
import EnvelopeChip from '@/components/EnvelopeChip.vue';
import { useExampleMoney } from '@/composables/useExampleMoney';
import { useLocale } from '@/composables/useLocale';
import { EXAMPLE_COLORS, EXAMPLES } from '@/lib/helpGuides';

const { locale } = useLocale();
const money = useExampleMoney();

const rows = computed(() => {
    const format = new Intl.DateTimeFormat(locale.value, {
        day: 'numeric',
        month: 'short',
    });
    const today = new Date();
    return EXAMPLES.transactions.map((row) => ({
        ...row,
        name:
            row.key === 'paycheck'
                ? 'onboarding.starter.revenue.paycheck'
                : `onboarding.starter.expense.${row.key}`,
        color: EXAMPLE_COLORS[row.key],
        date: format.format(
            new Date(
                today.getFullYear(),
                today.getMonth(),
                today.getDate() - row.daysAgo,
            ),
        ),
    }));
});
</script>

<template>
    <div class="overflow-hidden rounded-xl border bg-card">
        <table class="w-full text-sm">
            <caption
                class="border-b px-4 py-2 text-start text-xs font-medium text-muted-foreground uppercase"
            >
                {{ $t('help.common.example') }}
            </caption>
            <thead class="sr-only">
                <tr>
                    <th scope="col">
                        {{ $t('help.transactions.sections.record.date') }}
                    </th>
                    <th scope="col">
                        {{ $t('help.transactions.sections.record.envelope') }}
                    </th>
                    <th scope="col">
                        {{ $t('help.transactions.sections.record.amount') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="row in rows" :key="row.key">
                    <td
                        class="px-4 py-3 whitespace-nowrap text-muted-foreground tabular-nums"
                    >
                        {{ row.date }}
                    </td>
                    <td class="px-4 py-3">
                        <EnvelopeChip
                            :name="$t(row.name)"
                            :color="row.color"
                            :direction="row.direction"
                        />
                    </td>
                    <td
                        class="px-4 py-3 text-end font-medium whitespace-nowrap tabular-nums"
                    >
                        {{ row.direction === 'revenue' ? '+' : '−' }}
                        {{ money.format(row.amount) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
