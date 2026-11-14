<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import { REQUIRED_EXPENSE, REQUIRED_REVENUE } from '@/lib/onboardingJourney';

const props = withDefaults(
    defineProps<{
        revenue: number;
        expense: number;
        requiredRevenue?: number;
        requiredExpense?: number;
    }>(),
    { requiredRevenue: REQUIRED_REVENUE, requiredExpense: REQUIRED_EXPENSE },
);

const rows = computed(() => [
    {
        key: 'revenue',
        icon: 'north' as const,
        label: 'onboarding.slots.comingIn',
        aria: 'onboarding.slots.revenueLabel',
        have: props.revenue,
        need: props.requiredRevenue,
    },
    {
        key: 'expense',
        icon: 'south' as const,
        label: 'onboarding.slots.goingOut',
        aria: 'onboarding.slots.expenseLabel',
        have: props.expense,
        need: props.requiredExpense,
    },
]);
</script>

<template>
    <div class="grid gap-2">
        <fieldset
            v-for="row in rows"
            :key="row.key"
            class="m-0 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 border-0 p-0 text-sm"
            :aria-label="
                $t(row.aria, {
                    have: Math.min(row.have, row.need),
                    need: row.need,
                })
            "
        >
            <span
                class="inline-flex w-24 items-center gap-1.5 text-muted-foreground"
                aria-hidden="true"
            >
                <Icon :name="row.icon" class="size-4 shrink-0" />
                {{ $t(row.label) }}
            </span>
            <span class="flex items-center gap-1.5" aria-hidden="true">
                <svg
                    v-for="slot in row.need"
                    :key="slot"
                    viewBox="0 0 22 16"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                    focusable="false"
                    class="h-4 w-[22px] transition-opacity duration-150"
                >
                    <template v-if="slot <= row.have">
                        <rect
                            x="1"
                            y="1"
                            width="20"
                            height="14"
                            rx="2.5"
                            class="fill-brand/20 stroke-brand"
                            stroke-width="1.5"
                        />
                        <path
                            d="M1.5 2.5 11 9l9.5-6.5"
                            class="stroke-brand"
                            stroke-width="1.5"
                        />
                        <path
                            d="m14.5 11 1.5 1.5 3-3"
                            class="stroke-foreground"
                            stroke-width="1.6"
                        />
                    </template>
                    <template v-else>
                        <rect
                            x="1"
                            y="1"
                            width="20"
                            height="14"
                            rx="2.5"
                            class="stroke-muted-foreground"
                            stroke-width="1.5"
                            stroke-dasharray="2 3"
                        />
                    </template>
                </svg>
            </span>
            <span class="tabular-nums" aria-hidden="true">
                {{
                    $t('onboarding.slots.count', {
                        have: Math.min(row.have, row.need),
                        need: row.need,
                    })
                }}
                <span
                    v-if="row.have > row.need"
                    class="ms-1 text-muted-foreground"
                    >{{
                        $t('onboarding.slots.extra', { n: row.have - row.need })
                    }}</span
                >
            </span>
        </fieldset>
    </div>
</template>
