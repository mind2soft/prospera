<script setup lang="ts">
import { computed, nextTick, ref, useId, useTemplateRef, watch } from 'vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';

const { locale } = useLocale();

const props = defineProps<{
    selectedDate: string; // "YYYY-MM-DD"
    viewMonth: string; // "YYYY-MM-DD" — first day of the viewed month
    /** Lower bound of the calendar's period, e.g. a ledger's start date. */
    minDate?: string; // "YYYY-MM-DD"
    /** Upper bound of the calendar's period, e.g. a ledger's end date. */
    maxDate?: string; // "YYYY-MM-DD"
}>();

const emit = defineEmits<{
    (e: 'select-date', value: string): void;
    (e: 'navigate-month', direction: 'prev' | 'next'): void;
}>();

const iso = (d: Date): string =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

const todayIso = computed(() => iso(new Date()));

/** First day of the month `offset` months away from the viewed month, ISO. */
const monthOffset = (offset: number): string => {
    const [year, month] = props.viewMonth.split('-').map(Number);
    return iso(new Date(year, month - 1 + offset, 1));
};

/**
 * Bound month navigation to the calendar's period: a step is allowed only if
 * the first day of the target month stays within [minDate, maxDate].
 */
const prevMonthDisabled = computed(
    () =>
        props.minDate !== undefined &&
        monthOffset(-1) < `${props.minDate.slice(0, 8)}01`,
);

const nextMonthDisabled = computed(
    () =>
        props.maxDate !== undefined &&
        monthOffset(1) > `${props.maxDate.slice(0, 8)}01`,
);

/** True when `date` falls outside of the calendar's period. */
const isOutOfRange = (date: string): boolean =>
    (props.minDate !== undefined && date < props.minDate) ||
    (props.maxDate !== undefined && date > props.maxDate);

const monthLabel = computed(() => {
    const [year, month] = props.viewMonth.split('-').map(Number);
    return new Date(year, month - 1, 1).toLocaleDateString(locale.value, {
        month: 'long',
        year: 'numeric',
    });
});

type CalendarDay = {
    date: string;
    day: number;
    inMonth: boolean;
    disabled: boolean;
};

const weeks = computed<CalendarDay[][]>(() => {
    const [year, month] = props.viewMonth.split('-').map(Number);
    const first = new Date(year, month - 1, 1);
    const startOffset = (first.getDay() + 6) % 7; // weeks start on Monday
    const daysInMonth = new Date(year, month, 0).getDate();

    const cells: CalendarDay[] = [];
    for (let i = startOffset; i > 0; i -= 1) {
        const d = new Date(year, month - 1, 1 - i);
        const cellDate = iso(d);
        cells.push({
            date: cellDate,
            day: d.getDate(),
            inMonth: false,
            disabled: isOutOfRange(cellDate),
        });
    }
    for (let i = 1; i <= daysInMonth; i += 1) {
        const cellDate = iso(new Date(year, month - 1, i));
        cells.push({
            date: cellDate,
            day: i,
            inMonth: true,
            disabled: isOutOfRange(cellDate),
        });
    }
    const trailing = (7 - (cells.length % 7)) % 7;
    for (let i = 1; i <= trailing; i += 1) {
        const d = new Date(year, month, i);
        const cellDate = iso(d);
        cells.push({
            date: cellDate,
            day: d.getDate(),
            inMonth: false,
            disabled: isOutOfRange(cellDate),
        });
    }

    const grid: CalendarDay[][] = [];
    for (let i = 0; i < cells.length; i += 7) {
        grid.push(cells.slice(i, i + 7));
    }
    return grid;
});

