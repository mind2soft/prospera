<script setup lang="ts">
import { Donut } from '@unovis/ts';
// Per-component entry points (see IncomeExpensesWidget.vue).
import VisDonut from '@unovis/vue/components/donut';
import VisTooltip from '@unovis/vue/components/tooltip';
import VisSingleContainer from '@unovis/vue/containers/single-container';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { useChartDuration } from '@/composables/useReducedMotion';
import { formatCompactMoney, formatMoney } from '@/lib/budget';
import { escapeHtml } from '@/lib/html';
import { isClient } from '@/lib/ssr';
import type { DashboardRadialRow, DashboardWidgetData } from '@/types';

const props = defineProps<{
    data: DashboardWidgetData;
    viewType: string;
    currency: string;
}>();

const { locale } = useLocale();
const chartDuration = useChartDuration();

const rows = computed(() =>
    props.data.type === 'radial' ? props.data.rows : [],
);

/** Only amount-visible rows are charted; masked rows stay in the legend. */
const chartRows = computed(() =>
    rows.value.filter((row) => !row.masked && (row.value ?? 0) > 0),
);

const total = computed(() =>
    chartRows.value.reduce((sum, row) => sum + (row.value ?? 0), 0),
);

/** Fallback palette for groups (which carry no envelope color). */
const PALETTE = [
    '#3b82f6',
    '#f97316',
    '#a855f7',
    '#14b8a6',
    '#eab308',
    '#ec4899',
    '#6366f1',
    '#84cc16',
];

const FALLBACK_COLOR = 'var(--muted-foreground)';

function colorOf(row: DashboardRadialRow, index: number): string {
    return row.color ?? PALETTE[index % PALETTE.length];
}

const value = (d: DashboardRadialRow): number => d.value ?? 0;

/** Compact currency for the donut's central total ("$2.5K"). */
const compactMoney = (amount: number): string =>
    formatCompactMoney(amount, props.currency, locale.value);

/** Segment colors keyed by row key (masked rows fall back to muted). */
const colorByKey = computed(() => {
    const map: Record<string, string> = {};

    chartRows.value.forEach((row, index) => {
        map[row.key] = colorOf(row, index);
    });

    return map;
});

const color = (d: DashboardRadialRow): string =>
    colorByKey.value[d.key] ?? FALLBACK_COLOR;

// arcWidth is in pixels; 0 renders a full pie.
const arcWidth = computed(() => (props.viewType === 'donut' ? 24 : 0));

/** The d3 pie arc Unovis hands to segment triggers (its `DonutArcDatum`). */
type SegmentArc = { data?: DashboardRadialRow };

/**
 * Segment tooltip. Unovis passes the d3 pie arc, whose `data` is the row. The
 * returned string is written with `innerHTML`, so every interpolated value is
 * escaped (row names are user-provided).
 */
const tooltipTemplate = (arc?: SegmentArc): string => {
    const datum = arc?.data;

    if (!datum) {
        return '';
    }

    // Same locale-formatted share as the legend ("12,5 %" in French).
    const percentage = legendShare(datum);
    const share = percentage === null ? '' : ` (${percentage})`;

    return `<div style="font-size:12px">
        <strong>${escapeHtml(datum.name)}</strong><br/>
        <span style="font-variant-numeric:tabular-nums">${escapeHtml(
            datum.value === null
                ? '•••'
                : formatMoney(datum.value, props.currency, locale.value),
        )}${escapeHtml(share)}</span>
    </div>`;
};

function legendShare(row: DashboardRadialRow): string | null {
    if (row.masked || row.value === null || total.value === 0) {
        return null;
    }

    const percentage = new Intl.NumberFormat(locale.value, {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(row.value / total.value);

    return percentage;
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-2">
        <p
            v-if="rows.length === 0"
            class="m-auto px-4 text-center text-sm text-muted-foreground"
        >
            {{ $t('dashboard.widget.emptySelection') }}
        </p>

        <template v-else>
            <div
                v-if="isClient && chartRows.length > 0"
                class="flex min-h-0 flex-1 items-center justify-center"
            >
                <!-- Visual only: the legend lists every row with its amount
                     and share (the text equivalent). -->
                <VisSingleContainer
                    :duration="chartDuration"
                    aria-hidden="true"
                    data-testid="radial-chart"
                    :data="chartRows"
                    :height="200"
                    class="chart-surface"
                >
                    <VisDonut
                        :value="value"
                        :color="color"
                        :arc-width="arcWidth"
                        :pad-angle="0.03"
                        :corner-radius="6"
                        :central-label="
                            viewType === 'donut'
                                ? compactMoney(total)
                                : undefined
                        "
                    />
                    <VisTooltip
                        :triggers="{
                            [Donut.selectors.segment]: tooltipTemplate,
                        }"
                    />
                </VisSingleContainer>
            </div>

            <div
                v-else-if="isClient"
                class="flex min-h-0 flex-1 items-center justify-center text-sm text-muted-foreground"
            >
                {{ $t('dashboard.widget.emptySelection') }}
            </div>

            <div
                v-else
                class="min-h-24 flex-1 animate-pulse rounded-md bg-muted"
            />

            <!-- Legend: masked rows stay listed, without amounts. -->
            <ul
                class="flex flex-wrap items-center justify-center gap-1.5 px-1 pb-1 text-xs"
            >
                <li
                    v-for="row in rows"
                    :key="row.key"
                    class="flex items-center gap-1.5 rounded-full border border-border/70 bg-muted/50 px-2.5 py-0.5 font-medium text-muted-foreground"
                    :class="row.masked ? 'text-muted-foreground' : ''"
                >
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :style="{
                            backgroundColor: row.masked
                                ? 'var(--muted)'
                                : (colorByKey[row.key] ?? FALLBACK_COLOR),
                        }"
                        aria-hidden="true"
                    />
                    <span class="font-medium">{{ row.name }}</span>
                    <span
                        v-if="!row.masked && row.value !== null"
                        class="text-foreground tabular-nums"
                    >
                        {{ formatMoney(row.value, currency, locale) }}
                    </span>
                    <span
                        v-if="legendShare(row) !== null"
                        class="text-muted-foreground tabular-nums"
                    >
                        · {{ legendShare(row) }}
                    </span>
                    <template v-else-if="row.masked">
                        <span aria-hidden="true">•••</span>
                        <span class="sr-only">{{
                            $t('dashboard.widget.amountHidden')
                        }}</span>
                    </template>
                </li>
            </ul>
        </template>
    </div>
</template>
