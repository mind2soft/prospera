<script setup lang="ts">
import {
    VisAxis,
    VisCrosshair,
    VisLine,
    VisStackedBar,
    VisTooltip,
    VisXYContainer,
} from '@unovis/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useLocale } from '@/composables/useLocale';
import { formatMoney } from '@/lib/budget';
import { isClient } from '@/lib/ssr';
import type { DashboardHistoryBucket, DashboardWidgetData } from '@/types';

const props = defineProps<{
    data: DashboardWidgetData;
    viewType: string;
    currency: string;
}>();

const { t } = useI18n();
const { locale } = useLocale();

const series = computed<DashboardHistoryBucket[]>(() =>
    props.data.type === 'income_expenses' ? props.data.series : [],
);

const masked = computed(
    () => props.data.type === 'income_expenses' && props.data.masked,
);

/** The x accessor is the bucket index; labels are resolved per bucket. */
const x = (_: DashboardHistoryBucket, i: number): number => i;

const revenue = (d: DashboardHistoryBucket): number => d.revenue ?? 0;
const expenses = (d: DashboardHistoryBucket): number => d.expenses ?? 0;

const REVENUE_COLOR = '#10b981';
const EXPENSE_COLOR = '#ef4444';

const labels = computed(() =>
    series.value.map((bucket) => formatBucket(bucket.bucket)),
);

function formatBucket(bucket: string): string {
    const date =
        bucket.length === 7
            ? new Date(`${bucket}-01T00:00:00`)
            : new Date(`${bucket}T00:00:00`);

    return Number.isNaN(date.getTime())
        ? bucket
        : date.toLocaleDateString(locale.value, {
              month: 'short',
              ...(bucket.length === 7
                  ? { year: '2-digit' }
                  : { day: 'numeric' }),
          });
}

const xTickFormat = (i: number): string => labels.value[i] ?? '';

/** Compact currency for the y-axis ticks ("$2.5K"); full format in tooltips. */
const compactMoney = (value: number): string => {
    try {
        return new Intl.NumberFormat(locale.value, {
            style: 'currency',
            currency: props.currency,
            currencyDisplay: 'narrowSymbol',
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(value);
    } catch {
        return formatMoney(value, props.currency);
    }
};

const yTickFormat = (value: number): string => compactMoney(value);

const tooltipTemplate = (datum?: DashboardHistoryBucket): string => {
    if (!datum) {
        return '';
    }

    const row = (label: string, amount: number | null): string =>
        `<div style="display:flex;gap:8px;align-items:baseline;justify-content:space-between;">
            <span>${label}</span>
            <strong style="font-variant-numeric:tabular-nums">${
                amount === null ? '•••' : formatMoney(amount, props.currency)
            }</strong>
        </div>`;

    return `<div style="font-size:12px">
        <div style="font-weight:600;margin-bottom:4px">${formatBucket(datum.bucket)}</div>
        ${row(t('dashboard.history.revenue'), datum.revenue)}
        ${row(t('dashboard.history.expenses'), datum.expenses)}
    </div>`;
};
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Masked series: no chart at all — zero-filled bars would imply data. -->
        <p
            v-if="masked"
            class="m-auto px-4 text-center text-sm text-muted-foreground"
        >
            {{ $t('dashboard.widget.masked') }}
        </p>

        <!-- Unovis needs a DOM to measure — client-side only. -->
        <div v-else-if="isClient" class="min-h-0 flex-1">
            <VisXYContainer :data="series" :height="180">
                <VisStackedBar
                    v-if="viewType === 'stacked_bars'"
                    :x="x"
                    :y="[revenue, expenses]"
                    :color="[REVENUE_COLOR, EXPENSE_COLOR]"
                    :bar-padding="0.2"
                />
                <template v-else>
                    <VisLine
                        :x="x"
                        :y="revenue"
                        :color="REVENUE_COLOR"
                        :line-width="2"
                    />
                    <VisLine
                        :x="x"
                        :y="expenses"
                        :color="EXPENSE_COLOR"
                        :line-width="2"
                    />
                </template>

                <VisAxis
                    type="x"
                    :num-ticks="Math.min(series.length, 7)"
                    :tick-format="xTickFormat"
                    :grid-line="false"
                />
                <VisAxis
                    type="y"
                    :num-ticks="4"
                    :tick-format="yTickFormat"
                    :grid-line="true"
                />

                <VisCrosshair :template="tooltipTemplate" />
                <VisTooltip />
            </VisXYContainer>
        </div>

        <div v-else class="min-h-0 flex-1 animate-pulse rounded-md bg-muted" />

        <div
            class="mt-1 flex items-center justify-center gap-4 text-xs text-muted-foreground"
        >
            <span class="flex items-center gap-1.5">
                <span
                    class="size-2.5 rounded-full"
                    :style="{ backgroundColor: REVENUE_COLOR }"
                />
                {{ $t('dashboard.history.revenue') }}
            </span>
            <span class="flex items-center gap-1.5">
                <span
                    class="size-2.5 rounded-full"
                    :style="{ backgroundColor: EXPENSE_COLOR }"
                />
                {{ $t('dashboard.history.expenses') }}
            </span>
        </div>
    </div>
</template>
