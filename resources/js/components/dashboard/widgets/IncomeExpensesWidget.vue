<script setup lang="ts">
import { CurveType } from '@unovis/ts';
// Per-component entry points: the @unovis/vue barrel is flagged as having
// side effects, so importing from it bundles every chart, map and graph.
import VisArea from '@unovis/vue/components/area';
import VisAxis from '@unovis/vue/components/axis';
import VisCrosshair from '@unovis/vue/components/crosshair';
import VisLine from '@unovis/vue/components/line';
import VisStackedBar from '@unovis/vue/components/stacked-bar';
import VisTooltip from '@unovis/vue/components/tooltip';
import VisXYContainer from '@unovis/vue/containers/xy-container';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useLocale } from '@/composables/useLocale';
import { useChartDuration } from '@/composables/useReducedMotion';
import { formatCompactMoney, formatMoney } from '@/lib/budget';
import { escapeHtml } from '@/lib/html';
import { isClient } from '@/lib/ssr';
import type { DashboardHistoryBucket, DashboardWidgetData } from '@/types';

const props = defineProps<{
    data: DashboardWidgetData;
    viewType: string;
    currency: string;
}>();

const { t } = useI18n();
const { locale } = useLocale();
const chartDuration = useChartDuration();

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

const REVENUE_COLOR = 'var(--chart-revenue)';
const EXPENSE_COLOR = 'var(--chart-expense)';

/** The crosshair line stops being default black. */
const crosshairColor = 'var(--muted-foreground)';

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
const compactMoney = (value: number): string =>
    formatCompactMoney(value, props.currency, locale.value);

const yTickFormat = (value: number): string => compactMoney(value);

/**
 * Explicit domain: 0 is always on-chart, otherwise the data min/max plus
 * ~8% breathing room on each populated side.
 */
const yDomain = computed<[number | undefined, number | undefined]>(() => {
    const values = series.value.flatMap((bucket) => [
        bucket.revenue ?? 0,
        bucket.expenses ?? 0,
    ]);

    if (values.length === 0) {
        return [0, 1];
    }

    const min = Math.min(0, ...values);
    const max = Math.max(0, ...values);
    const span = max - min;
    const pad = (span === 0 ? Math.abs(max) || 1 : span) * 0.08;

    return [min < 0 ? min - pad : 0, max > 0 ? max + pad : 0];
});

/** A bucket amount for the data table (masked buckets are never shown). */
const amountText = (amount: number | null): string =>
    amount === null
        ? t('dashboard.widget.amountHidden')
        : formatMoney(amount, props.currency, locale.value);

