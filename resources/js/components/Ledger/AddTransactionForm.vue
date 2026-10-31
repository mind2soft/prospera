<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AddTransactionInput, PickerEnveloppe } from '@/lib/ledgerApi';

const props = defineProps<{
    enveloppes: PickerEnveloppe[];
    currency: string;
    selectedDate: string; // "YYYY-MM-DD"
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'confirm', input: AddTransactionInput): void;
}>();

const enveloppeId = ref<number | null>(null);
const amount = ref('');

const selected = computed(
    () =>
        props.enveloppes.find((e) => e.enveloppe_id === enveloppeId.value) ??
        null,
);

const dateLabel = computed(() => {
    const [year, month, day] = props.selectedDate.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
});

const isValidAmount = (value: string): boolean => {
    const parsed = Number(value);
    return (
        value !== '' &&
        !Number.isNaN(parsed) &&
        Number.isInteger(parsed) &&
        parsed >= 1
    );
};

const valid = computed(
    () => selected.value !== null && isValidAmount(amount.value),
);

const confirm = () => {
    if (!valid.value || selected.value === null) {
        return;
    }
    emit('confirm', {
        enveloppe_id: selected.value.enveloppe_id,
        amount: Number(amount.value),
        event_at: props.selectedDate,
    });
    amount.value = '';
};
</script>

<template>
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="confirm">
        <div class="grid gap-1.5">
            <Label>Enveloppe</Label>
            <Select
                :model-value="enveloppeId ?? undefined"
                :disabled="disabled || enveloppes.length === 0"
                @update:model-value="(value) => (enveloppeId = Number(value))"
            >
                <SelectTrigger class="w-52">
                    <SelectValue placeholder="Choose an enveloppe" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="e in enveloppes"
                        :key="e.enveloppe_id"
                        :value="e.enveloppe_id"
                    >
                        <span class="flex items-center gap-2">
                            <span
                                class="size-2 rounded-full"
                                :style="{ backgroundColor: e.color }"
                            />
                            {{ e.name }}
                            <span class="text-xs text-muted-foreground">
                                {{
                                    e.direction === 'revenue'
                                        ? 'revenue'
                                        : 'expense'
                                }}
                            </span>
                        </span>
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="grid gap-1.5">
            <Label for="transaction-amount">Amount</Label>
            <Input
                id="transaction-amount"
                v-model="amount"
                type="number"
                min="1"
                step="1"
                placeholder="e.g. 42"
                class="w-28 tabular-nums"
            />
        </div>

        <div class="grid gap-1.5">
            <Label>Event date</Label>
            <div
                class="flex h-9 w-36 items-center gap-1.5 rounded-md border bg-muted/40 px-3 text-sm tabular-nums"
                data-testid="event-date"
            >
                <Icon
                    name="calendar-month"
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                {{ dateLabel }}
            </div>
        </div>

        <Button type="submit" class="gap-1.5" :disabled="disabled || !valid">
            <Icon name="add" class="size-4" aria-hidden="true" />
            Add
        </Button>
    </form>
</template>
