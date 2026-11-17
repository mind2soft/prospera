import { beforeAll, describe, expect, it, vi } from 'vitest';
import type { Translate } from '@/lib/schedule';
import {
    definedFields,
    describeAffected,
    describeSchedule,
    draftFromSchedule,
    fieldErrorsFromApi,
    formatAmountInput,
    isCalendarDate,
    parseAmountInput,
    type ScheduleDraft,
    toScheduleInput,
    validateDraft,
    withStartDefaults,
} from '@/lib/schedule';
import type { EnvelopeSchedule } from '@/types';

/**
 * `@/i18n` pulls in Vue's runtime-dom, which needs a document in the plain
 * Node environment (see statusMessage.test.ts): stub it, then import the
 * app's real catalogs and plural rules.
 */
vi.stubGlobal('document', {
    querySelector: () => null,
    createElement: () => ({}),
});

let translate: (locale: string) => Translate;

beforeAll(async () => {
    const { i18n } = await import('@/i18n');
    translate = (locale) => (key, named, plural) => {
        i18n.global.locale.value = locale;

        return plural === undefined
            ? i18n.global.t(key, named ?? {})
            : i18n.global.t(key, named ?? {}, plural);
    };
});

const schedule = (
    overrides: Partial<EnvelopeSchedule> = {},
): EnvelopeSchedule => ({
    id: 1,
    envelope_id: 1,
    amount: -12345,
    period: 'monthly',
    start_date: '2026-03-01',
    end_date: null,
    weekdays: [],
    day_of_month: 15,
    month: null,
    reminder_days: null,
    reminder_unit: null,
    created_at: null,
    updated_at: null,
    next_occurrences: [],
    open_ledger_entries: 0,
    ...overrides,
});

const summary = (overrides: Partial<EnvelopeSchedule>, locale = 'en') =>
    describeSchedule(schedule(overrides), locale, translate(locale));

describe('describeSchedule()', () => {
    it('summarizes bi-weekly weekdays in order with a start date', () => {
        const result = summary({
            period: 'biweekly',
            weekdays: [4, 1],
            day_of_month: null,
        });

        expect(result.recurrence).toBe('Bi-weekly on Mon and Thu');
        expect(result.range).toBe('From Mar 1, 2026');
    });

    it('lists three weekdays and shows an end date', () => {
        const result = summary({
            period: 'weekly',
            weekdays: [5, 1, 3],
            day_of_month: null,
            end_date: '2026-12-31',
        });

        expect(result.recurrence).toBe('Weekly on Mon, Wed, and Fri');
        expect(result.range).toBe('From Mar 1, 2026 to Dec 31, 2026');
    });

    it('describes one-off, monthly and yearly rules, noting clamped days', () => {
        expect(summary({ period: 'once', day_of_month: null }).recurrence).toBe(
            'Once on Mar 1, 2026',
        );
        expect(summary({ period: 'once', day_of_month: null }).range).toBe(
            null,
        );
        expect(summary({}).recurrence).toBe('Monthly on day 15');
        expect(summary({ day_of_month: 31 }).recurrence).toBe(
            'Monthly on day 31 (or the last day of shorter months)',
        );
        expect(summary({ period: 'yearly', month: 3 }).recurrence).toBe(
            'Yearly on March 15',
        );
        expect(
            summary({ period: 'yearly', month: 2, day_of_month: 29 })
                .recurrence,
        ).toBe("Yearly on February 29 (or the month's last day)");
    });

    it('pluralizes reminders and lists upcoming dates', () => {
        expect(summary({}).reminder).toBe('No reminder');
        expect(summary({ reminder_days: 0 }).reminder).toBe(
            'Remind on the day',
        );
        expect(summary({ reminder_days: 1 }).reminder).toBe(
            'Remind 1 day before',
        );
        expect(summary({ reminder_days: 3 }).reminder).toBe(
            'Remind 3 days before',
        );
        expect(summary({ reminder_days: 1000 }).reminder).toBe(
            'Remind 1,000 days before',
        );
        expect(
            summary({ reminder_days: 1, reminder_unit: 'month' }).reminder,
        ).toBe('Remind 1 month before');
        expect(
            summary({ reminder_days: 2, reminder_unit: 'month' }).reminder,
        ).toBe('Remind 2 months before');
        expect(
            summary({ reminder_days: 12, reminder_unit: 'month' }).reminder,
        ).toBe('Remind 12 months before');
        expect(summary({}).upcoming).toBe('No upcoming dates');
        expect(
            summary({ next_occurrences: ['2026-03-15', '2026-04-15'] })
                .upcoming,
        ).toBe('Next: Mar 15, Apr 15');
    });

    it('formats with the French locale and its plural rules', () => {
        const result = summary(
            {
                period: 'biweekly',
                weekdays: [1, 4],
                day_of_month: null,
                end_date: '2026-12-31',
                reminder_days: 1,
            },
            'fr',
        );

        expect(result.recurrence).toBe(
            'Toutes les deux semaines : lun. et jeu.',
        );
        expect(result.range).toBe('Du 1 mars 2026 au 31 déc. 2026');
        expect(result.reminder).toBe('Rappel 1 jour avant');
        expect(summary({ reminder_days: 2 }, 'fr').reminder).toBe(
            'Rappel 2 jours avant',
        );
        expect(
            summary({ reminder_days: 1, reminder_unit: 'month' }, 'fr')
                .reminder,
        ).toBe('Rappel 1 mois avant');
        expect(
            summary({ reminder_days: 3, reminder_unit: 'month' }, 'fr')
                .reminder,
        ).toBe('Rappel 3 mois avant');
        expect(summary({ period: 'yearly', month: 3 }, 'fr').recurrence).toBe(
            'Chaque année, le 15 mars',
        );
    });
});