/** Full, localized date for a day's accessible name. */
const fullDateLabel = (date: string): string => {
    const [year, month, day] = date.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString(locale.value, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
};

const monthLabelId = useId();
const grid = useTemplateRef<HTMLElement>('grid');
const days = computed(() => weeks.value.flat());

/**
 * Roving tabindex: one day is in the tab order (the selected day when it is
 * shown, else the first selectable day of the month); arrow keys move it.
 */
const focusDate = ref<string | null>(null);

const tabbableDate = computed(() => {
    const visible = (date: string | null) =>
        date !== null && days.value.some((d) => d.date === date && !d.disabled);
    if (visible(focusDate.value)) {
        return focusDate.value;
    }
    if (visible(props.selectedDate)) {
        return props.selectedDate;
    }
    return (
        days.value.find((d) => d.inMonth && !d.disabled)?.date ??
        days.value.find((d) => !d.disabled)?.date ??
        null
    );
});

watch(
    () => props.selectedDate,
    () => {
        focusDate.value = null;
    },
);

const focusDay = async (date: string) => {
    focusDate.value = date;
    await nextTick();
    grid.value
        ?.querySelector<HTMLButtonElement>(`[data-date="${date}"]`)
        ?.focus();
};

const shiftDate = (date: string, days: number): string => {
    const [year, month, day] = date.split('-').map(Number);
    return iso(new Date(year, month - 1, day + days));
};

const KEY_STEPS: Record<string, number> = {
    ArrowLeft: -1,
    ArrowRight: 1,
    ArrowUp: -7,
    ArrowDown: 7,
};

const onDayKeydown = async (event: KeyboardEvent, day: CalendarDay) => {
    let target: string | null = null;
    if (event.key in KEY_STEPS) {
        target = shiftDate(day.date, KEY_STEPS[event.key]);
    } else if (event.key === 'Home' || event.key === 'End') {
        const week = weeks.value.find((w) =>
            w.some((d) => d.date === day.date),
        );
        target = (event.key === 'Home' ? week?.[0] : week?.[6])?.date ?? null;
    } else {
        return;
    }
    event.preventDefault();

    if (target === null || isOutOfRange(target)) {
        return;
    }
    if (!days.value.some((d) => d.date === target)) {
        emit('navigate-month', target < day.date ? 'prev' : 'next');
        await nextTick();
    }
    await focusDay(target);
};

/** Weekday names Mon–Sun in the active locale (2023-01-02 was a Monday). */
const weekDays = computed(() =>
    Array.from({ length: 7 }, (_, i) =>
        new Date(2023, 0, 2 + i).toLocaleDateString(locale.value, {
            weekday: 'short',
        }),
    ),
);
</script>

<template>
    <div
        class="w-full max-w-sm rounded-xl border bg-card p-4 shadow-sm"
        data-testid="month-calendar"
    >
        <div class="mb-3 flex items-center justify-between">
            <Button
                variant="ghost"
                size="icon-sm"
                class="rounded-lg"
                :disabled="prevMonthDisabled"
                :aria-label="$t('ledger.calendar.previousMonth')"
                @click="emit('navigate-month', 'prev')"
            >
                <Icon name="chevron-left" class="size-4" aria-hidden="true" />
            </Button>
            <p
                :id="monthLabelId"
                class="text-sm font-semibold"
                aria-live="polite"
            >
                {{ monthLabel }}
            </p>
            <Button
                variant="ghost"
                size="icon-sm"
                class="rounded-lg"
                :disabled="nextMonthDisabled"
                :aria-label="$t('ledger.calendar.nextMonth')"
                @click="emit('navigate-month', 'next')"
            >
                <Icon name="chevron-right" class="size-4" aria-hidden="true" />
            </Button>
        </div>

        <div
            class="grid grid-cols-7 gap-1 text-center text-xs font-medium tracking-wide text-muted-foreground uppercase"
            aria-hidden="true"
        >
            <span v-for="weekday in weekDays" :key="weekday">{{
                weekday
            }}</span>
        </div>

        <fieldset
            ref="grid"
            class="mt-2 min-w-0 space-y-1"
            :aria-labelledby="monthLabelId"
        >
            <div
                v-for="(week, index) in weeks"
                :key="index"
                class="grid grid-cols-7 gap-1"
            >
                <button
                    v-for="day in week"
                    :key="day.date"
                    type="button"
                    class="flex h-9 w-full items-center justify-center rounded-lg text-sm tabular-nums transition-colors disabled:pointer-events-none disabled:opacity-30"
                    :class="[
                        day.disabled
                            ? 'text-muted-foreground'
                            : day.inMonth
                              ? 'text-foreground hover:bg-muted'
                              : 'text-muted-foreground hover:bg-muted',
                        !day.disabled && day.date === selectedDate
                            ? 'bg-primary font-semibold text-primary-foreground hover:bg-primary'
                            : !day.disabled && day.date === todayIso
                              ? 'border border-primary/40'
                              : '',
                    ]"
                    :disabled="day.disabled"
                    :tabindex="day.date === tabbableDate ? 0 : -1"
                    :aria-label="fullDateLabel(day.date)"
                    :aria-pressed="day.date === selectedDate"
                    :aria-current="day.date === todayIso ? 'date' : undefined"
                    :data-date="day.date"
                    :data-testid="`calendar-day-${day.date}`"
                    @click="emit('select-date', day.date)"
                    @keydown="onDayKeydown($event, day)"
                >
                    {{ day.day }}
                </button>
            </div>
        </fieldset>
    </div>
</template>
