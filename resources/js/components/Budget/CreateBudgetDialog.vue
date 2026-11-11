<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/composables/useLocale';
import { CURRENCIES, currencyName } from '@/lib/budget';

const { locale } = useLocale();

const props = defineProps<{ open: boolean }>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm', currency: string): void;
}>();

const currency = ref<string>(CURRENCIES[0].code);

watch(
    () => props.open,
    (open) => {
        if (open) {
            currency.value = CURRENCIES[0].code;
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
                <DialogTitle>{{ $t('budget.create.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('budget.create.description') }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label for="create-budget-currency">{{
                    $t('budget.currency')
                }}</Label>
                <Select v-model="currency">
                    <SelectTrigger id="create-budget-currency" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in CURRENCIES"
                            :key="option.code"
                            :value="option.code"
                        >
                            {{ option.code }}
                            —
                            {{ currencyName(option.code, locale) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="emit('update:open', false)">{{
                    $t('common.actions.cancel')
                }}</Button>
                <Button @click="confirm">{{
                    $t('budget.create.createDraft')
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
