<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/composables/useLocale';
import type { CreateLedgerInput } from '@/lib/ledgerApi';

const { locale } = useLocale();

const props = defineProps<{
    open: boolean;
    /** The create request is in flight: the parent closes on success. */
    pending?: boolean;
    /** A preselected period when continuing a closed ledger. */
    initialStartDate?: string;
    initialEndDate?: string;
    /** The follow-on ledger must remain contiguous with its predecessor. */
    lockStartDate?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm', input: Omit<CreateLedgerInput, 'profile_id'>): void;
}>();

const name = ref('');
const startMonth = ref('');
const startYear = ref('');
const endMonth = ref('');
const endYear = ref('');
const balance = ref('');
const balanceType = ref<'cash' | 'debt'>('cash');

const currentYear = new Date().getFullYear();
const yearOptions = computed(() => {
    const years = [currentYear - 7, currentYear + 3];

    for (const date of [props.initialStartDate, props.initialEndDate]) {
        if (date) {
            years.push(Number(date.slice(0, 4)));
        }
    }

    const firstYear = Math.min(...years);
    const lastYear = Math.max(...years);

    return Array.from({ length: lastYear - firstYear + 1 }, (_, index) =>
        String(firstYear + index),
    );
});
const monthOptions = computed(() =>
    Array.from({ length: 12 }, (_, index) => {
        const month = String(index + 1);

        return {
            value: month,
            label: new Date(Date.UTC(2020, index, 1)).toLocaleString(
                locale.value,
                { month: 'long', timeZone: 'UTC' },
            ),
        };
    }),
);

const periodKey = (year: string, month: string): string =>
    `${year}-${month.padStart(2, '0')}`;

const startDate = computed(() =>
    startYear.value && startMonth.value
        ? `${periodKey(startYear.value, startMonth.value)}-01`
        : '',
);
const endDate = computed(() => {
    if (!endYear.value || !endMonth.value) {
        return '';
    }

    const lastDay = new Date(
        Number(endYear.value),
        Number(endMonth.value),
        0,
    ).getDate();

    return `${periodKey(endYear.value, endMonth.value)}-${String(lastDay).padStart(2, '0')}`;
});

const updateStartPeriod = (part: 'month' | 'year', value: unknown) => {
    if (typeof value !== 'string') {
        return;
    }

    if (part === 'month') {
        startMonth.value = value;
    } else {
        startYear.value = value;
    }

    if (
        periodKey(startYear.value, startMonth.value) >
        periodKey(endYear.value, endMonth.value)
    ) {
        endMonth.value = startMonth.value;
        endYear.value = startYear.value;
    }
};

