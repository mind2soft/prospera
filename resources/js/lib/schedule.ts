import { formatCalendarDate, parseCalendarDate } from '@/lib/calendar';
import type { ScheduleInput } from '@/lib/scheduleApi';
import type { EnvelopeSchedule, SchedulePeriod } from '@/types';

/**
 * Pure schedule helpers for the Envelopes page drawer and dialog
 * (docs/scheduled-transactions.md): human-readable summaries, the dialog's
 * draft ⇄ API payload mapping and the client-side mirror of the server's
 * validation rules.
 */

export const SCHEDULE_PERIODS: readonly SchedulePeriod[] = [
    'once',
    'weekly',
    'biweekly',
    'monthly',
    'yearly',
];

/** ISO weekdays, Monday first (the API's numbering). */
export const ISO_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

/** Same maximum as manual transactions: 999,999,999.99 currency units. */
export const MAX_AMOUNT_MINOR_UNITS = 99_999_999_999;

/** The server's bound for `reminder_days`. */
export const MAX_REMINDER_DAYS = 2_147_483_647;

/** A vue-i18n `t` with named arguments and an optional plural choice. */
export type Translate = (
    key: string,
    named?: Record<string, unknown>,
    plural?: number,
) => string;

const usesWeekdays = (period: SchedulePeriod): boolean =>
    period === 'weekly' || period === 'biweekly';

const usesDayOfMonth = (period: SchedulePeriod): boolean =>
    period === 'monthly' || period === 'yearly';

// --- money ----------------------------------------------------------------

/** A text input's model; `type="number"` inputs cast it to a number. */
export type InputValue = string | number;

/**
 * A typed currency amount ("123.45") as exact integer minor units (12345),
 * or null when it is not a nonnegative amount with at most two decimals.
 * Parsed from the digits — never through floating-point multiplication.
 * A number input's model may be a number: its shortest decimal string is
 * the typed value.
 */
export function parseAmountInput(value: InputValue): number | null {
    const match = /^(0|[1-9]\d{0,8})(?:\.(\d{1,2}))?$/.exec(
        String(value).trim(),
    );
    if (!match) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/** Minor units (sign ignored) as an editable amount: 12345 → "123.45", 500 → "5". */
export function formatAmountInput(minorUnits: number): string {
    const magnitude = Math.abs(minorUnits);
    const whole = Math.trunc(magnitude / 100);
    const cents = magnitude % 100;

    return cents === 0
        ? String(whole)
        : `${whole}.${String(cents).padStart(2, '0')}`;
}

/** Minor units as signed currency units, for display (`formatMoney`). */
export function minorToCurrencyUnits(minorUnits: number): number {
    return minorUnits / 100;
}

// --- summary --------------------------------------------------------------

const MEDIUM_DATE: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
};

/** Localized short weekday name of an ISO weekday (2024-01-01 is a Monday). */
export function weekdayName(
    isoWeekday: number,
    locale: string,
    width: 'short' | 'long' = 'short',
): string {
    return new Date(2024, 0, isoWeekday).toLocaleDateString(locale, {
        weekday: width,
    });
}

/** Localized month name (1–12). */
export function monthName(month: number, locale: string): string {
    return new Date(2024, month - 1, 1).toLocaleDateString(locale, {
        month: 'long',
    });
}

/** Days in the month of a common (non-leap) year. */
const daysInMonth = (month: number): number =>
    new Date(2023, month, 0).getDate();

export function formatInteger(value: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(value);
}

function recurrence(
    schedule: EnvelopeSchedule,
    locale: string,
    t: Translate,
): string {
    const prefix = 'envelopes.schedules.summary';
    const day = schedule.day_of_month ?? 1;

    switch (schedule.period) {
        case 'once':
            return t(`${prefix}.once`, {
                date: formatCalendarDate(
                    schedule.start_date,
                    locale,
                    MEDIUM_DATE,
                ),
            });
        case 'weekly':
        case 'biweekly':
            return t(`${prefix}.${schedule.period}`, {
                days: new Intl.ListFormat(locale, {
                    style: 'long',
                    type: 'conjunction',
                }).format(
                    [...schedule.weekdays]
                        .sort((a, b) => a - b)
                        .map((weekday) => weekdayName(weekday, locale)),
                ),
            });
        case 'monthly':
            return t(`${prefix}.${day > 28 ? 'monthlyClamped' : 'monthly'}`, {
                day: formatInteger(day, locale),
            });
        case 'yearly': {
            const month = schedule.month ?? 1;

            return t(
                `${prefix}.${day > daysInMonth(month) ? 'yearlyClamped' : 'yearly'}`,
                {
                    day: formatInteger(day, locale),
                    month: monthName(month, locale),
                },
            );
        }
    }
}

/** "Affects ~12 ledger entries in open ledgers." */
export function describeAffected(
    count: number,
    locale: string,
    t: Translate,
): string {
    return t(
        'envelopes.schedules.affected',
        { n: formatInteger(count, locale) },
        count,
    );
}

