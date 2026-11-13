import { api } from '@/lib/api';
import * as onboardingProgressRoute from '@/routes/api/onboarding';

export type OnboardingProgress = {
    revenue_envelopes: number;
    expense_envelopes: number;
    missing_revenue_envelopes: number;
    missing_expense_envelopes: number;
    can_create_ledger: boolean;
    history_days: number;
    history_days_remaining: number;
    envelopes_with_transactions: number;
    envelopes_missing_transactions: number;
    first_budget_created: boolean;
    can_create_budget: boolean;
};

export const onboardingApi = {
    progress: (profileId: number): Promise<{ progress: OnboardingProgress }> =>
        api(
            onboardingProgressRoute.progress({
                query: { profile_id: profileId },
            }),
        ),
};
