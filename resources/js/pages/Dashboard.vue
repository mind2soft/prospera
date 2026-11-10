<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue';
import DashboardWidgetContent from '@/components/dashboard/DashboardWidgetContent.vue';
import WidgetCatalog from '@/components/dashboard/WidgetCatalog.vue';
import WidgetSettingsSheet from '@/components/dashboard/WidgetSettingsSheet.vue';
import Heading from '@/components/Heading.vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useBudgets } from '@/composables/budgetQueries';
import {
    useDashboardWidgets,
    useRemoveDashboardWidget,
    useSaveDashboardLayout,
} from '@/composables/dashboardQueries';
import { ApiError } from '@/lib/api';
import { dashboard as dashboardRoute } from '@/routes';
import type {
    DashboardWidgetConfig,
    DashboardWidgetType,
    DashboardWidgetViewType,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'dashboard.title', href: dashboardRoute() }],
    },
});

const { t } = useI18n();

const widgetsQuery = useDashboardWidgets();
// Destructured for the template — refs nested in plain objects do not
// auto-unwrap in templates.
const { isPending: widgetsPending, isError: widgetsError } = widgetsQuery;
const saveLayout = useSaveDashboardLayout();
const removeWidget = useRemoveDashboardWidget();

const budgetsQuery = useBudgets();

/** The latest published budget's currency — budgets are for reporting only. */
const currency = computed(() => {
    const published = (budgetsQuery.data.value ?? [])
        .filter((b) => b.published_at !== null)
        .sort(
            (a, b) =>
                new Date(b.published_at ?? '').getTime() -
                new Date(a.published_at ?? '').getTime(),
        );

    return published[0]?.currency ?? '';
});

const DEFAULT_VIEWS: Record<DashboardWidgetType, DashboardWidgetViewType> = {
    envelope_spending: 'single',
    income_expenses: 'stacked_bars',
    radial: 'donut',
};

/** Local, editable copy of the layout; autosaved (no save buttons). */
const widgets = ref<DashboardWidgetConfig[]>([]);
let tempId = 0;

// Guard flag for programmatic list replacements (initial load, id adoption)
// that must not trigger the autosave watch.
const suppressSave = ref(false);

/** Applies a mutation to the local list without triggering the autosave. */
const withoutAutosave = (apply: () => void): void => {
    suppressSave.value = true;

    apply();

    void nextTick().then(() => {
        suppressSave.value = false;
    });
};

/** The save payload of a widget — existing widgets keep their id. */
const configOf = (widget: DashboardWidgetConfig): DashboardWidgetConfig => ({
    ...((widget.id ?? 0) > 0 ? { id: widget.id } : {}),
    type: widget.type,
    title: widget.title,
    view_type: widget.view_type,
    column_width: widget.column_width,
    period: { ...widget.period },
    selection: widget.selection.map((item) => ({ ...item })),
});

// Resync from the server when the canonical list changes (initial load,
// profile switch, removal invalidation). Skipped while a save is in flight
// so an edit made during the save round-trip is not clobbered, and skipped
// when the incoming layout equals the local one (no redundant autosave).
watch(
    () => widgetsQuery.data.value,
    (data) => {
        if (!data || saveLayout.isLoading.value) {
            return;
        }

        const incoming = data.map((widget) => ({
            ...configOf(widget),
            id: widget.id,
        }));

        const fingerprint = (list: DashboardWidgetConfig[]): string =>
            JSON.stringify(list.map((w) => [w.id, configOf(w)]));

        if (fingerprint(incoming) === fingerprint(widgets.value)) {
            return;
        }

        withoutAutosave(() => {
            widgets.value = incoming;
        });
    },
    { immediate: true },
);

// Debounced autosave on every change — the project convention: no save
// buttons. The full layout is sent; the server assigns ids + order.
const saveNow = (): void => {
    saveLayout.mutate(widgets.value.map(configOf), {
        onSuccess: (data) => {
            // Adopt the server-assigned ids for widgets added this round; a
            // temp id → real id transition must not re-trigger the watcher.
            withoutAutosave(() => {
                data.widgets.forEach((widget, index) => {
                    const local = widgets.value[index];

                    if (local && (local.id ?? 0) <= 0) {
                        local.id = widget.id;
                    }
                });
            });
        },
        onError: (error) => {
            toast.error(
                error instanceof ApiError
                    ? error.firstError
                    : t('dashboard.toast.saveFailed'),
            );
        },
    });
};

const scheduleSave = useDebounceFn(saveNow, 600);

watch(
    widgets,
    () => {
        if (suppressSave.value || widgetsQuery.isPending.value) {
            return;
        }

        scheduleSave();
    },
    { deep: true },
);

// ---- Adding widgets --------------------------------------------------------

