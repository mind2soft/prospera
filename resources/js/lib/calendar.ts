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
