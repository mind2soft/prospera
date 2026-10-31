<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/budget';

const props = defineProps<{
    amount: number;
    currency: string;
}>();

const isNegative = computed(() => props.amount < 0);

const display = computed(() =>
    isNegative.value
        ? `(${formatMoney(props.amount, props.currency)})`
        : formatMoney(props.amount, props.currency),
);
</script>

<template>
    <span :class="isNegative ? 'text-destructive' : ''" class="tabular-nums">{{
        display
    }}</span>
</template>
