export const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
] as const;

export const CURRENCIES = [
    { code: 'CAD' },
    { code: 'USD' },
    { code: 'EUR' },
    { code: 'GBP' },
    { code: 'CHF' },
    { code: 'AUD' },
    { code: 'JPY' },
    { code: 'CNY' },
] as const;

const currencyDisplayNames = new Map<string, Intl.DisplayNames>();

/**
 * The localized display name of a currency code, e.g. "Canadian Dollar"
 * for CAD in English (falls back to the raw code).
 */
export function currencyName(code: string, locale: string): string {
    let displayNames = currencyDisplayNames.get(locale);

    if (!displayNames) {
        displayNames = new Intl.DisplayNames([locale], { type: 'currency' });
        currencyDisplayNames.set(locale, displayNames);
    }

    try {
        return displayNames.of(code) ?? code;
    } catch {
        return code;
    }
}

const formatters = new Map<string, Intl.NumberFormat>();

/**
 * Formats an absolute amount with the budget's currency, e.g. "$1,300".
 */
export function formatMoney(amount: number, currency: string): string {
    const key = `${currency}`;
    let formatter = formatters.get(key);

    if (!formatter) {
        try {
            formatter = new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency,
                currencyDisplay: 'narrowSymbol',
                maximumFractionDigits: 0,
            });
        } catch {
            formatter = new Intl.NumberFormat(undefined, {
                maximumFractionDigits: 0,
            });
        }
        formatters.set(key, formatter);
    }

    return formatter.format(Math.abs(amount));
}

/**
 * Human label for a budget: "New Budget (draft)" or "May 2026".
 */
export function budgetLabel(budget: {
    published_at: string | null;
    effective_year: number;
    effective_month: number;
}): string {
    if (budget.published_at === null) {
        return 'New Budget (draft)';
    }

    return `${MONTH_NAMES[budget.effective_month - 1] ?? budget.effective_month} ${budget.effective_year}`;
}
