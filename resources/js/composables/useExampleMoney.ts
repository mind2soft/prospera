import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBudgets } from '@/composables/budgetQueries';
import { useLocale } from '@/composables/useLocale';
import { formatMoney } from '@/lib/budget';

/**
 * Formats the guides' fixed example amounts: in the profile's budget
 * currency when it has one, else the locale's example currency
 * (`onboarding.example.currency`).
 */
export function useExampleMoney() {
    const { t } = useI18n();
    const { locale } = useLocale();
    const budgetsQuery = useBudgets();

    const currency = computed(() => {
        const budgets = [...(budgetsQuery.data.value ?? [])].sort(
            (a, b) =>
                new Date(b.published_at ?? 0).getTime() -
                new Date(a.published_at ?? 0).getTime(),
        );

        return budgets[0]?.currency ?? t('onboarding.example.currency');
    });

    return {
        currency,
        format: (amount: number): string =>
            formatMoney(amount, currency.value, locale.value),
    };
}
