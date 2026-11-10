<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import type { DashboardWidgetConfig } from '@/types';

const props = defineProps<{
    widget: DashboardWidgetConfig;
}>();

const emit = defineEmits<{
    settings: [];
    /** Raised when a drag starts from the card's handle. */
    'drag-start': [];
    'drag-end': [];
}>();

const { t } = useI18n();

/** Free-text title, else the localized default title for the type. */
const title = computed(
    () =>
        props.widget.title ??
        t(`dashboard.widget.defaultTitle.${props.widget.type}`),
);

const card = useTemplateRef<HTMLElement>('card');

/**
 * The card element is always `draggable` (Chromium snapshots the attribute
 * at mousedown — toggling it from the handle's handler is too late), but a
 * drag is only allowed when it was initiated on the handle: `dragstart`
 * from anywhere else is cancelled, so normal content interaction keeps
 * working.
 */
const dragArmed = ref(false);

const onHandleDown = (down: MouseEvent): void => {
    if (down.button === 0) {
        dragArmed.value = true;
    }
};

const onDragStart = (event: DragEvent): void => {
    if (!dragArmed.value) {
        event.preventDefault();

        return;
    }

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(props.widget.id ?? ''));
    }

    emit('drag-start');
};

const onDragEnd = (): void => {
    dragArmed.value = false;
    emit('drag-end');
};

const onWindowMouseUp = (): void => {
    dragArmed.value = false;
};

onMounted(() => window.addEventListener('mouseup', onWindowMouseUp));

onBeforeUnmount(() => window.removeEventListener('mouseup', onWindowMouseUp));
</script>

<template>
    <article
        ref="card"
        draggable="true"
        :aria-label="title"
        class="group/card flex h-full flex-col rounded-xl border bg-card text-card-foreground shadow-sm transition-shadow hover:shadow-md"
        @dragstart="onDragStart"
        @dragend="onDragEnd"
    >
        <header class="flex items-center gap-1 px-3 pt-2.5">
            <button
                type="button"
                class="cursor-grab touch-none rounded p-1 text-muted-foreground opacity-60 transition-opacity hover:opacity-100 active:cursor-grabbing"
                :aria-label="`${t('dashboard.widget.drag')} — ${title}`"
                :title="t('dashboard.widget.drag')"
                @mousedown="onHandleDown"
            >
                <Icon name="drag_indicator" class="size-4" aria-hidden="true" />
            </button>

            <h3 class="min-w-0 flex-1 truncate text-sm font-semibold">
                {{ title }}
            </h3>

            <Button
                variant="ghost"
                size="icon-sm"
                class="text-muted-foreground opacity-0 transition-opacity group-hover/card:opacity-100 focus-visible:opacity-100"
                :aria-label="t('dashboard.widget.settings')"
                @click="emit('settings')"
            >
                <Icon name="settings" class="size-4" aria-hidden="true" />
            </Button>
        </header>

        <div class="min-h-0 flex-1 p-3 pt-1.5">
            <slot />
        </div>
    </article>
</template>
