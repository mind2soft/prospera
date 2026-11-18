<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import Icon from '@/components/Icon.vue';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';

defineProps<{ note: string }>();

/**
 * The note of a ledger row, disclosed from its amount (issue #121). The
 * amount is the trigger: hovering (mouse) or focusing it previews the note,
 * clicking or tapping pins it open until a second activation, Escape or an
 * outside tap. The popover is portalled and collision-aware, so it never
 * resizes the table row nor gets clipped by the scrolling table region.
 * The note is also the trigger's accessible description: screen readers
 * announce it on focus without entering the popover.
 */

const HOVER_CLOSE_DELAY_MS = 150;

const descriptionId = useId();
const trigger = ref<HTMLButtonElement | null>(null);

const hovered = ref(false);
const focused = ref(false);
const pinned = ref(false);
/** Closed explicitly (Escape, outside tap): stays closed until re-engaged. */
const dismissed = ref(false);

const open = computed(
    () => !dismissed.value && (pinned.value || hovered.value || focused.value),
);

let hoverTimer: ReturnType<typeof setTimeout> | undefined;

const clearHoverTimer = () => {
    if (hoverTimer !== undefined) {
        clearTimeout(hoverTimer);
        hoverTimer = undefined;
    }
};

const onPointerEnter = (event: PointerEvent) => {
    if (event.pointerType !== 'mouse') {
        return;
    }
    clearHoverTimer();
    hovered.value = true;
};

/** A short grace period lets the pointer travel onto the popover itself. */
const onPointerLeave = (event: PointerEvent) => {
    if (event.pointerType !== 'mouse') {
        return;
    }
    clearHoverTimer();
    hoverTimer = setTimeout(() => {
        hovered.value = false;
        dismissed.value = false;
    }, HOVER_CLOSE_DELAY_MS);
};

const onFocus = () => {
    focused.value = true;
};

const onBlur = () => {
    focused.value = false;
    dismissed.value = false;
};

const onActivate = () => {
    if (open.value && pinned.value) {
        pinned.value = false;
        dismissed.value = true;
        return;
    }
    pinned.value = true;
    dismissed.value = false;
};

const onOpenChange = (value: boolean) => {
    if (!value) {
        pinned.value = false;
        dismissed.value = true;
    }
};

/** Activating the trigger toggles the note; it is not an outside tap. */
const onInteractOutside = (event: Event) => {
    const target = (event as CustomEvent<{ originalEvent: Event }>).detail
        ?.originalEvent?.target;
    if (target instanceof Node && trigger.value?.contains(target)) {
        event.preventDefault();
    }
};

onBeforeUnmount(clearHoverTimer);
</script>

<template>
    <Popover :open="open" @update:open="onOpenChange">
        <PopoverAnchor as-child>
            <button
                ref="trigger"
                type="button"
                class="inline-flex min-h-7 items-center gap-1 rounded-sm px-1 underline decoration-dotted decoration-1 underline-offset-4 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="open"
                :aria-describedby="descriptionId"
                data-testid="transaction-note-trigger"
                @pointerenter="onPointerEnter"
                @pointerleave="onPointerLeave"
                @focus="onFocus"
                @blur="onBlur"
                @click="onActivate"
            >
                <Icon
                    name="sticky-note-2"
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <slot />
                <span class="sr-only">{{ $t('ledger.table.hasNote') }}</span>
            </button>
        </PopoverAnchor>
        <span :id="descriptionId" class="sr-only">{{ note }}</span>
        <PopoverContent
            side="top"
            align="end"
            :collision-padding="8"
            class="w-max max-w-[min(20rem,calc(100vw-1rem))] p-3"
            data-testid="transaction-note"
            @open-auto-focus.prevent
            @close-auto-focus.prevent
            @interact-outside="onInteractOutside"
            @pointerenter="onPointerEnter"
            @pointerleave="onPointerLeave"
        >
            <p
                class="max-h-48 overflow-y-auto text-left text-sm wrap-break-word whitespace-pre-wrap"
                aria-hidden="true"
            >
                {{ note }}
            </p>
        </PopoverContent>
    </Popover>
</template>
