<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
import { Button } from '@/components/ui/button';
import type { CreateLedgerInput } from '@/lib/ledgerApi';

const props = defineProps<{ open: boolean }>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm', input: Omit<CreateLedgerInput, 'profile_id'>): void;
}>();

const isoToday = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const name = ref('');
const startDate = ref('');
const endDate = ref('');
const balance = ref('');
const balanceType = ref<'cash' | 'debt'>('cash');

watch(
    () => props.open,
    (open) => {
        if (open) {
            const year = String(new Date().getFullYear());
            name.value = year;
            startDate.value = isoToday();
            endDate.value = `${year}-12-31`;
            balance.value = '';
            balanceType.value = 'cash';
        }
    },
);

const isValidBalance = (value: string): boolean => {
    const parsed = Number(value);
    return (
        value !== '' &&
        !Number.isNaN(parsed) &&
        Number.isInteger(parsed) &&
        parsed >= 0
    );
};

const valid = computed(
    () =>
        name.value.trim() !== '' &&
        startDate.value !== '' &&
        endDate.value !== '' &&
        endDate.value >= startDate.value &&
        isValidBalance(balance.value),
);

const confirm = () => {
    if (!valid.value) {
        return;
    }
    emit('confirm', {
        name: name.value.trim(),
        start_date: startDate.value,
        end_date: endDate.value,
        starting_balance: Number(balance.value),
        starting_balance_type: balanceType.value,
    });
    emit('update:open', false);
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Create a new ledger</DialogTitle>
                <DialogDescription>
                    A ledger keeps a yearly financial record of your
                    transactions.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="ledger-name">Name</Label>
                    <Input id="ledger-name" v-model="name" type="text" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="ledger-start">Starting date</Label>
                        <Input
                            id="ledger-start"
                            v-model="startDate"
                            type="date"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ledger-end">End date</Label>
                        <Input id="ledger-end" v-model="endDate" type="date" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="ledger-balance">Starting balance</Label>
                        <Input
                            id="ledger-balance"
                            v-model="balance"
                            type="number"
                            min="0"
                            step="1"
                            placeholder="e.g. 1000"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Balance type</Label>
                        <Select v-model="balanceType">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="cash">Cash</SelectItem>
                                <SelectItem value="debt">Debt</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="emit('update:open', false)"
                    >Cancel</Button
                >
                <Button :disabled="!valid" @click="confirm"
                    >Create ledger</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
