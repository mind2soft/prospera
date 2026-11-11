<!-- biome-ignore-all lint/a11y/noNoninteractiveTabindex: the table scroll region must be keyboard-focusable to scroll (WCAG 2.1.1) -->
<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import Currency from '@/components/Currency.vue';
import EnvelopeChip from '@/components/EnvelopeChip.vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/composables/useLocale';
import { formatMoney } from '@/lib/budget';
import type { BudgetEnvelope } from '@/types';

const props = defineProps<{
    envelopes: BudgetEnvelope[];
    currency: string;
    editable?: boolean;
    pendingId?: number | null;
    /** Autosave status of the amount edits. */
    saveStatus?: 'idle' | 'saving' | 'saved' | 'error';
}>();

const { locale } = useLocale();

const emit = defineEmits<{
    (e: 'add'): void;
    (e: 'update-amount', envelopeId: number, amount: number): void;
    (e: 'remove', envelopeId: number): void;
}>();

const edits = reactive<Record<number, string>>({});

watch(
    () => props.envelopes,
    (rows) => {
        const present = new Set<number>();
        for (const row of rows) {
            present.add(row.envelope_id);
            if (!(row.envelope_id in edits)) {
                edits[row.envelope_id] = String(Math.abs(row.amount));
            }
        }
        for (const key of Object.keys(edits)) {
            if (!present.has(Number(key))) {
                delete edits[Number(key)];
            }
        }
    },
    { immediate: true },
);

const isValidAmount = (value: string): boolean => {
    const parsed = Number(value);
    return (
        value !== '' &&
        !Number.isNaN(parsed) &&
        Number.isInteger(parsed) &&
        parsed >= 1
    );
};

/**
 * Live edit: applied locally on every valid keystroke; the parent
 * optimistic-updates the budget so the bars and totals follow along, and
 * persists the change (debounced) without waiting for a blur.
 */
const onAmountInput = (row: BudgetEnvelope, value: string) => {
    edits[row.envelope_id] = value;
    if (!isValidAmount(value)) {
        return;
    }
    if (Number(value) !== Math.abs(row.amount)) {
        emit('update-amount', row.envelope_id, Number(value));
    }
};

/**
 * An invalid edit (empty / non-integer / non-positive) is not saved: it stays
 * in the field with an inline message until fixed; Escape restores the saved
 * amount.
 */
const isInvalid = (row: BudgetEnvelope): boolean =>
    !isValidAmount(edits[row.envelope_id] ?? '');

const revert = (row: BudgetEnvelope) => {
    edits[row.envelope_id] = String(Math.abs(row.amount));
};

const errorId = (row: BudgetEnvelope): string =>
    `budget-amount-${row.envelope_id}-error`;

const ordered = computed(() =>
    [...props.envelopes].sort((a, b) =>
        a.direction === b.direction ? 0 : a.direction === 'revenue' ? -1 : 1,
    ),
);

const hasVariable = computed(() =>
    props.envelopes.some((e) => e.type === 'variable'),
);

const totalIncome = computed(() =>
    props.envelopes
        .filter((e) => e.direction === 'revenue')
        .reduce((sum, e) => sum + Math.abs(e.amount), 0),
);
const totalExpenses = computed(() =>
    props.envelopes
        .filter((e) => e.direction === 'expense')
        .reduce((sum, e) => sum + Math.abs(e.amount), 0),
);
const remaining = computed(() => totalIncome.value - totalExpenses.value);

const runningLeft = computed(() => {
    let left = totalIncome.value;
    return ordered.value.map((row) => {
        if (row.direction === 'revenue') {
            return null;
        }
        left -= Math.abs(row.amount);
        return left;
    });
});
</script>

