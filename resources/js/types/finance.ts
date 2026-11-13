// ---- Shared finances (docs/shared-finances.md) -----------------------------

export type ResourcePermission = 'owner' | 'contributor' | 'viewer';

/** Envelopes add "none" (metadata-only visibility). */
export type EnvelopePermission = ResourcePermission | 'none';

export type FinancialProfile = {
    id: number;
    name: string;
    is_owner: boolean;
    /** The caller's default membership flag — exactly one per user. */
    is_default: boolean;
};

export type ProfileMember = {
    id: number;
    name: string;
    email: string;
    is_owner: boolean;
    joined_at: string; // ISO date time
};

export type ProfileInvite = {
    id: number;
    email: string;
    role: string; // the role granted on acceptance — only 'collaborator' today
    awaiting_verification: boolean; // reserved by a registration pending email verification
    expires_at: string; // ISO date time
};

/** Invite context passed to the Register page via Fortify view composition. */
export type InviteContext = {
    profile: string;
    inviter: string;
    email: string;
    role: string;
};

/**
 * A profile member with their effective permission on a resource
 * (implicit viewer for members without an explicit share row). `explicit`
 * is false for those implicit viewers — they have no share row and cannot
 * be revoked.
 */
export type ResourceMember = {
    user_id: number;
    name: string;
    email: string;
    permission: ResourcePermission;
    explicit: boolean;
};

// ---- Envelope (docs/envelope.md) -----------------------------------------

export type Envelope = {
    id: number;
    name: string;
    direction: 'expense' | 'revenue';
    color: string;
    permission: EnvelopePermission;
};

// ---- Budget (docs/budget.md) -----------------------------------------------

export type BudgetEnvelopeType = 'fixed' | 'variable';

export type Budget = {
    id: number;
    profile_id: number;
    currency: string;
    effective_year: number;
    effective_month: number;
    published_at: string | null;
    created_at: string;
    permission: ResourcePermission;
};

export type BudgetEnvelope = {
    envelope_id: number;
    name: string;
    color: string;
    direction: 'expense' | 'revenue';
    amount: number; // signed: positive for revenue, negative for expense
    type: BudgetEnvelopeType;
};

export type BudgetDetail = Budget & {
    envelopes: BudgetEnvelope[];
    shares: ResourceMember[];
};

// ---- Ledger (docs/ledger.md) -----------------------------------------------

export type LedgerStatus = 'open' | 'closed';

export type Ledger = {
    id: number;
    profile_id: number;
    name: string;
    start_date: string; // ISO date, e.g. "2026-01-01"
    end_date: string; // ISO date
    starting_balance: number; // signed: positive = cash/assets, negative = debt
    closed_at: string | null;
    status: LedgerStatus;
    created_at: string;
    permission: ResourcePermission;
};

export type Transaction = {
    id: number;
    ledger_id: number;
    envelope_id: number;
    name: string; // envelope name
    color: string;
    direction: 'expense' | 'revenue';
    /** null + masked: true when the envelope is metadata-only for the user. */
    amount: number | null; // signed currency units, up to two decimal places
    masked: boolean;
    event_at: string; // ISO date "2026-10-15" (stored at 00:00:00)
    created_at: string;
    /** Allocated amount minus transactions for the month (null when masked or unallocated). */
    remaining_allocated?: number | null;
    /** id is null for deleted users ("Former member"). */
    user: { id: number | null; name: string };
};

// ---- Dashboard (docs/dashboard.md) ------------------------------------------

export type DashboardWidgetType =
    | 'envelope_spending'
    | 'income_expenses'
    | 'radial';

export type DashboardWidgetViewType =
    | 'single'
    | 'stacked_bars'
    | 'line'
    | 'pie'
    | 'donut';

export type DashboardPeriodType = 'days' | 'months';

/** Ordered envelope selection: envelope rows and flat, single-level groups. */
export type DashboardSelectionItem =
    | { kind: 'envelope'; envelope_id: number }
    | { kind: 'group'; name: string; envelope_ids: number[] };

export type DashboardWidget = {
    id: number;
    type: DashboardWidgetType;
    /** null → the client renders the localized default title. */
    title: string | null;
    view_type: DashboardWidgetViewType;
    /** 1..5 columns of the xl grid; clamped to the viewport's column count. */
    column_width: number;
    period: { type: DashboardPeriodType; range: number };
    selection: DashboardSelectionItem[];
    /** Display order (drag & drop). */
    order: number;
};

/**
 * A widget configuration being edited. Saved widgets carry their server id;
 * widgets added but not saved yet carry a negative, client-side temporary id.
 */
export type DashboardWidgetConfig = Omit<DashboardWidget, 'order'>;

/** A widget in the full-layout save payload: `id` only for saved widgets (in-place updates). */
export type DashboardWidgetPayload = Omit<DashboardWidgetConfig, 'id'> & {
    id?: number;
};

export type DashboardSpendingRow = {
    key: string;
    kind: 'envelope' | 'group';
    envelope_id?: number;
    name: string;
    color?: string;
    direction?: 'expense' | 'revenue';
    masked: boolean;
    /** Signed sum of recorded transactions (null when masked). */
    spent: number | null;
    /** Summed allocations on the published budgets covering the period (null = no progress). */
    allocated: number | null;
};

export type DashboardHistoryBucket = {
    bucket: string; // "2026-11-10" for days periods, "2026-11" for months
    masked: boolean;
    revenue: number | null;
    expenses: number | null;
};

export type DashboardRadialRow = {
    key: string;
    kind: 'envelope' | 'group';
    envelope_id?: number;
    name: string;
    color?: string;
    masked: boolean;
    value: number | null; // |a|
};

export type DashboardWidgetData =
    | { type: 'envelope_spending'; rows: DashboardSpendingRow[] }
    | {
          type: 'income_expenses';
          masked: boolean;
          series: DashboardHistoryBucket[];
      }
    | { type: 'radial'; rows: DashboardRadialRow[] };
