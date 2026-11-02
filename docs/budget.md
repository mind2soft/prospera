# Budget

A budget represents expected spending and earnings for a single month.

## Concepts

- **Envelope** — groups transactions by purpose (e.g. mortgage, groceries); a
  named spending/income bucket (`envelopes`), owned by a user, with a
  name, a direction (`expense` or `revenue`) and a color. Envelopes are managed on the
  _Envelopes_ page and referenced by budgets.
- **Budget** — a set of envelopes with a planned amount each, for one month.
- **Draft budget** — a budget being prepared. `effective_year` and `effective_month` are `0`.
- **Published budget** — a budget in read-only mode. Publishing stamps the budget with the
  current month and year.
- **Revision** — a clone of a published budget created as a new draft, adjusted, then
  published again to replace the published budget for that month.

## Data Schema

```typescript
type Budget = {
    id: number; // PK
    profile_id: number; // FK financial_profiles.id
    currency: string; // 3-letter ISO 4217 code, e.g. "CAD"
    effective_year: number; // e.g. 2026; 0 while draft
    effective_month: number; // 1..12 (January..December); 0 while draft
    created_at: string; // ISO date time
    published_at: string | null; // ISO date time; null = draft
    permission: ResourcePermission | 'none'; // API only: the caller's effective permission
};

type ResourcePermission = 'owner' | 'contributor' | 'viewer';

type BudgetPermission = {
    budget_id: number; // FK: budgets.id (table: budget_permissions)
    user_id: number; // FK: users.id — must be a profile member
    permission: ResourcePermission;
    created_at: string; // ISO date time
    updated_at: string; // ISO date time
};

// fixed    - bills, recurrent payments, e.g. a mortgage, insurance, utilities
// variable - discretionary spending, e.g. gas, groceries, entertainment
type BudgetEnvelopeType = 'fixed' | 'variable';

type BudgetEnvelope = {
    budget_id: number; // FK: budgets.id
    envelope_id: number; // FK: envelopes.id
    amount: number; // integer; signed (see "Amount sign convention")
    type: BudgetEnvelopeType;
};
```

## Storage Conventions

### Profile scoping

Budgets are created within the **current profile** (`profile_id`) and are
visible to the profile's members per resource permissions — members have
read-only visibility by default and escalate via the Share drawer (see
[Shared Finances](shared-finances.md)).

### Amount sign convention

Amounts are **always displayed positive** and user input is enforced to be a positive
integer. They are **stored signed** so arithmetic works naturally in SQL:

- revenue envelopes (direction `revenue`) are stored positive;
- expense envelopes (direction `expense`) are stored negative.

This makes totals trivial: `SUM(amount) = net`, or
`SUM(amount WHERE envelope is revenue) - SUM(amount WHERE envelope is expense) = balance`.

The sign is derived from the envelope's direction — the client never sends a negative
number; the server negates expense amounts on write.

### Budget lifecycle

- There can only be **one draft budget** at a time (per profile).
- Creating a budget always inserts a draft (`effective_year = 0`, `effective_month = 0`,
  `published_at = null`).
- **Publishing** a draft sets `published_at = now`, `effective_year = current year` and
  `effective_month = current month`. Publishing is irreversible (read-only afterwards).
- **One published budget per profile per month**: publishing a draft for a month that
  already has a published budget **replaces** it — the previously published budget for
  that month is deleted immediately (with its shares and assignments). Transactions
  are independent of budgets and are never blocked by (or blocking) this replacement.
  The publish confirmation dialog must warn about this replacement.
- **"New Budget"** on a published budget creates a **clone** of it in draft mode
  (same currency and same envelope assignments). The clone dialog asks for
  confirmation (and the currency, pre-filled from the source budget).
- The app always shows the **latest** budget by default — newest `created_at` first.
  A dropdown lists all budgets (draft first, then published, newest first).

### Ownership

Only the **profile owner** can create, clone and publish budgets (publishing
also requires being the budget owner); collaborators get `403`, and
non-members (or unknown profiles) get `404`. The budget creator is the owner:
creating a budget inserts a `budget_permissions`
row with `permission = 'owner'`. There is no `owner_id` column on `budgets` —
ownership is the `'owner'` share row.

