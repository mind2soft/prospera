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
 * Formats an absolute amount with the budget's currency in the given
 * (active app) locale, e.g. "$1,300" in `en` or "1 300 $" in `fr`.
 */
export function formatMoney(
    amount: number,
    currency: string,
    locale: string,
): string {
    const key = `${locale}|${currency}`;
    let formatter = formatters.get(key);

    if (!formatter) {
        try {
            formatter = new Intl.NumberFormat(locale, {
                style: 'currency',
                currency,
                currencyDisplay: 'narrowSymbol',
                maximumFractionDigits: 0,
            });
        } catch {
            formatter = new Intl.NumberFormat(locale, {
                maximumFractionDigits: 0,
            });
        }
        formatters.set(key, formatter);
    }

    return formatter.format(Math.abs(amount));
}

/**
 * A budget's month in the given locale, e.g. "May 2026" / "mai 2026".
 */
export function budgetMonthLabel(
    year: number,
    month: number,
    locale: string,
): string {
    return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString(locale, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/**
 * Human label for a budget: the (translated) draft label, or its month,
 * e.g. "May 2026".
 */
export function budgetLabel(
    budget: {
        published_at: string | null;
        effective_year: number;
        effective_month: number;
    },
    locale: string,
    draftLabel: string,
): string {
    if (budget.published_at === null) {
        return draftLabel;
    }

    return budgetMonthLabel(
        budget.effective_year,
        budget.effective_month,
        locale,
    );
}
