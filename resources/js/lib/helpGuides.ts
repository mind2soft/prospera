import type { StageId } from '@/lib/onboardingJourney';

/** The four field guides, in reading order (docs/onboarding.md). */
export const GUIDE_TOPICS = [
    'envelopes',
    'ledgers',
    'transactions',
    'budgets',
] as const;

export type GuideTopic = (typeof GUIDE_TOPICS)[number];

export type Guide = {
    topic: GuideTopic;
    /** The setup stage this guide teaches. */
    stage: StageId;
    /** Reading time in minutes (checked against the French copy too). */
    minutes: number;
    related: GuideTopic;
};

export const GUIDES: readonly Guide[] = [
    { topic: 'envelopes', stage: 'envelopes', minutes: 2, related: 'budgets' },
    { topic: 'ledgers', stage: 'ledger', minutes: 2, related: 'transactions' },
    {
        topic: 'transactions',
        stage: 'challenge',
        minutes: 2,
        related: 'envelopes',
    },
    { topic: 'budgets', stage: 'budget', minutes: 2, related: 'ledgers' },
];

export type IllustrationName =
    | 'envelopeFlow'
    | 'ledgerPeriod'
    | 'transactionFlow'
    | 'budgetDial';

export const GUIDE_ILLUSTRATIONS: Record<GuideTopic, IllustrationName> = {
    envelopes: 'envelopeFlow',
    ledgers: 'ledgerPeriod',
    transactions: 'transactionFlow',
    budgets: 'budgetDial',
};

export const guideFor = (topic: GuideTopic): Guide =>
    GUIDES.find((guide) => guide.topic === topic) ?? GUIDES[0];

export const guideIndex = (topic: GuideTopic): number =>
    GUIDE_TOPICS.indexOf(topic);

/** Localized suggestions (`onboarding.starter.{direction}.{key}`). */
export const STARTER_ENVELOPES = {
    revenue: ['paycheck', 'sideIncome'],
    expense: [
        'housing',
        'groceries',
        'gas',
        'insurance',
        'phoneInternet',
        'utilities',
        'restaurants',
        'savings',
    ],
} as const;

export type StarterDirection = keyof typeof STARTER_ENVELOPES;

/** Palette colours (Envelopes page) used by the static examples. */
export const EXAMPLE_COLORS = {
    paycheck: '#10b981',
    groceries: '#f59e0b',
    gas: '#8b5cf6',
    restaurants: '#f43f5e',
} as const;

/**
 * Fixed example amounts for the guides — never user data. Always labelled
 * "Example" and formatted in the active locale.
 */
export const EXAMPLES = {
    ledger: { start: 1200, close: 2050 },
    transactions: [
        {
            key: 'paycheck',
            direction: 'revenue',
            amount: 2400,
            daysAgo: 2,
        },
        {
            key: 'groceries',
            direction: 'expense',
            amount: 86.45,
            daysAgo: 1,
        },
        { key: 'gas', direction: 'expense', amount: 52, daysAgo: 0 },
    ],
    budget: [
        { key: 'groceries', planned: 500, spent: 430 },
        { key: 'gas', planned: 200, spent: 180 },
        { key: 'restaurants', planned: 150, spent: 190 },
    ],
} as const;
