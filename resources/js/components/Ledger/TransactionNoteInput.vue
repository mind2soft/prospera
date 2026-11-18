<script setup lang="ts">
import { computed, nextTick, ref, useId, useTemplateRef } from 'vue';
import { Label } from '@/components/ui/label';
import { NOTE_MAX_LENGTH } from '@/lib/ledgerApi';

const props = withDefaults(
    defineProps<{
        id: string;
        /** Recent notes of the selected envelope, newest first. */
        suggestions?: string[];
        rows?: number;
        disabled?: boolean;
    }>(),
    { suggestions: () => [], rows: 1, disabled: false },
);

const model = defineModel<string>({ required: true });

/**
 * The optional transaction note (issue #121) with autocomplete from the
 * envelope's recent notes. The field stays a native textarea; matching
 * suggestions are listed under it in an overlay (no layout shift) while it
 * has focus. ArrowDown at the end of the text moves into the list, arrows
 * move within it, Enter or a click picks a note and returns to the field,
 * Escape closes the list. The list items are not in the tab order, so Tab
 * still goes straight to the next form control.
 */

const listId = useId();
const hintId = useId();
const counterId = useId();

const field = useTemplateRef<HTMLTextAreaElement>('field');
const list = useTemplateRef<HTMLUListElement>('list');
const expanded = ref(false);

const matches = computed(() => {
    const query = model.value.trim().toLocaleLowerCase();
    return props.suggestions.filter((suggestion) => {
        const candidate = suggestion.toLocaleLowerCase();
        return candidate !== query && candidate.includes(query);
    });
});

const showList = computed(
    () => expanded.value && !props.disabled && matches.value.length > 0,
);

const remaining = computed(() => NOTE_MAX_LENGTH - model.value.length);

const options = (): HTMLButtonElement[] =>
    Array.from(list.value?.querySelectorAll('button') ?? []);

const focusOption = (index: number) => {
    const all = options();
    all[(index + all.length) % all.length]?.focus();
};

const onFieldKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && showList.value) {
        event.preventDefault();
        event.stopPropagation();
        expanded.value = false;
        return;
    }
    const target = event.target as HTMLTextAreaElement;
    if (
        event.key === 'ArrowDown' &&
        target.selectionStart === target.value.length
    ) {
        expanded.value = true;
        if (matches.value.length > 0) {
            event.preventDefault();
            void nextTick().then(() => focusOption(0));
        }
    }
};

const onOptionKeydown = (event: KeyboardEvent, index: number) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        focusOption(index + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (index === 0) {
            field.value?.focus();
        } else {
            focusOption(index - 1);
        }
    } else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        expanded.value = false;
        field.value?.focus();
    }
};

const pick = (suggestion: string) => {
    model.value = suggestion.slice(0, NOTE_MAX_LENGTH);
    expanded.value = false;
    field.value?.focus();
};

/** Closes the list once focus leaves both the field and the list. */
const onFocusOut = (event: FocusEvent) => {
    const container = event.currentTarget as HTMLElement;
    if (!container.contains(event.relatedTarget as Node | null)) {
        expanded.value = false;
    }
};
</script>

<template>
    <div class="grid gap-1.5">
        <div class="flex items-baseline justify-between gap-2">
            <Label :for="id">
                {{ $t('ledger.form.note') }}
                <span class="font-normal text-muted-foreground">{{
                    $t('ledger.form.optional')
                }}</span>
            </Label>
            <span
                :id="counterId"
                class="text-xs leading-none text-muted-foreground tabular-nums"
                :class="{ 'text-destructive': remaining <= 10 }"
                data-testid="note-counter"
            >
                {{
                    $t('ledger.form.noteCounter', {
                        count: model.length,
                        max: NOTE_MAX_LENGTH,
                    })
                }}
            </span>
        </div>
        <div class="relative" @focusout="onFocusOut">
            <textarea
                :id="id"
                ref="field"
                v-model="model"
                :maxlength="NOTE_MAX_LENGTH"
                :rows="rows"
                :disabled="disabled"
                :placeholder="$t('ledger.form.notePlaceholder')"
                :aria-describedby="
                    matches.length > 0 ? `${counterId} ${hintId}` : counterId
                "
                class="block min-h-9 w-full resize-y rounded-md border border-input bg-background px-3 py-1.5 text-sm leading-5 shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                @focus="expanded = true"
                @input="expanded = true"
                @keydown="onFieldKeydown"
            />
            <span v-if="matches.length > 0" :id="hintId" class="sr-only">{{
                $t(
                    'ledger.form.noteSuggestionsHint',
                    { count: matches.length },
                    matches.length,
                )
            }}</span>
            <div
                v-if="showList"
                class="absolute inset-x-0 top-full z-30 mt-1 rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                data-testid="note-suggestions"
            >
                <p class="px-2 py-1 text-xs text-muted-foreground">
                    {{ $t('ledger.form.noteSuggestions') }}
                </p>
                <ul
                    :id="listId"
                    ref="list"
                    class="max-h-48 overflow-y-auto"
                    :aria-label="$t('ledger.form.noteSuggestions')"
                >
                    <li
                        v-for="(suggestion, index) in matches"
                        :key="suggestion"
                    >
                        <button
                            type="button"
                            tabindex="-1"
                            class="w-full rounded-sm px-2 py-1.5 text-left text-sm wrap-break-word hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:text-accent-foreground focus-visible:outline-none"
                            @mousedown.prevent
                            @keydown="onOptionKeydown($event, index)"
                            @click="pick(suggestion)"
                        >
                            {{ suggestion }}
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