<template>
    <section class="space-y-2">
        <div v-if="editable" class="flex items-center justify-end gap-3">
            <p
                class="mr-auto flex items-center gap-1.5 text-xs"
                :class="
                    saveStatus === 'error'
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
                role="status"
                data-testid="budget-save-status"
            >
                <template v-if="saveStatus === 'saving'">
                    <Spinner class="size-3.5" aria-hidden="true" />
                    {{ $t('budget.table.saving') }}
                </template>
                <template v-else-if="saveStatus === 'saved'">
                    <Icon name="check" class="size-3.5" aria-hidden="true" />
                    {{ $t('budget.table.saved') }}
                </template>
                <template v-else-if="saveStatus === 'error'">
                    <Icon name="warning" class="size-3.5" aria-hidden="true" />
                    {{ $t('budget.table.notSaved') }}
                </template>
            </p>
            <Button
                variant="default"
                size="sm"
                class="gap-1.5"
                @click="emit('add')"
            >
                <Icon name="add" class="size-4" aria-hidden="true" />
                {{ $t('budget.table.addEnvelope') }}
            </Button>
        </div>

        <section
            class="overflow-x-auto rounded-lg border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="$t('budget.table.label')"
            tabindex="0"
        >
            <table class="w-full min-w-lg text-sm">
                <thead>
                    <tr
                        class="border-b bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <th class="px-4 py-2.5 font-medium">
                            {{ $t('budget.table.envelope') }}
                        </th>
                        <th class="px-4 py-2.5 font-medium">
                            {{ $t('budget.table.type') }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{ $t('budget.table.amount') }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{ $t('budget.table.leftToSpend') }}
                        </th>
                        <th v-if="editable" class="w-12 px-4 py-2.5">
                            <span class="sr-only">{{
                                $t('budget.table.actions')
                            }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in ordered"
                        :key="row.envelope_id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-2.5">
                            <EnvelopeChip
                                :name="row.name"
                                :color="row.color"
                                :direction="row.direction"
                            />
                        </td>
                        <td class="px-4 py-2.5 text-muted-foreground">
                            {{ $t(`budget.addEnvelope.${row.type}`) }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <template v-if="editable">
                                <Input
                                    :model-value="edits[row.envelope_id]"
                                    :aria-label="
                                        $t('budget.table.amountFor', {
                                            name: row.name,
                                        })
                                    "
                                    :aria-invalid="isInvalid(row) || undefined"
                                    :aria-describedby="
                                        isInvalid(row)
                                            ? errorId(row)
                                            : undefined
                                    "
                                    type="number"
                                    min="1"
                                    step="1"
                                    class="ml-auto h-7 w-24 text-right tabular-nums"
                                    @update:model-value="
                                        onAmountInput(row, String($event))
                                    "
                                    @keydown.escape="revert(row)"
                                    @keydown.enter="
                                        (
                                            $event.target as HTMLInputElement
                                        ).blur()
                                    "
                                />
                                <p
                                    v-if="isInvalid(row)"
                                    :id="errorId(row)"
                                    class="mt-1 text-xs text-destructive"
                                    role="alert"
                                >
                                    {{ $t('budget.table.invalidAmount') }}
                                </p>
                            </template>
                            <template v-else>{{
                                formatMoney(row.amount, currency, locale)
                            }}</template>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-muted-foreground"
                        >
                            <Currency
                                v-if="runningLeft[index] !== null"
                                :amount="runningLeft[index] as number"
                                :currency="currency"
                            />
                        </td>
                        <td v-if="editable" class="px-4 py-2.5 text-right">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="
                                    $t('budget.table.removeEnvelopeFor', {
                                        name: row.name,
                                    })
                                "
                                :disabled="pendingId === row.envelope_id"
                                @click="emit('remove', row.envelope_id)"
                            >
                                <Icon
                                    name="delete"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="ordered.length === 0">
                        <td
                            :colspan="editable ? 5 : 4"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            {{ $t('budget.table.empty') }}
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="ordered.length > 0">
                    <tr class="border-t bg-muted/50">
                        <td class="px-4 py-2.5 font-medium" colspan="3">
                            <span
                                class="inline-flex items-center gap-1.5"
                                :class="
                                    remaining < 0
                                        ? 'text-destructive'
                                        : 'text-success'
                                "
                            >
                                <Icon
                                    :name="
                                        remaining < 0
                                            ? 'warning'
                                            : 'thumb-up-outline'
                                    "
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{
                                    remaining < 0
                                        ? $t('budget.table.overspending')
                                        : $t('budget.table.leftOver')
                                }}
                            </span>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right font-medium"
                            colspan="2"
                        >
                            <Currency
                                :amount="remaining"
                                :currency="currency"
                            />
                        </td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <p
            v-if="hasVariable"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <Icon name="info" class="size-3.5 shrink-0" aria-hidden="true" />
            {{ $t('budget.table.variableNote') }}
        </p>
    </section>
</template>
