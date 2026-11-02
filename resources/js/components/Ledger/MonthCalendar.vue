<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { MONTH_NAMES } from '@/lib/budget';

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
        monthOffset(-1) < props.minDate.slice(0, 8) + '01',
);

const nextMonthDisabled = computed(
    () =>
        props.maxDate !== undefined &&
        monthOffset(1) > props.maxDate.slice(0, 8) + '01',
);

/** True when `date` falls outside of the calendar's period. */
const isOutOfRange = (date: string): boolean =>
    (props.minDate !== undefined && date < props.minDate) ||
    (props.maxDate !== undefined && date > props.maxDate);

const monthLabel = computed(() => {
    const [year, month] = props.viewMonth.split('-').map(Number);
    return `${MONTH_NAMES[month - 1] ?? month} ${year}`;
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

const weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
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
                aria-label="Previous month"
                @click="emit('navigate-month', 'prev')"
            >
                <Icon name="chevron-left" class="size-4" aria-hidden="true" />
            </Button>
            <p class="text-sm font-semibold">{{ monthLabel }}</p>
            <Button
                variant="ghost"
                size="icon-sm"
                class="rounded-lg"
                :disabled="nextMonthDisabled"
                aria-label="Next month"
                @click="emit('navigate-month', 'next')"
            >
                <Icon name="chevron-right" class="size-4" aria-hidden="true" />
            </Button>
        </div>

        <div
            class="grid grid-cols-7 gap-1 text-center text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
        >
            <span v-for="weekday in weekDays" :key="weekday">{{
                weekday
            }}</span>
        </div>

        <div class="mt-2 space-y-1">
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
                        day.disabled || !day.inMonth
                            ? 'text-muted-foreground/40'
                            : 'text-foreground hover:bg-muted',
                        !day.disabled && day.date === selectedDate
                            ? 'bg-primary font-semibold text-primary-foreground hover:bg-primary'
                            : !day.disabled && day.date === todayIso
                              ? 'border border-primary/40'
                              : '',
                    ]"
                    :disabled="day.disabled"
                    :data-testid="`calendar-day-${day.date}`"
                    @click="emit('select-date', day.date)"
                >
                    {{ day.day }}
                </button>
            </div>
        </div>
    </div>
</template>