Ownership can be transferred from the Share drawer to a profile member; the
previous owner becomes `contributor`. When the owner is removed from the
profile or their account is deleted, the budget auto-transfers to the profile
owner (see [Shared Finances](shared-finances.md)).

### Referential integrity

- Deleting a budget cascades its shares and envelope assignments. Transactions
  are independent of budgets (see [Ledger](ledger.md)) — they never block a
  budget deletion and are unaffected by it.
- **Deleting an envelope that is referenced by any budget or transaction is
  blocked** with a validation error (data integrity is enforced, not cascaded).

## Permissions

| Permission    | Budgets                                                 | Transactions |
| ------------- | ------------------------------------------------------- | ------------ |
| `owner`       | Full control (drafts, publish, share, transfer, delete) | Full access  |
| `contributor` | Read-only on the budget itself (no drafts, no edits)    | Read-only    |
| `viewer`      | Read-only                                               | Read-only    |

The budget `contributor` role grants **nothing on transactions**: adding
transactions is tied to the ledger/envelope side — a member records
transactions through the [Ledger](ledger.md), where the ledger owner or
contributor must also be `contributor`+ on the target envelope (see
[Envelope](envelope.md)). It currently behaves like `viewer` on the budget
and exists for symmetry and future use. Profile members have read-only visibility of
the profile's budgets by default (see
[Shared Finances](shared-finances.md)).

## API

All endpoints require authentication (session + CSRF). The frontend consumes them via
**vue-query** — this is an application-wide requirement for server data fetching.

| Method   | Path                                           | Description                                                                                                                                                  |
| -------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET`    | `/api/budgets`                                 | List budgets of the given `profile_id` (optional query param; the current profile is tracked client-side) — without it, budgets of all the caller's profiles |
| `POST`   | `/api/budgets`                                 | Create a draft in the current profile (`currency` required; profile owner only)                                                                              |
| `GET`    | `/api/budgets/{budget}`                        | Budget detail with envelopes and shares                                                                                                                      |
| `DELETE` | `/api/budgets/{budget}`                        | Delete the budget (owner only; cascades shares and envelope assignments)                                                                                     |
| `POST`   | `/api/budgets/{budget}/publish`                | Publish draft (budget owner **and** profile owner)                                                                                                           |
| `POST`   | `/api/budgets/{budget}/clone`                  | Clone published budget into a draft (profile owner; `viewer`+ on the budget)                                                                                 |
| `POST`   | `/api/budgets/{budget}/envelopes`              | Assign an envelope (`envelope_id`, `type`, `amount`) to a draft — requires `contributor`+ on the envelope (`none`/`viewer` → `422` on `envelope_id`)         |
| `PATCH`  | `/api/budgets/{budget}/envelopes/{envelopeId}` | Update amount (and type)                                                                                                                                     |
| `DELETE` | `/api/budgets/{budget}/envelopes/{envelopeId}` | Remove an assignment                                                                                                                                         |
| `GET`    | `/api/budgets/{budget}/shares`                 | List shares (profile members only)                                                                                                                           |
| `POST`   | `/api/budgets/{budget}/shares`                 | Assign a permission to a **profile member** (`user_id`, `permission` — `owner` is rejected with a validation error; use transfer-ownership)                  |
| `PATCH`  | `/api/budgets/{budget}/shares/{userId}`        | Change a member's permission (owner only; `owner` is rejected — use transfer-ownership)                                                                      |
| `DELETE` | `/api/budgets/{budget}/shares/{userId}`        | Revoke a share                                                                                                                                               |
| `POST`   | `/api/budgets/{budget}/transfer-ownership`     | Transfer ownership to a member (`user_id`); the previous owner becomes `contributor`                                                                         |

## User Interface

### No budgets

The empty state shows a screen asking the user to create a new one. Creating a budget
inserts a draft and asks for the **currency** (picker in the confirmation dialog).

### Budget selector

A dropdown on the top row shows the budget list — all budgets (draft first,
then published, newest first):

- One budget (draft): `New Budget (draft)`
- One budget (published): `May 2026`
- Two budgets, one draft: `May 2026` / `New Budget (draft)`
- Two budgets: `May 2026` / `October 2026`

On the same row, to the right (`flex justify-between`), an action menu — a vertical
three-dots button — with actions for the selected budget:

- **Publish** — draft budgets only; asks confirmation.
- **New Budget** — published budgets only; creates a clone in draft mode; asks
  confirmation.
- **Share** — a Google Drive-like drawer (right side, not a dialog) listing
  the profile's members with their permission, allowing permission changes and
  ownership transfer (see [Shared Finances](shared-finances.md)).
- **Delete** — owner only; deletes the budget; asks for confirmation.

### Income vs expense bars

Underneath the selector, two rows — **Revenues** and **Expenses** — each showing a bar
segmented with the corresponding envelopes. The bars let the user see whether spending
is greater than, equal to, or less than the incomes.

```text
Revenues
 --------------------------------------------------------------------