describe('describeAffected()', () => {
    it('pluralizes and formats the count per locale', () => {
        expect(describeAffected(1, 'en', translate('en'))).toBe(
            'Affects ~1 ledger entry in open ledgers.',
        );
        expect(describeAffected(1200, 'en', translate('en'))).toBe(
            'Affects ~1,200 ledger entries in open ledgers.',
        );
        expect(describeAffected(1200, 'fr', translate('fr'))).toBe(
            `Touche environ ${new Intl.NumberFormat('fr').format(1200)} écritures dans les registres ouverts.`,
        );
    });
});

describe('amount conversion', () => {
    it('parses currency input into exact minor units without float math', () => {
        expect(parseAmountInput('123.45')).toBe(12345);
        expect(parseAmountInput('0.29')).toBe(29);
        expect(parseAmountInput('1.1')).toBe(110);
        expect(parseAmountInput('5')).toBe(500);
        expect(parseAmountInput('0')).toBe(0);
        expect(parseAmountInput('999999999.99')).toBe(99_999_999_999);
        // A number input's model is a number: 0.29 * 100 is 28.999… in
        // floating point, the digits are not.
        expect(parseAmountInput(0.29)).toBe(29);
        expect(parseAmountInput(5.1)).toBe(510);
        expect(parseAmountInput(1.005)).toBeNull();
        for (const invalid of ['', '-1', '1.234', '01', '1e3', 'abc', '.5']) {
            expect(parseAmountInput(invalid)).toBeNull();
        }
    });

    it('formats minor units back for editing, ignoring the sign', () => {
        expect(formatAmountInput(-12345)).toBe('123.45');
        expect(formatAmountInput(500)).toBe('5');
        expect(formatAmountInput(-7)).toBe('0.07');
        expect(formatAmountInput(29)).toBe('0.29');
    });
});