const updateEndPeriod = (part: 'month' | 'year', value: unknown) => {
    if (typeof value !== 'string') {
        return;
    }

    if (part === 'month') {
        endMonth.value = value;
    } else {
        endYear.value = value;
    }

    if (
        periodKey(endYear.value, endMonth.value) <
        periodKey(startYear.value, startMonth.value)
    ) {
        if (props.lockStartDate) {
            endMonth.value = startMonth.value;
            endYear.value = startYear.value;
        } else {
            startMonth.value = endMonth.value;
            startYear.value = endYear.value;
        }
    }
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            const year = String(new Date().getFullYear());
            const month = String(new Date().getMonth() + 1);
            const [initialStartYear, initialStartMonth] =
                props.initialStartDate?.split('-') ?? [];
            const [initialEndYear, initialEndMonth] =
                props.initialEndDate?.split('-') ?? [];
            name.value = initialStartYear ?? year;
            startMonth.value = initialStartMonth
                ? String(Number(initialStartMonth))
                : month;
            startYear.value = initialStartYear ?? year;
            endMonth.value = initialEndMonth
                ? String(Number(initialEndMonth))
                : '12';
            endYear.value = initialEndYear ?? year;
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
    if (!valid.value || props.pending) {
        return;
    }
    emit('confirm', {
        name: name.value.trim(),
        start_date: startDate.value,
        end_date: endDate.value,
        starting_balance: Number(balance.value),
        starting_balance_type: balanceType.value,
    });
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ $t('ledger.createDialog.title') }}</DialogTitle>
                <DialogDescription>
                    {{
                        lockStartDate
                            ? $t('ledger.createDialog.nextDescription')
                            : $t('ledger.createDialog.description')
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="ledger-name">{{
                        $t('common.fields.name')
                    }}</Label>
                    <Input id="ledger-name" v-model="name" type="text" />
                </div>

                <div class="grid gap-4">
                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">
                            {{ $t('ledger.createDialog.startDate') }}
                        </legend>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="grid gap-2">
                                <Label for="ledger-start-month">{{
                                    $t('ledger.createDialog.month')
                                }}</Label>
                                <Select
                                    :model-value="startMonth"
                                    :disabled="lockStartDate"
                                    @update:model-value="
                                        updateStartPeriod('month', $event)
                                    "
                                >
                                    <SelectTrigger
                                        id="ledger-start-month"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="month in monthOptions"
                                            :key="month.value"
                                            :value="month.value"
                                        >
                                            {{ month.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-2">
                                <Label for="ledger-start-year">{{
                                    $t('ledger.createDialog.year')
                                }}</Label>
                                <Select
                                    :model-value="startYear"
                                    :disabled="lockStartDate"
                                    @update:model-value="
                                        updateStartPeriod('year', $event)
                                    "
                                >
                                    <SelectTrigger
                                        id="ledger-start-year"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="year in yearOptions"
                                            :key="year"
                                            :value="year"
                                        >
                                            {{ year }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{
                                lockStartDate
                                    ? $t('ledger.createDialog.startLocked')
                                    : $t('ledger.createDialog.startsOnFirstDay')
                            }}
                        </p>
                    </fieldset>

                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">
                            {{ $t('ledger.createDialog.endDate') }}
                        </legend>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="grid gap-2">
                                <Label for="ledger-end-month">{{
                                    $t('ledger.createDialog.month')
                                }}</Label>
                                <Select
                                    :model-value="endMonth"
                                    @update:model-value="
                                        updateEndPeriod('month', $event)
                                    "
                                >
                                    <SelectTrigger
                                        id="ledger-end-month"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="month in monthOptions"
                                            :key="month.value"
                                            :value="month.value"
                                        >
                                            {{ month.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-2">
                                <Label for="ledger-end-year">{{
                                    $t('ledger.createDialog.year')
                                }}</Label>
                                <Select
                                    :model-value="endYear"
                                    @update:model-value="
                                        updateEndPeriod('year', $event)
                                    "
                                >
                                    <SelectTrigger
                                        id="ledger-end-year"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="year in yearOptions"
                                            :key="year"
                                            :value="year"
                                        >
                                            {{ year }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('ledger.createDialog.endsOnLastDay') }}
                        </p>
                    </fieldset>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="ledger-balance">{{
                            $t('ledger.createDialog.startingBalance')
                        }}</Label>
                        <Input
                            id="ledger-balance"
                            v-model="balance"
                            type="number"
                            min="0"
                            step="1"
                            :placeholder="
                                $t('ledger.createDialog.balancePlaceholder')
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ledger-balance-type">{{
                            $t('ledger.createDialog.balanceType')
                        }}</Label>
                        <Select v-model="balanceType">
                            <SelectTrigger
                                id="ledger-balance-type"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="cash">{{
                                    $t('ledger.createDialog.cash')
                                }}</SelectItem>
                                <SelectItem value="debt">{{
                                    $t('ledger.createDialog.debt')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="emit('update:open', false)">{{
                    $t('common.actions.cancel')
                }}</Button>
                <Button :disabled="!valid || pending" @click="confirm">
                    <Spinner v-if="pending" />
                    {{ $t('ledger.actions.createLedger') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