|  |  |
 --------------------------------------------------------------------
 \ Paychecks                                \ Passive income

Expenses
 --------------------------------------------------------------------
|  |  |  |  |  | ########## |
 --------------------------------------------------------------------
 \ Mortgage               \ Food            \ Gas   \ Utilities
                                                          \ Streaming
```

- Labels are angled (single row) so they do not visually overlap.
- All segments are sorted from largest to smallest amount.
- Segments use the envelope's color.
- Both bars are normalized against the same total (the larger of the two), so the
  comparison is visual. The `###` area above illustrates the leftover space when
  income exceeds expenses (the opposite is possible too).

### Envelopes table

Below the bars, a table of envelopes: the envelope, its type (fixed or variable),
the amount assigned, the remaining sum and a delete button.

Planned amounts are **budget data**: they are visible to anyone with read
access to the budget and are **never masked** by envelope permissions —
envelope amount privacy governs transaction data only (see
[Envelope](envelope.md)).

- In draft mode the amount is editable and a button to add a new envelope is visible.
- When published, editing controls disappear and the table is view-only.
- Revenue envelopes are listed first; the first cell indicates the kind with an
  icon + colour.
- Only positive integers are allowed.
- **Left to spend** for expense rows is the running balance:
  `total income − cumulative expenses (up to and including this row)`.
- The footer row shows the total/remaining with a thumbs-up icon for a left-over or a
  warning icon when spending exceeds income.
- Variable amounts are expected values and may be re-adjusted with a budget revision
  (a new draft) — a hint below the table communicates this.

| Envelope         | Type     | Amount | Left to spend |     |
| ---------------- | -------- | ------ | ------------- | --- |
| + Paychecks      | Fixed    | 2,000  |               | x   |
| + Passive income | Variable | 1,000  |               | x   |
| - Mortgage       | Fixed    | 1,300  | 1,700         | x   |
| - Food           | Variable | 800    | 900           | x   |
| - Gas            | Variable | 300    | 600           | x   |
| - Utilities      | Fixed    | 150    | 450           | x   |
| - Streaming      | Fixed    | 30     | 420           | x   |

## UX Conventions

- Create reusable components whenever possible, based on shadcn-vue components.
  Install missing ones if necessary.
- Always use iconify (Material Symbols) for icons.
- The design is always fancy and professional. Avoid sub-headers, repetitive text, and
  noise pollution — descriptive text only for clarity.
- Changes are **always automatically saved** — no save buttons.
- The publish action only puts a budget in read-only mode.
- **Always ask before performing irreversible changes** (publishing, deletion,
  creating a new budget, etc.).

## Roadmap

Future features, in priority order:

1. **~~Transaction tracking against budgets~~ — implemented**: transactions
   are recorded in the [Ledger](ledger.md). They are independent of budgets —
   allocation reporting matches transactions to the published budget of
   their month/year.

Email-based budget sharing is replaced by profile membership + per-resource
permissions — see [Shared Finances](shared-finances.md). Profile invites and
the contributor/viewer roles are part of that current model (invites live at
the profile level; contributor/viewer are resource-level permissions).
