<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
        draggable="true"
        :aria-label="title"
        class="group/card relative flex h-full flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-card transition-[box-shadow,translate] duration-200 ease-out outline-none focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background hover:-translate-y-px hover:shadow-card-hover"
        @dragstart="onDragStart"
        @dragend="onDragEnd"
    >
        <span
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 top-0 h-px bg-linear-to-r from-transparent via-border/80 to-transparent"
        />

        <header class="flex items-center gap-1 px-3 pt-2.5">
            <button
                type="button"
                class="-ml-1 cursor-grab touch-none rounded-md p-1.5 text-muted-foreground/60 transition-colors hover:bg-muted/70 hover:text-foreground active:cursor-grabbing active:bg-muted active:text-foreground"
                :aria-label="`${t('dashboard.widget.drag')} — ${title}`"
                :title="t('dashboard.widget.drag')"
                @mousedown="onHandleDown"
            >
                <Icon name="drag-indicator" class="size-4" aria-hidden="true" />
            </button>

            <h2
                class="min-w-0 flex-1 truncate text-[13px] leading-5 font-medium tracking-[-0.01em] text-foreground"
            >
                {{ title }}
            </h2>

            <Button
                variant="ghost"
                size="icon-sm"
                class="text-muted-foreground/70 opacity-0 transition-opacity group-focus-within/card:opacity-100 group-hover/card:opacity-100 hover:text-foreground focus-visible:opacity-100 pointer-coarse:opacity-100"
                :aria-label="t('dashboard.widget.settings')"
                @click="emit('settings')"
            >
                <Icon name="settings" class="size-4" aria-hidden="true" />
            </Button>
        </header>

        <div class="min-h-0 flex-1 px-3.5 pt-1 pb-3.5">
            <slot />
        </div>
    </article>
</template>