const tooltipTemplate = (datum?: DashboardHistoryBucket): string => {
    if (!datum) {
        return '';
    }

    const row = (label: string, color: string, amount: number | null): string =>
        `<div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;">
            <span style="display:flex;align-items:center;gap:6px;">
                <span style="width:6px;height:6px;border-radius:9999px;background:${escapeHtml(color)};flex:none;"></span>
                <span style="color:var(--muted-foreground);">${escapeHtml(label)}</span>
            </span>
            <strong style="font-variant-numeric:tabular-nums;">${escapeHtml(
                amount === null
                    ? '•••'
                    : formatMoney(amount, props.currency, locale.value),
            )}</strong>
        </div>`;

    return `<div style="font-size:12px;min-width:160px;">
        <div style="font-weight:600;margin-bottom:6px;">${escapeHtml(formatBucket(datum.bucket))}</div>
        ${row(t('dashboard.history.revenue'), REVENUE_COLOR, datum.revenue)}
        ${row(t('dashboard.history.expenses'), EXPENSE_COLOR, datum.expenses)}
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
        <!-- The chart is visual only: the data table below is its text
             equivalent (keyboard, touch and screen readers). -->
        <div
            v-else-if="isClient"
            class="chart-surface min-h-0 flex-1"
            aria-hidden="true"
        >
            <VisXYContainer
                :data="series"
                :height="220"
                :y-domain="yDomain"
                :duration="chartDuration"
            >
                <VisStackedBar
                    v-if="viewType === 'stacked_bars'"
                    :x="x"
                    :y="[revenue, expenses]"
                    :color="[REVENUE_COLOR, EXPENSE_COLOR]"
                    :bar-padding="0.25"
                    :radius="3"
                />
                <template v-else>
                    <VisArea
                        :x="x"
                        :y="revenue"
                        :color="REVENUE_COLOR"
                        :curve-type="CurveType.MonotoneX"
                        :opacity="0.12"
                    />
                    <VisArea
                        :x="x"
                        :y="expenses"
                        :color="EXPENSE_COLOR"
                        :curve-type="CurveType.MonotoneX"
                        :opacity="0.12"
                    />
                    <VisLine
                        :x="x"
                        :y="revenue"
                        :color="REVENUE_COLOR"
                        :curve-type="CurveType.MonotoneX"
                        :line-width="2"
                    />
                    <VisLine
                        :x="x"
                        :y="expenses"
                        :color="EXPENSE_COLOR"
                        :curve-type="CurveType.MonotoneX"
                        :line-width="2"
                    />
                </template>

                <VisAxis
                    type="x"
                    :num-ticks="Math.min(series.length, 6)"
                    :tick-format="xTickFormat"
                    :grid-line="false"
                />
                <VisAxis
                    type="y"
                    :num-ticks="4"
                    :tick-format="yTickFormat"
                    :grid-line="true"
                    :domain-line="false"
                />

                <VisCrosshair
                    :template="tooltipTemplate"
                    :color="crosshairColor"
                />
                <VisTooltip />
            </VisXYContainer>
        </div>

        <div v-else class="min-h-0 flex-1 animate-pulse rounded-md bg-muted" />

        <details
            v-if="!masked && series.length > 0"
            class="mt-1.5 text-xs"
            data-testid="history-data"
        >
            <summary
                class="cursor-pointer rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {{ $t('dashboard.history.showData') }}
            </summary>
            <div class="mt-1.5 max-h-48 overflow-y-auto">
                <table class="w-full tabular-nums">
                    <thead class="text-muted-foreground">
                        <tr>
                            <th scope="col" class="py-1 text-start font-medium">
                                {{ $t('dashboard.history.period') }}
                            </th>
                            <th scope="col" class="py-1 text-end font-medium">
                                {{ $t('dashboard.history.revenue') }}
                            </th>
                            <th scope="col" class="py-1 text-end font-medium">
                                {{ $t('dashboard.history.expenses') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(bucket, index) in series"
                            :key="bucket.bucket"
                            class="border-t"
                        >
                            <th scope="row" class="py-1 text-start font-normal">
                                {{ labels[index] }}
                            </th>
                            <td class="py-1 text-end">
                                {{ amountText(bucket.revenue) }}
                            </td>
                            <td class="py-1 text-end">
                                {{ amountText(bucket.expenses) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>

        <div
            class="mt-1.5 flex items-center justify-center gap-1.5 text-xs"
            aria-hidden="true"
        >
            <span
                class="flex items-center gap-1.5 rounded-full border border-border/70 bg-muted/50 px-2.5 py-0.5 font-medium text-muted-foreground"
            >
                <span
                    class="size-2 rounded-full"
                    :style="{ backgroundColor: REVENUE_COLOR }"
                    aria-hidden="true"
                />
                {{ $t('dashboard.history.revenue') }}
            </span>
            <span
                class="flex items-center gap-1.5 rounded-full border border-border/70 bg-muted/50 px-2.5 py-0.5 font-medium text-muted-foreground"
            >
                <span
                    class="size-2 rounded-full"
                    :style="{ backgroundColor: EXPENSE_COLOR }"
                    aria-hidden="true"
                />
                {{ $t('dashboard.history.expenses') }}
            </span>
        </div>
    </div>
</template>
