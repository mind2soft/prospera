<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useEnveloppeOptions } from '@/composables/enveloppeQueries';
import type { AddEnveloppeInput } from '@/lib/budgetApi';

const props = defineProps<{
    open: boolean;
    excludedIds?: number[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm', input: AddEnveloppeInput): void;
}>();

const optionsQuery = useEnveloppeOptions();
const options = computed(() =>
    // Only enveloppes the user may plan against (owner or contributor);
    // metadata-only enveloppes are rejected by the server.
    (optionsQuery.data.value ?? []).filter(
        (option) =>
            !props.excludedIds?.includes(option.id) &&
            (option.permission === 'owner' ||
                option.permission === 'contributor'),
    ),
);

const enveloppeId = ref<number | null>(null);
const type = ref<'fixed' | 'variable'>('fixed');
const amount = ref('');

const selected = computed(
    () =>
        options.value.find((option) => option.id === enveloppeId.value) ?? null,
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            enveloppeId.value = null;
            type.value = 'fixed';
            amount.value = '';
        }
    },
);

const valid = computed(() => {
    const parsed = Number(amount.value);
    return (
        selected.value !== null &&
        amount.value !== '' &&
        Number.isInteger(parsed) &&
        parsed >= 1
    );
});

const confirm = () => {
    if (!valid.value || selected.value === null) {
        return;
    }
    emit('confirm', {
        enveloppe_id: selected.value.id,
        type: type.value,
        amount: Number(amount.value),
    });
    emit('update:open', false);
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Add an enveloppe</DialogTitle>
                <DialogDescription>
                    Assign one of your enveloppes to this budget with its
                    planned amount.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label>Enveloppe</Label>
                    <Select
                        :model-value="enveloppeId ?? undefined"
                        @update:model-value="
                            (value) => (enveloppeId = Number(value))
                        "
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Choose an enveloppe" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in options"
                                :key="option.id"
                                :value="option.id"
                            >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="size-2 rounded-full"
                                        :style="{
                                            backgroundColor: option.color,
                                        }"
                                    />
                                    {{ option.name }}
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            option.direction === 'revenue'
                                                ? 'revenue'
                                                : 'expense'
                                        }}
                                    </span>
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="open && options.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        <Icon
                            name="info"
                            class="mr-1 inline size-3.5"
                            aria-hidden="true"
                        />
                        All your enveloppes are already in this budget.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>Type</Label>
                    <Select v-model="type">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="fixed">Fixed</SelectItem>
                            <SelectItem value="variable">Variable</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Amount</Label>
                    <Input
                        v-model="amount"
                        type="number"
                        min="1"
                        step="1"
                        placeholder="e.g. 800"
                        class="tabular-nums"
                    />
                    <p v-if="selected" class="text-xs text-muted-foreground">
                        {{
                            selected.direction === 'expense'
                                ? 'Saved as an expense.'
                                : 'Saved as revenue.'
                        }}
                    </p>
                </div>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="emit('update:open', false)"
                    >Cancel</Button
                >
                <Button :disabled="!valid" @click="confirm"
                    >Add enveloppe</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
