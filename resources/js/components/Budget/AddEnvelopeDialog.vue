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
import { useEnvelopeOptions } from '@/composables/envelopeQueries';
import type { AddEnvelopeInput } from '@/lib/budgetApi';

const props = defineProps<{
    open: boolean;
    excludedIds?: number[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm', input: AddEnvelopeInput): void;
}>();

const optionsQuery = useEnvelopeOptions();
const options = computed(() =>
    // Only envelopes the user may plan against (owner or contributor);
    // metadata-only envelopes are rejected by the server.
    (optionsQuery.data.value ?? []).filter(
        (option) =>
            !props.excludedIds?.includes(option.id) &&
            (option.permission === 'owner' ||
                option.permission === 'contributor'),
    ),
);

const envelopeId = ref<number | null>(null);
const type = ref<'fixed' | 'variable'>('fixed');
const amount = ref('');

const selected = computed(
    () =>
        options.value.find((option) => option.id === envelopeId.value) ?? null,
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            envelopeId.value = null;
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
        envelope_id: selected.value.id,
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
                <DialogTitle>{{ $t('budget.addEnvelope.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('budget.addEnvelope.description') }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label>{{ $t('budget.addEnvelope.envelope') }}</Label>
                    <Select
                        :model-value="envelopeId ?? undefined"
                        @update:model-value="
                            (value) => (envelopeId = Number(value))
                        "
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue
                                :placeholder="
                                    $t('budget.addEnvelope.chooseEnvelope')
                                "
                            />
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
                                                ? $t(
                                                      'budget.addEnvelope.revenue',
                                                  )
                                                : $t(
                                                      'budget.addEnvelope.expense',
                                                  )
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
                        {{ $t('budget.addEnvelope.allAssigned') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>{{ $t('budget.addEnvelope.type') }}</Label>
                    <Select v-model="type">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="fixed">{{
                                $t('budget.addEnvelope.fixed')
                            }}</SelectItem>
                            <SelectItem value="variable">{{
                                $t('budget.addEnvelope.variable')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>{{ $t('budget.addEnvelope.amount') }}</Label>
                    <Input
                        v-model="amount"
                        type="number"
                        min="1"
                        step="1"
                        :placeholder="
                            $t('budget.addEnvelope.amountPlaceholder')
                        "
                        class="tabular-nums"
                    />
                    <p v-if="selected" class="text-xs text-muted-foreground">
                        {{
                            selected.direction === 'expense'
                                ? $t('budget.addEnvelope.savedAsExpense')
                                : $t('budget.addEnvelope.savedAsRevenue')
                        }}
                    </p>
                </div>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="emit('update:open', false)">{{
                    $t('common.actions.cancel')
                }}</Button>
                <Button :disabled="!valid" @click="confirm">{{
                    $t('budget.addEnvelope.add')
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