const onAdd = (type: DashboardWidgetType): void => {
    widgets.value.push({
        id: --tempId,
        type,
        title: null,
        view_type: DEFAULT_VIEWS[type],
        column_width: 3,
        period: { type: 'months', range: 1 },
        selection: [],
    });

    toast.success(t('dashboard.toast.added'));
};

// ---- Reordering ------------------------------------------------------------

const onReorder = (ids: number[]): void => {
    const byId = new Map(
        widgets.value.map((widget) => [widget.id ?? 0, widget]),
    );

    const reordered = ids
        .map((id) => byId.get(id))
        .filter((widget) => widget !== undefined);

    if (reordered.length !== widgets.value.length) {
        return;
    }

    // Reordering is a discrete action, not a stream of edits: save
    // immediately (a debounced save would be lost if the user navigates or
    // reloads right after dropping the widget).
    withoutAutosave(() => {
        widgets.value = reordered;
    });

    saveNow();
};

// ---- Settings & removal ----------------------------------------------------

const settingsOpen = ref(false);
const settingsWidget = ref<DashboardWidgetConfig | null>(null);

const openSettings = (widget: DashboardWidgetConfig): void => {
    settingsWidget.value = widget;
    settingsOpen.value = true;
};

const onSettingsUpdate = (patch: Partial<DashboardWidgetConfig>): void => {
    if (settingsWidget.value) {
        Object.assign(settingsWidget.value, patch);
    }
};

const confirmRemove = ref(false);

const onRemove = (): void => {
    settingsOpen.value = false;
    confirmRemove.value = true;
};

const onDestroy = (): void => {
    const widget = settingsWidget.value;

    if (widget === null || (widget.id ?? 0) <= 0) {
        // Not saved yet — drop it locally.
        widgets.value = widgets.value.filter((w) => w !== widget);
        confirmRemove.value = false;
        return;
    }

    removeWidget.mutate(widget.id ?? 0, {
        onSuccess: () => {
            confirmRemove.value = false;
            // The canonical list is invalidated by the mutation; keep the
            // UI responsive by removing the row immediately too (without a
            // redundant autosave round-trip).
            withoutAutosave(() => {
                widgets.value = widgets.value.filter((w) => w !== widget);
            });
        },
        onError: () => {
            toast.error(t('dashboard.toast.failed'));
        },
    });
};
</script>

<template>
    <Head :title="$t('dashboard.title')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <header class="flex items-start justify-between gap-4">
            <Heading :title="$t('dashboard.title')" />

            <WidgetCatalog @add="onAdd" />
        </header>

        <!-- Loading -->
        <div
            v-if="widgetsPending && !widgetsError"
            class="grid grid-cols-1 gap-4 md:grid-cols-3 xl:grid-cols-5"
        >
            <Skeleton v-for="i in 3" :key="i" class="h-48 rounded-xl" />
        </div>

        <!-- Empty dashboard: no seeded widgets — the user composes their own. -->
        <Card
            v-else-if="widgets.length === 0"
            class="relative overflow-hidden border-dashed"
        >
            <span
                class="pointer-events-none absolute -top-24 left-1/2 size-64 -translate-x-1/2 rounded-full bg-primary/10 blur-3xl"
                aria-hidden="true"
            />
            <CardContent
                class="relative flex flex-col items-center gap-4 py-16 text-center"
            >
                <span
                    class="flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                >
                    <Icon name="dashboard" class="size-7" aria-hidden="true" />
                </span>
                <div class="space-y-1">
                    <h3 class="text-lg font-semibold">
                        {{ $t('dashboard.empty.title') }}
                    </h3>
                    <p class="mx-auto max-w-md text-sm text-muted-foreground">
                        {{ $t('dashboard.empty.description') }}
                    </p>
                </div>
                <WidgetCatalog variant="labelled" @add="onAdd" />
            </CardContent>
        </Card>

        <!-- Widget grid -->
        <DashboardGrid
            v-else
            :widgets="widgets"
            @reorder="onReorder"
            @settings="openSettings"
        >
            <template #widget="{ widget }">
                <DashboardWidgetContent :widget="widget" :currency="currency" />
            </template>
        </DashboardGrid>

        <!-- Widget settings (right sidebar, same convention as the Share drawer) -->
        <WidgetSettingsSheet
            :open="settingsOpen"
            :widget="settingsWidget"
            @update:open="settingsOpen = $event"
            @update="onSettingsUpdate"
            @remove="onRemove"
        />

        <!-- Removal is destructive → confirmation. -->
        <Dialog :open="confirmRemove" @update:open="confirmRemove = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('dashboard.removeConfirm.title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('dashboard.removeConfirm.description') }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="removeWidget.isLoading.value"
                        @click="confirmRemove = false"
                    >
                        {{ $t('dashboard.removeConfirm.cancel') }}
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="removeWidget.isLoading.value"
                        @click="onDestroy"
                    >
                        {{ $t('dashboard.removeConfirm.confirm') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
