<script setup lang="ts">
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { useSaveSchedule } from '@/composables/scheduleQueries';
import { useLocale } from '@/composables/useLocale';
import { ApiError } from '@/lib/api';
import { calendarDate } from '@/lib/calendar';
import {
    definedFields,
    describeAffected,
    draftFromSchedule,
    fieldErrorsFromApi,
    ISO_WEEKDAYS,
    MAX_REMINDER_DAYS,
    monthName,
    REMINDER_MODES,
    type ReminderMode,
    SCHEDULE_PERIODS,
    type ScheduleDraft,
    type ScheduleField,
    type StartDerivedField,
    toScheduleInput,
    validateDraft,
    weekdayName,
    withStartDefaults,
} from '@/lib/schedule';
import type { Envelope, EnvelopeSchedule } from '@/types';

const props = defineProps<{
    open: boolean;
    envelope: Envelope;
    /** The schedule to edit; null creates one. */
    schedule: EnvelopeSchedule | null;
    /** The profile's budget currency ('' when it has none yet). */
    currency: string;
}>();

const emit = defineEmits<(e: 'update:open', value: boolean) => void>();

const { t } = useI18n();
const { locale } = useLocale();
const save = useSaveSchedule();

const draft = ref<ScheduleDraft>(draftFromSchedule(null, calendarDate()));
const submitted = ref(false);
const serverErrors = ref<Partial<Record<ScheduleField, string>>>({});
/** Weekday/day/month fields that no longer follow the start date. */
const fixed = ref(new Set<StartDerivedField>());

watch(
    () => props.open,
    (open) => {
        if (open) {
            draft.value = draftFromSchedule(props.schedule, calendarDate());
            fixed.value = definedFields(props.schedule);
            submitted.value = false;
            serverErrors.value = {};
        }
    },
    { immediate: true },
);

watch(
    () => draft.value.startDate,
    () => (draft.value = withStartDefaults(draft.value, fixed.value)),
);

const touch = (field: StartDerivedField) => fixed.value.add(field);

// A changed value supersedes the server's verdict on the previous one.
watch(draft, () => (serverErrors.value = {}), { deep: true });

/** Client errors show after the first submit, then follow the input live. */
const errors = computed((): Partial<Record<ScheduleField, string>> => {
    const client = submitted.value ? validateDraft(draft.value) : {};
    const translated = Object.fromEntries(
        Object.entries(client).map(([field, key]) => [
            field,
            t(key, { max: MAX_REMINDER_DAYS }),
        ]),
    );

    return { ...serverErrors.value, ...translated };
});

const period = computed(() => draft.value.period);
const weekly = computed(
    () => period.value === 'weekly' || period.value === 'biweekly',
);
const monthly = computed(
    () => period.value === 'monthly' || period.value === 'yearly',
);

const weekdayOptions = computed(() =>
    ISO_WEEKDAYS.map((day) => ({
        value: day,
        short: weekdayName(day, locale.value),
        long: weekdayName(day, locale.value, 'long'),
    })),
);
const monthOptions = computed(() =>
    Array.from({ length: 12 }, (_, index) => ({
        value: String(index + 1),
        label: monthName(index + 1, locale.value),
    })),
);
const reminderModes: readonly ReminderMode[] = REMINDER_MODES;

const affected = computed(() => props.schedule?.open_ledger_entries ?? 0);
const affectedText = computed(() =>
    describeAffected(affected.value, locale.value, t),
);