export type ScheduleSummary = {
    /** "Bi-weekly on Mon and Thu" */
    recurrence: string;
    /** "From Mar 1, 2026" / "From Mar 1, 2026 to Dec 31, 2026"; null for a one-off. */
    range: string | null;
    /** "No reminder" / "Remind on the day" / "Remind 3 days before" */
    reminder: string;
    /** "Next: Mar 5, Mar 9" / "No upcoming dates" */
    upcoming: string;
};

/** The schedule's human-readable, localized summary. */
export function describeSchedule(
    schedule: EnvelopeSchedule,
    locale: string,
    t: Translate,
): ScheduleSummary {
    const prefix = 'envelopes.schedules.summary';
    const date = (value: string) =>
        formatCalendarDate(value, locale, MEDIUM_DATE);

    let range: string | null = null;
    if (schedule.period !== 'once') {
        range =
            schedule.end_date === null
                ? t(`${prefix}.from`, { start: date(schedule.start_date) })
                : t(`${prefix}.between`, {
                      start: date(schedule.start_date),
                      end: date(schedule.end_date),
                  });
    }

    const days = schedule.reminder_days;
    const reminder =
        days === null
            ? t(`${prefix}.noReminder`)
            : days === 0
              ? t(`${prefix}.remindSameDay`)
              : t(
                    `${prefix}.remindDaysBefore`,
                    { n: formatInteger(days, locale) },
                    days,
                );

    const upcoming =
        schedule.next_occurrences.length === 0
            ? t(`${prefix}.noUpcoming`)
            : t(`${prefix}.upcoming`, {
                  dates: new Intl.ListFormat(locale, {
                      style: 'short',
                      type: 'unit',
                  }).format(
                      schedule.next_occurrences.map((value) =>
                          formatCalendarDate(value, locale, {
                              month: 'short',
                              day: 'numeric',
                          }),
                      ),
                  ),
              });

    return {
        recurrence: recurrence(schedule, locale, t),
        range,
        reminder,
        upcoming,
    };
}

// --- dialog draft ---------------------------------------------------------

export type ReminderMode = 'none' | 'sameDay' | 'daysBefore';

/** The dialog's editable state: text inputs stay strings until submit. */
export type ScheduleDraft = {
    amount: InputValue;
    period: SchedulePeriod;
    startDate: string;
    endDate: string;
    weekdays: number[];
    dayOfMonth: InputValue;
    month: string;
    reminder: ReminderMode;
    reminderDays: InputValue;
};

export type ScheduleField =
    | 'amount'
    | 'period'
    | 'start_date'
    | 'end_date'
    | 'weekdays'
    | 'day_of_month'
    | 'month'
    | 'reminder_days';

const FIELDS: readonly ScheduleField[] = [
    'amount',
    'period',
    'start_date',
    'end_date',
    'weekdays',
    'day_of_month',
    'month',
    'reminder_days',
];

/** ISO weekday (1–7) of a calendar date. */
const isoWeekday = (date: string): number =>
    parseCalendarDate(date).getDay() || 7;

/** Draft fields that default from the start date until the user sets them. */
export type StartDerivedField = 'weekdays' | 'dayOfMonth' | 'month';

/**
 * The fields an existing schedule already defines (they never follow the
 * start date); none for a new schedule.
 */
export function definedFields(
    schedule: EnvelopeSchedule | null,
): Set<StartDerivedField> {
    const fields = new Set<StartDerivedField>();
    if (schedule !== null && usesWeekdays(schedule.period)) {
        fields.add('weekdays');
    }
    if (schedule !== null && usesDayOfMonth(schedule.period)) {
        fields.add('dayOfMonth');
    }
    if (schedule?.period === 'yearly') {
        fields.add('month');
    }

    return fields;
}

/**
 * The draft with its weekday, day of month and month taken from a valid
 * start date, except the `fixed` ones (defined or touched by the user).
 */
export function withStartDefaults(
    draft: ScheduleDraft,
    fixed: ReadonlySet<StartDerivedField>,
): ScheduleDraft {
    if (!isCalendarDate(draft.startDate)) {
        return draft;
    }

    return {
        ...draft,
        weekdays: fixed.has('weekdays')
            ? draft.weekdays
            : [isoWeekday(draft.startDate)],
        dayOfMonth: fixed.has('dayOfMonth')
            ? draft.dayOfMonth
            : String(Number(draft.startDate.slice(8, 10))),
        month: fixed.has('month')
            ? draft.month
            : String(Number(draft.startDate.slice(5, 7))),
    };
}