describe('dialog drafts', () => {
    const valid = (overrides: Partial<ScheduleDraft> = {}): ScheduleDraft => ({
        ...draftFromSchedule(null, '2026-03-04'),
        amount: '25',
        ...overrides,
    });

    it('defaults a new schedule from today', () => {
        expect(draftFromSchedule(null, '2026-03-04')).toMatchObject({
            period: 'monthly',
            startDate: '2026-03-04',
            weekdays: [3],
            dayOfMonth: '4',
            month: '3',
            reminder: 'none',
        });
    });

    it('derives weekday, day and month from the start date until set', () => {
        const draft = draftFromSchedule(null, '2026-03-04');
        expect(
            withStartDefaults(
                { ...draft, startDate: '2090-03-01' },
                definedFields(null),
            ),
        ).toMatchObject({ weekdays: [3], dayOfMonth: '1', month: '3' });

        const touched = new Set(['dayOfMonth'] as const);
        expect(
            withStartDefaults(
                { ...draft, dayOfMonth: '20', startDate: '2090-12-25' },
                touched,
            ),
        ).toMatchObject({ weekdays: [1], dayOfMonth: '20', month: '12' });

        // An incomplete date changes nothing.
        expect(withStartDefaults({ ...draft, startDate: '' }, touched)).toEqual(
            { ...draft, startDate: '' },
        );

        // An existing schedule keeps the fields its period defines.
        const weekly = schedule({
            period: 'weekly',
            weekdays: [1, 4],
            day_of_month: null,
        });
        expect(
            withStartDefaults(
                {
                    ...draftFromSchedule(weekly, '2026-03-04'),
                    startDate: '2090-03-15',
                },
                definedFields(weekly),
            ),
        ).toMatchObject({ weekdays: [1, 4], dayOfMonth: '15', month: '3' });
    });

    it('round-trips an existing schedule', () => {
        const existing = schedule({
            period: 'weekly',
            weekdays: [1, 4],
            day_of_month: null,
            reminder_days: 3,
            reminder_unit: null,
            end_date: '2026-06-30',
        });

        expect(
            toScheduleInput(draftFromSchedule(existing, '2026-03-04')),
        ).toEqual({
            amount: 12345,
            period: 'weekly',
            start_date: '2026-03-01',
            end_date: '2026-06-30',
            weekdays: [1, 4],
            day_of_month: null,
            month: null,
            reminder_days: 3,
            reminder_unit: 'day',
        });
    });

    it('maps preset reminder leads to their stored (days, unit) pair', () => {
        // Legacy rows (null unit) count days and still map to a preset.
        expect(
            draftFromSchedule(
                schedule({ reminder_days: 3, reminder_unit: null }),
                '2026-03-04',
            ).reminder,
        ).toBe('day3');
        expect(
            draftFromSchedule(
                schedule({ reminder_days: 7, reminder_unit: null }),
                '2026-03-04',
            ).reminder,
        ).toBe('week1');
        // Presets with the month unit round-trip through the selector.
        expect(
            draftFromSchedule(
                schedule({ reminder_days: 1, reminder_unit: 'month' }),
                '2026-03-04',
            ).reminder,
        ).toBe('month1');
        // A lead no preset matches keeps the free "days before" input.
        expect(
            draftFromSchedule(
                schedule({ reminder_days: 5, reminder_unit: null }),
                '2026-03-04',
            ).reminder,
        ).toBe('daysBefore');
        expect(
            draftFromSchedule(
                schedule({ reminder_days: 30, reminder_unit: null }),
                '2026-03-04',
            ).reminder,
        ).toBe('daysBefore');
    });

    it('sends only the fields the period uses', () => {
        expect(toScheduleInput(valid({ period: 'yearly' }))).toMatchObject({
            weekdays: [],
            day_of_month: 4,
            month: 3,
            reminder_days: null,
            reminder_unit: null,
        });
        expect(
            toScheduleInput(
                valid({
                    period: 'biweekly',
                    weekdays: [4, 1],
                    reminder: 'sameDay',
                }),
            ),
        ).toMatchObject({
            weekdays: [1, 4],
            day_of_month: null,
            month: null,
            reminder_days: 0,
            reminder_unit: 'day',
        });
        expect(toScheduleInput(valid({ reminder: 'month1' }))).toMatchObject({
            reminder_days: 1,
            reminder_unit: 'month',
        });
        expect(toScheduleInput(valid({ reminder: 'week2' }))).toMatchObject({
            reminder_days: 14,
            reminder_unit: 'day',
        });
        expect(toScheduleInput(valid({ period: 'once' }))).toMatchObject({
            weekdays: [],
            day_of_month: null,
            month: null,
        });
    });

    it('mirrors the server validation rules', () => {
        expect(validateDraft(valid())).toEqual({});
        expect(validateDraft(valid({ amount: '' }))).toHaveProperty('amount');
        expect(validateDraft(valid({ amount: '1.005' }))).toHaveProperty(
            'amount',
        );
        expect(validateDraft(valid({ startDate: '' }))).toHaveProperty(
            'start_date',
        );
        expect(validateDraft(valid({ endDate: '2026-03-03' }))).toEqual({
            end_date: 'envelopes.schedules.validation.endBeforeStart',
        });
        expect(validateDraft(valid({ endDate: '2026-03-04' }))).toEqual({});
        expect(
            validateDraft(valid({ period: 'weekly', weekdays: [] })),
        ).toHaveProperty('weekdays');
        expect(validateDraft(valid({ period: 'once', weekdays: [] }))).toEqual(
            {},
        );
        expect(validateDraft(valid({ dayOfMonth: '32' }))).toHaveProperty(
            'day_of_month',
        );
        expect(
            validateDraft(valid({ period: 'yearly', month: '' })),
        ).toHaveProperty('month');
        expect(
            validateDraft(valid({ reminder: 'daysBefore', reminderDays: '0' })),
        ).toHaveProperty('reminder_days');
        expect(
            validateDraft(valid({ reminder: 'daysBefore', reminderDays: '7' })),
        ).toEqual({});
        expect(
            validateDraft(
                valid({ reminder: 'daysBefore', reminderDays: '366' }),
            ),
        ).toEqual({});
        expect(
            validateDraft(
                valid({ reminder: 'daysBefore', reminderDays: '367' }),
            ),
        ).toHaveProperty('reminder_days');
    });

    it('accepts only real calendar dates', () => {
        expect(isCalendarDate('2028-02-29')).toBe(true);
        expect(isCalendarDate('2027-02-29')).toBe(false);
        expect(isCalendarDate('2026-13-01')).toBe(false);
        expect(isCalendarDate('0000-01-01')).toBe(false);
        expect(isCalendarDate('0001-01-01')).toBe(true);
        expect(isCalendarDate('2026-1-1')).toBe(false);
    });

    it('maps server errors onto dialog fields', () => {
        expect(
            fieldErrorsFromApi({
                'weekdays.0': ['Bad weekday.'],
                amount: ['Bad amount.', 'Other.'],
                timezone: ['Bad zone.'],
            }),
        ).toEqual({ weekdays: 'Bad weekday.', amount: 'Bad amount.' });
    });
});
