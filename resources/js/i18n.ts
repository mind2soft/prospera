import { createI18n } from 'vue-i18n';
import { messages } from '@/locales';

/**
 * The fallback locale: used when the user has no preferred locale and the
 * browser locales don't match any available translation.
 */
export const FALLBACK_LOCALE = 'en';

export const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: FALLBACK_LOCALE,
    fallbackLocale: FALLBACK_LOCALE,
    messages,
    // French treats 0 and 1 as singular ("0 enveloppe", "1 enveloppe").
    pluralRules: {
        fr: (choice: number, choicesLength: number): number =>
            choicesLength === 2
                ? Number(Math.abs(choice) >= 2)
                : Math.min(Math.abs(choice), choicesLength - 1),
    },
    missingWarn: false,
    fallbackWarn: false,
});
