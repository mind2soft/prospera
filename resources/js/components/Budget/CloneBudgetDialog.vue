<script setup lang="ts">
import { ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { CURRENCIES } from '@/lib/budget';

const props = defineProps<{
    open: boolean;
    /** Currency pre-filled from the published budget being cloned. */
    currency?: string;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm', currency: string): void;
}>();

const currency = ref<string>(props.currency ?? CURRENCIES[0].code);

watch(
    () => props.open,
    (open) => {
        if (open) {
            currency.value = props.currency ?? CURRENCIES[0].code;
        }
    },
);

const confirm = () => {
    emit('confirm', currency.value);
    emit('update:open', false);
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Create a new budget?</DialogTitle>
                <DialogDescription>
                    A draft copy of this budget will be created — same
                    enveloppes, ready for your adjustments. The currency is
                    pre-filled from the current budget.
                </DialogDescription>
            </DialogHeader>

            <Select v-model="currency">
                <SelectTrigger class="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in CURRENCIES"
                        :key="option.code"
                        :value="option.code"
                    >
                        {{ option.code }} — {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <DialogFooter>
                <Button variant="ghost" @click="emit('update:open', false)"
                    >Cancel</Button
                >
                <Button @click="confirm">Create draft</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
