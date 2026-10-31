<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import Icon from '@/components/Icon.vue';
import Currency from '@/components/Currency.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/budget';
import type { BudgetEnveloppe } from '@/types';

const props = defineProps<{
    enveloppes: BudgetEnveloppe[];
    currency: string;
    editable?: boolean;
    pendingId?: number | null;
}>();

const emit = defineEmits<{
    (e: 'add'): void;
    (e: 'update-amount', enveloppeId: number, amount: number): void;
    (e: 'remove', enveloppeId: number): void;
}>();

const edits = reactive<Record<number, string>>({});

watch(
    () => props.enveloppes,
    (rows) => {
        const present = new Set<number>();
        for (const row of rows) {
            present.add(row.enveloppe_id);
            if (!(row.enveloppe_id in edits)) {
                edits[row.enveloppe_id] = String(Math.abs(row.amount));
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
const onAmountInput = (row: BudgetEnveloppe, value: string) => {
    edits[row.enveloppe_id] = value;
    if (!isValidAmount(value)) {
        return;
    }
    if (Number(value) !== Math.abs(row.amount)) {
        emit('update-amount', row.enveloppe_id, Number(value));
    }
};

/**
 * On blur, revert invalid (empty / non-integer / non-positive) edits back to
 * the current amount; valid edits are already applied and being saved.
 */
const revertIfInvalid = (row: BudgetEnveloppe) => {
    if (!isValidAmount(edits[row.enveloppe_id] ?? '')) {
        edits[row.enveloppe_id] = String(Math.abs(row.amount));
    }
};

const ordered = computed(() =>
    [...props.enveloppes].sort((a, b) =>
        a.direction === b.direction ? 0 : a.direction === 'revenue' ? -1 : 1,
    ),
);

const hasVariable = computed(() =>
    props.enveloppes.some((e) => e.type === 'variable'),
);

const totalIncome = computed(() =>
    props.enveloppes
        .filter((e) => e.direction === 'revenue')
        .reduce((sum, e) => sum + Math.abs(e.amount), 0),
);
const totalExpenses = computed(() =>
    props.enveloppes
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
        <div v-if="editable" class="flex justify-end">
            <Button
                variant="default"
                size="sm"
                class="gap-1.5"
                @click="emit('add')"
            >
                <Icon name="add" class="size-4" aria-hidden="true" />
                Add enveloppe
            </Button>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="border-b bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <th class="px-4 py-2.5 font-medium">Enveloppe</th>
                        <th class="px-4 py-2.5 font-medium">Type</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Amount
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Left to spend
                        </th>
                        <th v-if="editable" class="w-12 px-4 py-2.5">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in ordered"
                        :key="row.enveloppe_id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-2.5">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                :style="{
                                    backgroundColor: `${row.color}22`,
                                    color: row.color,
                                }"
                            >
                                <Icon
                                    :name="
                                        row.direction === 'revenue'
                                            ? 'north'
                                            : 'south'
                                    "
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                {{ row.name }}
                            </span>
                        </td>
                        <td
                            class="px-4 py-2.5 text-muted-foreground capitalize"
                        >
                            {{ row.type }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <template v-if="editable">
                                <Input
                                    :model-value="edits[row.enveloppe_id]"
                                    type="number"
                                    min="1"
                                    step="1"
                                    class="ml-auto h-7 w-24 text-right tabular-nums"
                                    @update:model-value="
                                        onAmountInput(row, String($event))
                                    "
                                    @blur="revertIfInvalid(row)"
                                    @keydown.enter="
                                        (
                                            $event.target as HTMLInputElement
                                        ).blur()
                                    "
                                />
                            </template>
                            <template v-else>{{
                                formatMoney(row.amount, currency)
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
                                class="text-destructive hover:bg-destructive hover:text-destructive"
                                aria-label="Remove enveloppe"
                                :disabled="pendingId === row.enveloppe_id"
                                @click="emit('remove', row.enveloppe_id)"
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
                            No enveloppes assigned yet.
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
                                        : 'text-emerald-600 dark:text-emerald-400'
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
                                    remaining < 0 ? 'Overspending' : 'Left over'
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
        </div>

        <p
            v-if="hasVariable"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <Icon name="info" class="size-3.5 shrink-0" aria-hidden="true" />
            Variable amounts are expected values — re-adjust them any time with
            a budget revision.
        </p>
    </section>
</template>
