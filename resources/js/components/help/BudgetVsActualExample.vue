<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import { useExampleMoney } from '@/composables/useExampleMoney';
import { EXAMPLES } from '@/lib/helpGuides';

defineProps<{
    /** Without its own card frame (nested in a figure). */ bare?: boolean;
}>();

const money = useExampleMoney();

const rows = computed(() =>
    EXAMPLES.budget.map((row) => {
        const over = row.spent > row.planned;
        const scale = Math.max(row.planned, row.spent);
        return {
            ...row,
            over,
            // Track = the plan; fill = spending, capped at the plan.
            trackWidth: (row.planned / scale) * 100,
            fill: (Math.min(row.spent, row.planned) / row.planned) * 100,
            overflow: over ? ((row.spent - row.planned) / scale) * 100 : 0,
            difference: Math.abs(row.planned - row.spent),
        };
    }),
);
</script>

<template>
    <div class="grid gap-4" :class="!bare && 'rounded-xl border bg-card p-5'">
        <span class="text-xs font-medium text-muted-foreground uppercase">{{
            $t('help.common.example')
        }}</span>
        <ul class="grid gap-4">
            <li
                v-for="row in rows"
                :key="row.key"
                class="grid gap-1.5 sm:grid-cols-[8rem_minmax(0,1fr)_10rem] sm:items-center sm:gap-4"
            >
                <span class="text-sm font-medium">{{
                    $t(`help.budgets.examples.${row.key}`)
                }}</span>
                <span class="flex h-3 items-center" aria-hidden="true">
                    <span
                        class="relative h-3 rounded-full border border-foreground/40"
                        :style="{ width: `${row.trackWidth}%` }"
                    >
                        <span
                            class="absolute inset-y-0 start-0 rounded-full bg-brand"
                            :style="{ width: `${row.fill}%` }"
                        />
                    </span>
                    <template v-if="row.over">
                        <span
                            class="h-5 border-s-2 border-dashed border-foreground/60"
                        />
                        <span
                            class="h-3 rounded-e-full bg-chart-expense"
                            :style="{ width: `${row.overflow}%` }"
                        />
                    </template>
                </span>
                <span
                    class="inline-flex items-center gap-1.5 text-sm tabular-nums sm:justify-end"
                    :class="row.over ? 'text-foreground' : 'text-success'"
                >
                    <Icon
                        :name="row.over ? 'warning' : 'check'"
                        class="size-4 shrink-0"
                        :class="row.over && 'text-warning'"
                        aria-hidden="true"
                    />
                    {{
                        row.over
                            ? $t('help.budgets.sections.plan.over', {
                                  amount: money.format(row.difference),
                              })
                            : $t('help.budgets.sections.plan.leftOver', {
                                  amount: money.format(row.difference),
                              })
                    }}
                    <span class="sr-only">
                        ({{
                            $t('help.budgets.sections.plan.planned', {
                                amount: money.format(row.planned),
                            })
                        }},
                        {{
                            $t('help.budgets.sections.plan.spent', {
                                amount: money.format(row.spent),
                            })
                        }})</span
                    >
                </span>
            </li>
        </ul>
    </div>
</template>