/** The draft of an existing schedule, or a new one starting on `today`. */
export function draftFromSchedule(
    schedule: EnvelopeSchedule | null,
    today: string,
): ScheduleDraft {
    if (schedule === null) {
        return {
            amount: '',
            period: 'monthly',
            startDate: today,
            endDate: '',
            weekdays: [isoWeekday(today)],
            dayOfMonth: String(Number(today.slice(8, 10))),
            month: String(Number(today.slice(5, 7))),
            reminder: 'none',
            reminderDays: '1',
        };
    }

    const days = schedule.reminder_days;

    return {
        amount: formatAmountInput(schedule.amount),
        period: schedule.period,
        startDate: schedule.start_date,
        endDate: schedule.end_date ?? '',
        weekdays: usesWeekdays(schedule.period)
            ? [...schedule.weekdays]
            : [isoWeekday(schedule.start_date)],
        dayOfMonth: String(
            schedule.day_of_month ?? Number(schedule.start_date.slice(8, 10)),
        ),
        month: String(
            schedule.month ?? Number(schedule.start_date.slice(5, 7)),
        ),
        reminder:
            days === null ? 'none' : days === 0 ? 'sameDay' : 'daysBefore',
        reminderDays: days !== null && days > 0 ? String(days) : '1',
    };
}

/** A real Gregorian `YYYY-MM-DD` date in years 0001–9999 (the server rule). */
export function isCalendarDate(value: string): boolean {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!match) {
        return false;
    }
    const [year, month, day] = match.slice(1).map(Number);
    const date = new Date(Date.UTC(2000, month - 1, day));
    date.setUTCFullYear(year);

    return (
        year >= 1 &&
        date.getUTCFullYear() === year &&
        date.getUTCMonth() === month - 1 &&
        date.getUTCDate() === day
    );
}

function integerIn(value: InputValue, min: number, max: number): number | null {
    const text = String(value).trim();
    if (!/^\d{1,10}$/.test(text)) {
        return null;
    }
    const parsed = Number(text);

    return parsed >= min && parsed <= max ? parsed : null;
}

/**
 * Client-side mirror of the server rules: field → i18n message key. An
 * empty object means the draft can be submitted.
 */
export function validateDraft(
    draft: ScheduleDraft,
): Partial<Record<ScheduleField, string>> {
    const errors: Partial<Record<ScheduleField, string>> = {};
    const prefix = 'envelopes.schedules.validation';

    const amount = parseAmountInput(draft.amount);
    if (amount === null || amount > MAX_AMOUNT_MINOR_UNITS) {
        errors.amount = `${prefix}.amount`;
    }
    if (!isCalendarDate(draft.startDate)) {
        errors.start_date = `${prefix}.startDate`;
    }
    if (draft.endDate !== '') {
        if (!isCalendarDate(draft.endDate)) {
            errors.end_date = `${prefix}.endDate`;
        } else if (!errors.start_date && draft.endDate < draft.startDate) {
            errors.end_date = `${prefix}.endBeforeStart`;
        }
    }
    if (usesWeekdays(draft.period) && draft.weekdays.length === 0) {
        errors.weekdays = `${prefix}.weekdays`;
    }
    if (
        usesDayOfMonth(draft.period) &&
        integerIn(draft.dayOfMonth, 1, 31) === null
    ) {
        errors.day_of_month = `${prefix}.dayOfMonth`;
    }
    if (draft.period === 'yearly' && integerIn(draft.month, 1, 12) === null) {
        errors.month = `${prefix}.month`;
    }
    if (
        draft.reminder === 'daysBefore' &&
        integerIn(draft.reminderDays, 1, MAX_REMINDER_DAYS) === null
    ) {
        errors.reminder_days = `${prefix}.reminderDays`;
    }

    return errors;
}

/**
 * The API payload of a valid draft: the complete definition, with the
 * fields the period does not use emptied (the server rejects stale ones).
 */
export function toScheduleInput(draft: ScheduleDraft): ScheduleInput {
    return {
        amount: parseAmountInput(draft.amount) ?? 0,
        period: draft.period,
        start_date: draft.startDate,
        end_date: draft.endDate === '' ? null : draft.endDate,
        weekdays: usesWeekdays(draft.period)
            ? [...draft.weekdays].sort((a, b) => a - b)
            : [],
        day_of_month: usesDayOfMonth(draft.period)
            ? Number(draft.dayOfMonth)
            : null,
        month: draft.period === 'yearly' ? Number(draft.month) : null,
        reminder_days:
            draft.reminder === 'none'
                ? null
                : draft.reminder === 'sameDay'
                  ? 0
                  : Number(draft.reminderDays),
    };
}

/** Server validation errors (`weekdays.0` → `weekdays`), first message per known field. */
export function fieldErrorsFromApi(
    errors: Record<string, string[]>,
): Partial<Record<ScheduleField, string>> {
    const result: Partial<Record<ScheduleField, string>> = {};
    for (const [key, messages] of Object.entries(errors)) {
        const field = key.split('.')[0] as ScheduleField;
        if (FIELDS.includes(field) && !result[field] && messages[0]) {
            result[field] = messages[0];
        }
    }

    return result;
}
