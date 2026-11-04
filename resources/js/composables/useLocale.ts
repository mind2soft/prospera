import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed, watchEffect } from 'vue';
import { FALLBACK_LOCALE, i18n } from '@/i18n';

export type Locale = string;

/**
 * The locales a translation bundle exists for (e.g. `['en']`).
 */
export function availableLocales(): string[] {
    return i18n.global.availableLocales;
}

/**
 * Match the first candidate that has a translation bundle, first by exact
 * match (e.g. `en-GB`), then by primary subtag (e.g. `en-GB` -> `en`).
 */
export function matchLocale(
    candidates: readonly (string | undefined | null)[],
): Locale | null {
    const available = availableLocales();

    for (const candidate of candidates) {
        if (!candidate) {
            continue;
        }

        const lower = candidate.toLowerCase();
        const exact = available.find(
            (locale) => locale.toLowerCase() === lower,
        );

        if (exact) {
            return exact;
        }

        const base = lower.split('-')[0];
        const partial = available.find(
            (locale) => locale.toLowerCase() === base,
        );

        if (partial) {
            return partial;
        }
    }

    return null;
}

/**
 * The browser's locales, most preferred first.
 */
export function browserLocales(): readonly string[] {
    if (typeof navigator === 'undefined') {
        return [];
    }

    return navigator.languages ?? [navigator.language];
}

/**
 * Resolve the active locale: the user's preferred locale when set, else the
 * best match from the browser locales, else the fallback locale.
 */
export function resolveLocale(userLocale?: string | null): Locale {
    return (
        matchLocale(userLocale ? [userLocale] : []) ??
        matchLocale(browserLocales()) ??
        FALLBACK_LOCALE
    );
}

/**
 * Keep the i18n locale in sync with the user's preference as shared by the
 * backend, falling back to the browser locales. The effect starts before
 * Inertia resolves the initial page, so the props may not be set yet.
 */
export function initializeLocale(): void {
    const page = usePage();

    watchEffect(() => {
        const user = page.props?.auth?.user;

        i18n.global.locale.value = resolveLocale(user?.locale ?? null);
    });
}

/**
 * The active locale, e.g. to locale-format dates and numbers:
 *
 *     const { locale } = useLocale();
 *     date.toLocaleDateString(locale.value, { ... });
 */
export function useLocale(): { locale: ComputedRef<Locale> } {
    return { locale: computed(() => i18n.global.locale.value) };
}
