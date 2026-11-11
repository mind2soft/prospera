/**
 * The browser's IANA time zone (e.g. "Australia/Sydney"), sent with
 * requests whose "today" / "current month" is the user's, not the server's
 * (docs/budget.md, docs/dashboard.md). Undefined when unavailable.
 */
export function browserTimeZone(): string | undefined {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined;
    } catch {
        return undefined;
    }
}

/** The user's current month as `Y-m`, e.g. "2026-05". */
export function currentMonth(now: Date = new Date()): string {
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

/** The year and month (1–12) of a `Y-m` month, e.g. "2026-05" → [2026, 5]. */
export function parseMonth(month: string): [year: number, month: number] {
    const [year, number] = month.split('-').map(Number);

    return [year, number];
}

/** The local `Date` of a calendar date (`Y-m-d`), at midnight. */
export function parseCalendarDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
}

/** A calendar date (`Y-m-d`), formatted for the given locale. */
export function formatCalendarDate(
    date: string,
    locale: string,
    options: Intl.DateTimeFormatOptions,
): string {
    return parseCalendarDate(date).toLocaleDateString(locale, options);
}
