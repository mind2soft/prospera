import type { OnboardingProgress } from '@/lib/onboardingApi';

/**
 * The 30-day route (docs/onboarding.md): four setup stages derived from the
 * server's onboarding progress. Pure, so every surface (onboarding page,
 * journey bar, guides, dashboard) agrees on the same state.
 */
export const STAGE_IDS = [
    'envelopes',
    'ledger',
    'challenge',
    'budget',
] as const;

export type StageId = (typeof STAGE_IDS)[number];
export type StageStatus = 'done' | 'current' | 'locked';

export type JourneyStage = {
    id: StageId;
    step: number;
    status: StageStatus;
};

export const REQUIRED_REVENUE = 1;
export const REQUIRED_EXPENSE = 3;
export const CHALLENGE_DAYS = 30;

export type Journey = {
    stages: JourneyStage[];
    /** The first stage not done; null once the route is complete. */
    current: StageId | null;
    /** 1-based step of the current stage (4 when complete). */
    stepNumber: number;
    complete: boolean;
    envelopes: {
        revenue: number;
        expense: number;
        missingRevenue: number;
        missingExpense: number;
        missing: number;
        ready: boolean;
    };
    hasLedger: boolean;
    challenge: {
        /** The clock starts with the first recorded transaction. */
        started: boolean;
        /** Full days of history (0–30). */
        days: number;
        /** "Day d of 30" — today counts as the next day of the challenge. */
        day: number;
        total: number;
        daysRemaining: number;
        covered: number;
        totalEnvelopes: number;
        missing: number;
        unlockDate: Date | null;
        halfway: boolean;
        ready: boolean;
    };
    canCreateBudget: boolean;
};

export type JourneyContext = {
    /** Any budget on the profile: the server no longer gates new drafts. */
    hasBudget?: boolean;
    today?: Date;
};

export function stageHref(id: StageId, journey: Journey): string {
    switch (id) {
        case 'envelopes':
            return journey.envelopes.missingRevenue > 0
                ? '/envelopes?new=revenue'
                : '/envelopes?new=expense';
        case 'ledger':
            return journey.envelopes.ready ? '/ledger?create=1' : '/envelopes';
        case 'challenge':
            return journey.hasLedger || journey.challenge.started
                ? '/ledger#add-transaction'
                : '/ledger?create=1';
        case 'budget':
            return journey.canCreateBudget ? '/budget?create=1' : '/budget';
    }
}

/** The page a stage happens on (the journey bar's mini-route links). */
export function stagePage(id: StageId): string {
    return {
        envelopes: '/envelopes',
        ledger: '/ledger',
        challenge: '/ledger',
        budget: '/budget',
    }[id];
}

export function deriveJourney(
    progress: OnboardingProgress,
    context: JourneyContext = {},
): Journey {
    const firstBudget =
        progress.first_budget_created || context.hasBudget === true;
    const hasLedger =
        progress.has_ledger === true ||
        progress.envelopes_with_transactions > 0;
    const challengeMet =
        progress.history_days_remaining === 0 &&
        progress.envelopes_missing_transactions === 0;

    // Once the first budget exists, setup is over for good: deleting a
    // ledger or an envelope later doesn't send the owner back to step 1.
    const done: Record<StageId, boolean> = {
        envelopes: progress.can_create_ledger || firstBudget,
        ledger: hasLedger || firstBudget,
        challenge: challengeMet || firstBudget,
        budget: firstBudget,
    };

    const current = STAGE_IDS.find((id) => !done[id]) ?? null;
    const stages = STAGE_IDS.map((id, index) => ({
        id,
        step: index + 1,
        status: (done[id]
            ? 'done'
            : id === current
              ? 'current'
              : 'locked') as StageStatus,
    }));

    const started = progress.envelopes_with_transactions > 0;
    const days = Math.min(CHALLENGE_DAYS, progress.history_days);
    const today = context.today ?? new Date();
    const unlockDate =
        started && progress.history_days_remaining > 0
            ? new Date(
                  today.getFullYear(),
                  today.getMonth(),
                  today.getDate() + progress.history_days_remaining,
              )
            : null;

    return {
        stages,
        current,
        stepNumber:
            current === null
                ? STAGE_IDS.length
                : STAGE_IDS.indexOf(current) + 1,
        complete: current === null,
        envelopes: {
            revenue: progress.revenue_envelopes,
            expense: progress.expense_envelopes,
            missingRevenue: progress.missing_revenue_envelopes,
            missingExpense: progress.missing_expense_envelopes,
            missing:
                progress.missing_revenue_envelopes +
                progress.missing_expense_envelopes,
            ready: progress.can_create_ledger,
        },
        hasLedger,
        challenge: {
            started,
            days,
            day: started ? Math.min(CHALLENGE_DAYS, days + 1) : 0,
            total: CHALLENGE_DAYS,
            daysRemaining: progress.history_days_remaining,
            covered: progress.envelopes_with_transactions,
            totalEnvelopes:
                progress.envelopes_with_transactions +
                progress.envelopes_missing_transactions,
            missing: progress.envelopes_missing_transactions,
            unlockDate,
            halfway: days >= CHALLENGE_DAYS / 2,
            ready: challengeMet,
        },
        canCreateBudget: progress.can_create_budget || firstBudget,
    };
}
