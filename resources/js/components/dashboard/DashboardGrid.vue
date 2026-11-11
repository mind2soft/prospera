<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import WidgetCard from '@/components/dashboard/WidgetCard.vue';
import type { DashboardWidgetConfig } from '@/types';

const props = defineProps<{
    widgets: DashboardWidgetConfig[];
}>();

const emit = defineEmits<{
    /** The widget ids in their new display order after a drag & drop. */
    reorder: [ids: number[]];
    settings: [widget: DashboardWidgetConfig];
}>();

const container = ref<HTMLElement | null>(null);
const { width } = useElementSize(container);

/**
 * Responsive columns: 1 at `xs` up to 5 at `xl` (docs/dashboard.md).
 * Server-rendered pages assume `xl` (5) and correct after the first client
 * measurement — the initial render matches SSR, so hydration is safe.
 */
const columns = computed(() => {
    const w = width.value;

    if (w === 0) {
        return 5;
    }

    if (w >= 1100) {
        return 5;
    }

    if (w >= 1024) {
        return 4;
    }

    if (w >= 768) {
        return 3;
    }

    if (w >= 640) {
        return 2;
    }

    return 1;
});

/** A widget never spans more than the viewport's column count. */
const spanOf = (widget: DashboardWidgetConfig): number =>
    Math.min(widget.column_width, columns.value);

const { t } = useI18n();

/** Polite announcement of keyboard moves ("Moved to position 2 of 5"). */
const announcement = ref('');

const titleOf = (widget: DashboardWidgetConfig): string =>
    widget.title ?? t(`dashboard.widget.defaultTitle.${widget.type}`);

const onKeyboardMove = async (
    widget: DashboardWidgetConfig,
    move: 'earlier' | 'later' | 'first' | 'last',
): Promise<void> => {
    const ids = props.widgets.map((w) => w.id ?? 0);
    const from = ids.indexOf(widget.id ?? 0);
    const last = ids.length - 1;
    const to = {
        earlier: Math.max(0, from - 1),
        later: Math.min(last, from + 1),
        first: 0,
        last,
    }[move];

    if (from === -1 || to === from) {
        return;
    }

    ids.splice(from, 1);
    ids.splice(to, 0, widget.id ?? 0);
    emit('reorder', ids);

    announcement.value = t('dashboard.widget.moved', {
        title: titleOf(widget),
        position: to + 1,
        count: ids.length,
    });

    // The card re-renders at its new place: keep focus on its handle.
    await nextTick();
    container.value
        ?.querySelector<HTMLElement>(
            `[data-widget-id="${widget.id}"] [data-reorder-handle]`,
        )
        ?.focus();
};

const draggingId = ref<number | null>(null);

const onCardDragStart = (widget: DashboardWidgetConfig): void => {
    draggingId.value = widget.id ?? null;
};

const onCardDragEnd = (): void => {
    draggingId.value = null;
};

const onCardDragOver = (
    event: DragEvent,
    target: DashboardWidgetConfig,
): void => {
    const draggedId = draggingId.value;
    const targetId = target.id;

    if (draggedId === null || targetId === null || draggedId === targetId) {
        return;
    }

    event.preventDefault();

    if (!(event.target instanceof HTMLElement) || !container.value) {
        return;
    }

    const targetCard = event.target.closest('[data-widget-card]');

    if (!(targetCard instanceof HTMLElement)) {
        return;
    }

    const from = props.widgets.findIndex((w) => w.id === draggedId);
    const to = props.widgets.findIndex((w) => w.id === targetId);

    if (from === -1 || to === -1) {
        return;
    }

    // In RTL the DOM order flows right-to-left, so the pointer half of the
    // target maps to the opposite insertion side.
    const rtl =
        container.value.parentElement !== null &&
        getComputedStyle(container.value.parentElement).direction === 'rtl';

    const beforePointer =
        event.clientX <
        targetCard.getBoundingClientRect().left +
            targetCard.getBoundingClientRect().width / 2;

    const insertBefore = rtl ? !beforePointer : beforePointer;

    const targetIndex = insertBefore
        ? to > from
            ? to - 1
            : to
        : to > from
          ? to
          : to + 1;

    if (targetIndex === from) {
        return;
    }

    const ids = props.widgets.map((w) => w.id ?? 0);
    ids.splice(from, 1);
    ids.splice(targetIndex, 0, draggedId);

    emit('reorder', ids);
};
</script>

<template>
    <ul
        ref="container"
        class="grid list-none gap-4"
        :style="{
            gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
        }"
    >
        <li
            v-for="widget in widgets"
            :key="widget.id"
            data-widget-card
            :data-widget-id="widget.id"
            :class="widget.id === draggingId ? 'opacity-50' : ''"
            :style="{ gridColumn: `span ${spanOf(widget)}` }"
            @dragover="onCardDragOver($event, widget)"
            @drop.prevent
        >
            <WidgetCard
                :widget="widget"
                @drag-start="onCardDragStart(widget)"
                @drag-end="onCardDragEnd"
                @move="onKeyboardMove(widget, $event)"
                @settings="emit('settings', widget)"
            >
                <template #default>
                    <slot name="widget" :widget="widget" />
                </template>
            </WidgetCard>
        </li>
    </ul>
    <p class="sr-only" aria-live="polite" data-testid="reorder-announcement">
        {{ announcement }}
    </p>
</template>
