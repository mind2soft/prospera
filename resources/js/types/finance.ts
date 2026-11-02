// ---- Shared finances (docs/shared-finances.md) -----------------------------

export type ResourcePermission = 'owner' | 'contributor' | 'viewer';

/** Envelopes add "none" (metadata-only visibility). */
export type EnvelopePermission = ResourcePermission | 'none';

export type FinancialProfile = {
    id: number;
    name: string;
    is_owner: boolean;
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
    awaiting_verification: boolean; // reserved by a registration pending email verification
    expires_at: string; // ISO date time
};

/** Invite context passed to the Register page via Fortify view composition. */
export type InviteContext = {
    profile: string;
    inviter: string;
    email: string;
};

/**
 * A profile member with their effective permission on a resource
 * (implicit viewer for members without an explicit share row).
 */
export type ResourceMember = {
    user_id: number;
    name: string;
    email: string;
    permission: ResourcePermission;
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
    amount: number | null;
    masked: boolean;
    event_at: string; // ISO date "2026-10-15" (stored at 00:00:00)
    created_at: string;
    /** Allocated amount minus transactions for the month (null when masked or unallocated). */
    remaining_allocated?: number | null;
    /** id is null for deleted users ("Former member"). */
    user: { id: number | null; name: string };
};
