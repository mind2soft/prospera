<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/budget';

export type BudgetBarSegment = {
    id: number;
    name: string;
    color: string;
    amount: number;
};

const props = defineProps<{
    title: string;
    segments: BudgetBarSegment[];
    max: number;
    currency?: string;
}>();

const total = computed(() =>
    props.segments.reduce((sum, s) => sum + s.amount, 0),
);

const positions = computed(() => {
    let offset = 0;
    const segments = [...props.segments]
        .filter((segment) => segment.amount > 0)
        .sort((a, b) => b.amount - a.amount);
    return segments.map((segment) => {
        const widthPct = (segment.amount / props.max) * 100;
        const position = { ...segment, widthPct, offsetPct: offset };
        offset += widthPct;
        return position;
    });
});

const fmt = (amount: number) =>
    props.currency
        ? formatMoney(amount, props.currency)
        : amount.toLocaleString();
</script>

<template>
    <section>
        <div class="mb-2 flex items-baseline justify-between gap-4">
            <h3 class="text-sm font-medium text-muted-foreground">
                {{ title }}
            </h3>
            <span class="text-sm font-medium tabular-nums">{{
                fmt(total)
            }}</span>
        </div>

        <div
            class="flex h-3 w-full items-center overflow-hidden rounded-full bg-muted"
            :class="total === 0 && 'border border-dashed border-border'"
        >
            <div
                v-for="segment in positions"
                :key="segment.id"
                class="h-full border-r-2 border-background last:border-r-0"
                :style="{
                    width: `${segment.widthPct}%`,
                    backgroundColor: segment.color,
                }"
                :title="`${segment.name} — ${fmt(segment.amount)}`"
            />
        </div>

        <ul
            v-if="positions.length > 0"
            class="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-muted-foreground"
        >
            <li
                v-for="segment in positions"
                :key="segment.id"
                class="flex items-center gap-1.5"
            >
                <span
                    class="size-2 shrink-0 rounded-full"
                    :style="{ backgroundColor: segment.color }"
                    aria-hidden="true"
                />
                <span class="max-w-40 truncate">{{ segment.name }}</span>
                <span class="tabular-nums">{{ fmt(segment.amount) }}</span>
            </li>
        </ul>
    </section>
</template>
