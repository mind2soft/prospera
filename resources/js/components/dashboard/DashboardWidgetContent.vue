<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useWidgetData } from '@/composables/dashboardQueries';
import type { DashboardWidgetConfig } from '@/types';

// The chart widgets (Unovis) are lazy chunks — the dashboard bundle carries
// no charting library for any other page.
const EnvelopeSpendingWidget = defineAsyncComponent(
    () => import('@/components/dashboard/widgets/EnvelopeSpendingWidget.vue'),
);
const IncomeExpensesWidget = defineAsyncComponent(
    () => import('@/components/dashboard/widgets/IncomeExpensesWidget.vue'),
);
const RadialWidget = defineAsyncComponent(
    () => import('@/components/dashboard/widgets/RadialWidget.vue'),
);

const props = defineProps<{
    widget: DashboardWidgetConfig;
    currency: string;
}>();

/**
 * The aggregate endpoint exists only for saved widgets (positive server
 * ids); freshly added ones render as skeletons until the autosave lands.
 */
const { data, isPending, isError, refetch } = useWidgetData(
    computed(() => ((props.widget.id ?? 0) > 0 ? props.widget : null)),
);
</script>

<template>
    <div v-if="isPending" class="flex flex-col gap-2.5">
        <Skeleton class="h-4 w-3/4" />
        <Skeleton class="h-4 w-1/2" />
        <Skeleton class="h-2 w-full" />
        <Skeleton class="h-4 w-2/3" />
        <Skeleton class="h-2 w-full" />
    </div>

    <p
        v-else-if="isError"
        class="flex h-full flex-col items-center justify-center gap-2 text-center text-sm text-muted-foreground"
    >
        {{ $t('dashboard.widget.error') }}
        <Button variant="outline" size="sm" @click="() => refetch()">
            {{ $t('dashboard.widget.retry') }}
        </Button>
    </p>

    <EnvelopeSpendingWidget
        v-else-if="data?.type === 'envelope_spending'"
        :data="data"
        :currency="currency"
    />

    <IncomeExpensesWidget
        v-else-if="data?.type === 'income_expenses'"
        :data="data"
        :view-type="widget.view_type"
        :currency="currency"
    />

    <RadialWidget
        v-else-if="data?.type === 'radial'"
        :data="data"
        :view-type="widget.view_type"
        :currency="currency"
    />

    <span v-else class="text-sm text-muted-foreground">
        {{ $t('dashboard.widget.emptySelection') }}
    </span>
</template>
