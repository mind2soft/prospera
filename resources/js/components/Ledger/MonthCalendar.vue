<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { MONTH_NAMES } from '@/lib/budget';

const props = defineProps<{
    selectedDate: string; // "YYYY-MM-DD"
    viewMonth: string; // "YYYY-MM-DD" — first day of the viewed month
}>();

const emit = defineEmits<{
    (e: 'select-date', value: string): void;
    (e: 'navigate-month', direction: 'prev' | 'next'): void;
}>();

const iso = (d: Date): string =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

const todayIso = computed(() => iso(new Date()));

const monthLabel = computed(() => {
    const [year, month] = props.viewMonth.split('-').map(Number);
    return `${MONTH_NAMES[month - 1] ?? month} ${year}`;
});

type CalendarDay = { date: string; day: number; inMonth: boolean };

const weeks = computed<CalendarDay[][]>(() => {
    const [year, month] = props.viewMonth.split('-').map(Number);
    const first = new Date(year, month - 1, 1);
    const startOffset = (first.getDay() + 6) % 7; // weeks start on Monday
    const daysInMonth = new Date(year, month, 0).getDate();

    const cells: CalendarDay[] = [];
    for (let i = startOffset; i > 0; i -= 1) {
        const d = new Date(year, month - 1, 1 - i);
        cells.push({ date: iso(d), day: d.getDate(), inMonth: false });
    }
    for (let i = 1; i <= daysInMonth; i += 1) {
        cells.push({
            date: iso(new Date(year, month - 1, i)),
            day: i,
            inMonth: true,
        });
    }
    const trailing = (7 - (cells.length % 7)) % 7;
    for (let i = 1; i <= trailing; i += 1) {
        cells.push({
            date: iso(new Date(year, month, i)),
            day: i,
            inMonth: false,
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
                    class="flex h-9 w-full items-center justify-center rounded-lg text-sm tabular-nums transition-colors"
                    :class="[
                        day.inMonth
                            ? 'text-foreground hover:bg-muted'
                            : 'text-muted-foreground/40',
                        day.date === selectedDate
                            ? 'bg-primary font-semibold text-primary-foreground hover:bg-primary'
                            : day.date === todayIso
                              ? 'border border-primary/40'
                              : '',
                    ]"
                    :data-testid="`calendar-day-${day.date}`"
                    @click="emit('select-date', day.date)"
                >
                    {{ day.day }}
                </button>
            </div>
        </div>
    </div>
</template>
