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
    { code: 'CAD', label: 'Canadian Dollar' },
    { code: 'USD', label: 'US Dollar' },
    { code: 'EUR', label: 'Euro' },
    { code: 'GBP', label: 'British Pound' },
    { code: 'CHF', label: 'Swiss Franc' },
    { code: 'AUD', label: 'Australian Dollar' },
    { code: 'JPY', label: 'Japanese Yen' },
    { code: 'CNY', label: 'Chinese Yuan' },
] as const;

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
