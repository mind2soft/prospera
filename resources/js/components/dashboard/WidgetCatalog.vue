<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { DashboardWidgetType } from '@/types';

const props = withDefaults(
    defineProps<{
        disabled?: boolean;
        /** "icon" = compact "+" button (page header); "labelled" = CTA with text (empty state). */
        variant?: 'icon' | 'labelled';
    }>(),
    {
        variant: 'icon',
    },
);

const emit = defineEmits<{
    add: [type: DashboardWidgetType];
}>();

const CATALOG: DashboardWidgetType[] = [
    'envelope_spending',
    'income_expenses',
    'radial',
];

const CATALOG_ICONS: Record<DashboardWidgetType, string> = {
    envelope_spending: 'table_chart',
    income_expenses: 'bar_chart',
    radial: 'donut_large',
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                v-if="props.variant === 'labelled'"
                variant="outline"
                :disabled="disabled"
            >
                <Icon name="add" class="size-5" aria-hidden="true" />
                {{ $t('dashboard.empty.add') }}
            </Button>
            <Button
                v-else
                variant="outline"
                size="icon"
                :disabled="disabled"
                :aria-label="$t('dashboard.add')"
                :title="$t('dashboard.add')"
            >
                <Icon name="add" class="size-5" aria-hidden="true" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuItem
                v-for="type in CATALOG"
                :key="type"
                class="items-start gap-3 py-2.5"
                @select="emit('add', type)"
            >
                <span
                    class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-muted"
                >
                    <Icon
                        :name="CATALOG_ICONS[type]"
                        class="size-4 text-foreground"
                        aria-hidden="true"
                    />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-medium">
                        {{ $t(`dashboard.catalog.${type}.name`) }}
                    </span>
                    <span class="block text-xs text-muted-foreground">
                        {{ $t(`dashboard.catalog.${type}.description`) }}
                    </span>
                </span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
