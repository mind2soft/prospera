<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { formatMoney } from '@/lib/budget';

const props = defineProps<{
    amount: number;
    currency: string;
}>();

const { locale } = useLocale();

const isNegative = computed(() => props.amount < 0);

const display = computed(() =>
    isNegative.value
        ? `(${formatMoney(props.amount, props.currency, locale.value)})`
        : formatMoney(props.amount, props.currency, locale.value),
);
</script>

<template>
    <span :class="isNegative ? 'text-destructive' : ''" class="tabular-nums">{{
        display
    }}</span>
</template>