const submit = () => {
    submitted.value = true;
    if (
        save.isPending.value ||
        Object.keys(validateDraft(draft.value)).length
    ) {
        return;
    }
    const editing = props.schedule !== null;

    save.mutate(
        {
            envelopeId: props.envelope.id,
            scheduleId: props.schedule?.id ?? null,
            input: toScheduleInput(draft.value),
        },
        {
            onSuccess: () => {
                toast.success(
                    t(
                        editing
                            ? 'envelopes.schedules.toast.updated'
                            : 'envelopes.schedules.toast.created',
                    ),
                );
                emit('update:open', false);
            },
            onError: (error) => {
                const fields =
                    error instanceof ApiError
                        ? fieldErrorsFromApi(error.errors)
                        : {};
                serverErrors.value = fields;
                if (Object.keys(fields).length === 0) {
                    toast.error(
                        error instanceof ApiError
                            ? error.firstError
                            : t('envelopes.schedules.toast.failed'),
                    );
                }
            },
        },
    );
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>
                    {{
                        schedule
                            ? $t('envelopes.schedules.dialog.editTitle')
                            : $t('envelopes.schedules.dialog.newTitle')
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{ $t('envelopes.schedules.dialog.description') }}
                </DialogDescription>
            </DialogHeader>

            <form
                id="schedule-form"
                class="grid gap-5"
                novalidate
                @submit.prevent="submit"
            >
                <Alert v-if="affected > 0" data-testid="schedule-affected">
                    <Icon name="warning" aria-hidden="true" />
                    <AlertDescription>
                        {{ affectedText }}
                    </AlertDescription>
                </Alert>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="schedule-amount">
                            {{ $t('envelopes.schedules.dialog.amount') }}
                            <span
                                v-if="currency"
                                class="font-normal text-muted-foreground"
                                >{{
                                    $t(
                                        'envelopes.schedules.dialog.inCurrency',
                                        {
                                            currency,
                                        },
                                    )
                                }}</span
                            >
                        </Label>
                        <Input
                            id="schedule-amount"
                            v-model="draft.amount"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            step="0.01"
                            class="tabular-nums"
                            aria-describedby="schedule-amount-hint"
                        />
                        <p
                            id="schedule-amount-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                envelope.direction === 'revenue'
                                    ? $t(
                                          'envelopes.schedules.dialog.revenueHint',
                                      )
                                    : $t(
                                          'envelopes.schedules.dialog.expenseHint',
                                      )
                            }}
                        </p>
                        <InputError
                            for="schedule-amount"
                            :message="errors.amount"
                        />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="schedule-period">{{
                            $t('envelopes.schedules.dialog.period')
                        }}</Label>
                        <Select v-model="draft.period">
                            <SelectTrigger id="schedule-period" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in SCHEDULE_PERIODS"
                                    :key="option"
                                    :value="option"
                                >
                                    {{
                                        $t(
                                            `envelopes.schedules.periods.${option}`,
                                        )
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            for="schedule-period"
                            :message="errors.period"
                        />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="schedule-start-date">{{
                            period === 'once'
                                ? $t('envelopes.schedules.dialog.onceDate')
                                : $t('envelopes.schedules.dialog.startDate')
                        }}</Label>
                        <Input
                            id="schedule-start-date"
                            v-model="draft.startDate"
                            type="date"
                            min="0001-01-01"
                            max="9999-12-31"
                            required
                        />
                        <InputError
                            for="schedule-start-date"
                            :message="errors.start_date"
                        />
                    </div>

                    <div
                        v-if="period !== 'once'"
                        class="grid content-start gap-2"
                    >
                        <Label for="schedule-end-date">{{
                            $t('envelopes.schedules.dialog.endDate')
                        }}</Label>
                        <Input
                            id="schedule-end-date"
                            v-model="draft.endDate"
                            type="date"
                            :min="draft.startDate || undefined"
                            max="9999-12-31"
                            aria-describedby="schedule-end-date-hint"
                        />
                        <p
                            id="schedule-end-date-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{ $t('envelopes.schedules.dialog.endDateHint') }}
                        </p>
                        <InputError
                            for="schedule-end-date"
                            :message="errors.end_date"
                        />
                    </div>
                </div>

                <div v-if="weekly" class="grid gap-2">
                    <Label id="schedule-weekdays-label" as="span">{{
                        $t('envelopes.schedules.dialog.weekdays')
                    }}</Label>
                    <ToggleGroupRoot
                        id="schedule-weekdays"
                        v-model="draft.weekdays"
                        @update:model-value="touch('weekdays')"
                        type="multiple"
                        tabindex="-1"
                        aria-labelledby="schedule-weekdays-label"
                        class="flex flex-wrap gap-1.5 outline-none"
                    >
                        <ToggleGroupItem
                            v-for="day in weekdayOptions"
                            :key="day.value"
                            :value="day.value"
                            :aria-label="day.long"
                            class="min-h-11 min-w-11 rounded-lg border border-border px-2.5 text-sm font-medium text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                        >
                            {{ day.short }}
                        </ToggleGroupItem>
                    </ToggleGroupRoot>
                    <InputError
                        for="schedule-weekdays"
                        :message="errors.weekdays"
                    />
                </div>

                <div v-if="monthly" class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-if="period === 'yearly'"
                        class="grid content-start gap-2"
                    >
                        <Label for="schedule-month">{{
                            $t('envelopes.schedules.dialog.month')
                        }}</Label>
                        <Select
                            v-model="draft.month"
                            @update:model-value="touch('month')"
                        >
                            <SelectTrigger id="schedule-month" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in monthOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            for="schedule-month"
                            :message="errors.month"
                        />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="schedule-day">{{
                            $t('envelopes.schedules.dialog.dayOfMonth')
                        }}</Label>
                        <Input
                            id="schedule-day"
                            v-model="draft.dayOfMonth"
                            @update:model-value="touch('dayOfMonth')"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="31"
                            step="1"
                            class="tabular-nums"
                            aria-describedby="schedule-day-hint"
                        />
                        <p
                            id="schedule-day-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                $t('envelopes.schedules.dialog.dayOfMonthHint')
                            }}
                        </p>
                        <InputError
                            for="schedule-day"
                            :message="errors.day_of_month"
                        />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="schedule-reminder">{{
                            $t('envelopes.schedules.dialog.reminder')
                        }}</Label>
                        <Select v-model="draft.reminder">
                            <SelectTrigger
                                id="schedule-reminder"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="mode in reminderModes"
                                    :key="mode"
                                    :value="mode"
                                >
                                    {{
                                        $t(
                                            `envelopes.schedules.reminders.${mode}`,
                                        )
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div
                        v-if="draft.reminder === 'daysBefore'"
                        class="grid content-start gap-2"
                    >
                        <Label for="schedule-reminder-days">{{
                            $t('envelopes.schedules.dialog.reminderDays')
                        }}</Label>
                        <Input
                            id="schedule-reminder-days"
                            v-model="draft.reminderDays"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            :max="MAX_REMINDER_DAYS"
                            step="1"
                            class="tabular-nums"
                        />
                        <InputError
                            for="schedule-reminder-days"
                            :message="errors.reminder_days"
                        />
                    </div>
                </div>
            </form>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    @click="emit('update:open', false)"
                >
                    {{ $t('common.actions.cancel') }}
                </Button>
                <Button
                    type="submit"
                    form="schedule-form"
                    :disabled="save.isPending.value"
                >
                    <Spinner v-if="save.isPending.value" />
                    {{
                        save.isPending.value
                            ? $t('envelopes.schedules.dialog.saving')
                            : schedule
                              ? $t('envelopes.schedules.dialog.save')
                              : $t('envelopes.schedules.dialog.create')
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
