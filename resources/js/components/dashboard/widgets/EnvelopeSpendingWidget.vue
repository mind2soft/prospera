<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import { formatMoney } from '@/lib/budget';
import type { DashboardSpendingRow, DashboardWidgetData } from '@/types';

const props = defineProps<{
    data: DashboardWidgetData;
    currency: string;
}>();

const rows = computed(() =>
    props.data.type === 'envelope_spending' ? props.data.rows : [],
);

/**
 * Budget progress fraction (0..1, > 1 when overspent); null when there is
 * no published budget covering the period (empty progress bar) or the row
 * is masked.
 */
function progress(row: DashboardSpendingRow): number | null {
    if (row.masked || row.spent === null || row.allocated === null) {
        return null;
    }

    const denominator = Math.abs(row.allocated);

    return denominator === 0 ? null : Math.abs(row.spent) / denominator;
}

function isOverBudget(row: DashboardSpendingRow): boolean {
    return (progress(row) ?? 0) > 1;
}

/** Revenue rows render as income progress — the bar fills from the goal side. */
function isRevenue(row: DashboardSpendingRow): boolean {
    return row.direction === 'revenue';
}

function spentLabel(row: DashboardSpendingRow): string {
    if (row.masked || row.spent === null) {
        return '•••';
    }

    return formatMoney(row.spent, props.currency);
}

function allocatedLabel(row: DashboardSpendingRow): string | null {
    if (row.masked || row.allocated === null) {
        return null;
    }

    return formatMoney(row.allocated, props.currency);
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <p
            v-if="rows.length === 0"
            class="m-auto px-4 text-center text-sm text-muted-foreground"
        >
            {{ $t('dashboard.widget.emptySelection') }}
        </p>

        <!-- Long lists scroll inside the widget instead of stretching the grid row. -->
        <ul
            v-else
            class="flex max-h-64 min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-1 py-0.5"
        >
            <li v-for="row in rows" :key="row.key" class="space-y-1.5">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="flex min-w-0 items-center gap-1.5">
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{
                                backgroundColor: row.color ?? 'currentColor',
                            }"
                            aria-hidden="true"
                        />
                        <span class="truncate text-sm font-medium">
                            {{ row.name }}
                        </span>
                        <Icon
                            v-if="row.masked"
                            name="lock"
                            class="size-4 shrink-0 text-muted-foreground"
                            :aria-label="$t('dashboard.widget.masked')"
                        />
                    </span>
                    <span class="shrink-0 text-sm tabular-nums">
                        <span
                            :class="row.masked ? 'text-muted-foreground' : ''"
                        >
                            {{ spentLabel(row) }}
                        </span>
                        <template v-if="allocatedLabel(row) !== null">
                            <span class="text-muted-foreground">
                                /
                                {{ allocatedLabel(row) }}
                            </span>
                        </template>
                    </span>
                </div>

                <div
                    v-if="progress(row) !== null"
                    class="relative h-2 overflow-hidden rounded-full bg-muted"
                    :title="
                        isOverBudget(row)
                            ? $t('dashboard.widget.overBudget')
                            : undefined
                    "
                >
                    <div
                        class="absolute inset-y-0 rounded-full"
                        :class="
                            isOverBudget(row)
                                ? 'bg-destructive'
                                : isRevenue(row)
                                  ? 'bg-emerald-500'
                                  : 'bg-primary'
                        "
                        :style="{
                            width: `${Math.min(progress(row) ?? 0, 1) * 100}%`,
                            ...(isRevenue(row)
                                ? { insetInlineEnd: 0 }
                                : { insetInlineStart: 0 }),
                        }"
                    />
                </div>

                <p
                    v-else-if="!row.masked"
                    class="text-xs text-muted-foreground"
                >
                    {{ $t('dashboard.widget.noBudget') }}
                </p>
            </li>
        </ul>
    </div>
</template>
