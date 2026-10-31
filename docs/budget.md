# Budget

A budget represents expected spending and earnings for a single month.

## Concepts

- **Enveloppe** — groups transactions by purpose (e.g. mortgage, groceries); a
  named spending/income bucket (`enveloppes`), owned by a user, with a
  name, a direction (`expense` or `revenue`) and a color. Enveloppes are managed on the
  _Enveloppes_ page and referenced by budgets.
- **Budget** — a set of enveloppes with a planned amount each, for one month.
- **Draft budget** — a budget being prepared. `effective_year` and `effective_month` are `0`.
- **Published budget** — a budget in read-only mode. Publishing stamps the budget with the
  current month and year.
- **Revision** — a clone of a published budget created as a new draft, adjusted, then
  published again to replace the published budget for that month.
- **Retired budget** — a previously published budget that was replaced by a
  revision while transactions still reference it. Hidden from the budget
  selector; deleted automatically when the last referencing transaction is
  deleted.

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
    retired_at: string | null; // ISO date time; null = not retired
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
type BudgetEnveloppeType = 'fixed' | 'variable';

type BudgetEnveloppe = {
    budget_id: number; // FK: budgets.id
    enveloppe_id: number; // FK: enveloppes.id
    amount: number; // integer; signed (see "Amount sign convention")
    type: BudgetEnveloppeType;
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

- revenue enveloppes (direction `revenue`) are stored positive;
- expense enveloppes (direction `expense`) are stored negative.

This makes totals trivial: `SUM(amount) = net`, or
`SUM(amount WHERE enveloppe is revenue) - SUM(amount WHERE enveloppe is expense) = balance`.

The sign is derived from the enveloppe's direction — the client never sends a negative
number; the server negates expense amounts on write.

### Budget lifecycle

- There can only be **one draft budget** at a time (per profile).
- Creating a budget always inserts a draft (`effective_year = 0`, `effective_month = 0`,
  `published_at = null`).
- **Publishing** a draft sets `published_at = now`, `effective_year = current year` and
  `effective_month = current month`. Publishing is irreversible (read-only afterwards).
- **One published budget per profile per month**: publishing a draft for a month that
  already has a published budget **replaces** it. The previously published budget for
  that month is deleted immediately (with its shares and assignments) **unless
  transactions reference it** — in that case it becomes **retired** (hidden from
  the budget selector; see [Concepts](#concepts)) and is deleted automatically
  when the last transaction referencing it is deleted. The publish confirmation
  dialog must warn about this replacement.
- **"New Budget"** on a published budget creates a **clone** of it in draft mode
  (same currency and same enveloppe assignments). The clone dialog asks for
  confirmation (and the currency, pre-filled from the source budget).
- The app always shows the **latest** budget by default — newest `created_at` first.
  A dropdown lists all **non-retired** budgets (draft first, then published, newest
  first); retired budgets are excluded.

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

- Deleting a budget cascades its shares and enveloppe assignments, **unless
  transactions reference the budget** — then deletion is blocked with a
  validation error (data integrity is enforced, not cascaded; see
  [Ledger](ledger.md)). Retired budgets (replaced by a revision while still
  referenced) are deleted automatically when their last referencing
  transaction is deleted.
- **Deleting an enveloppe that is referenced by any budget or transaction is
  blocked** with a validation error (data integrity is enforced, not cascaded).

## Permissions

| Permission    | Budgets                                                 | Transactions |
| ------------- | ------------------------------------------------------- | ------------ |
| `owner`       | Full control (drafts, publish, share, transfer, delete) | Full access  |
| `contributor` | Read-only on the budget itself (no drafts, no edits)    | Read-only    |
| `viewer`      | Read-only                                               | Read-only    |

The budget `contributor` role grants **nothing on transactions**: adding
transactions is tied to the ledger/enveloppe side — a member records
transactions through the [Ledger](ledger.md), where the ledger owner or
contributor must also be `contributor`+ on the target enveloppe (see
[Enveloppe](enveloppe.md)). It currently behaves like `viewer` on the budget
and exists for symmetry and future use. Profile members have read-only visibility of
the profile's budgets by default (see
[Shared Finances](shared-finances.md)).

## API

All endpoints require authentication (session + CSRF). The frontend consumes them via
**vue-query** — this is an application-wide requirement for server data fetching.

| Method   | Path                                             | Description                                                                                                                                                  |
| -------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET`    | `/api/budgets`                                   | List budgets of the given `profile_id` (optional query param; the current profile is tracked client-side) — without it, budgets of all the caller's profiles |
| `POST`   | `/api/budgets`                                   | Create a draft in the current profile (`currency` required; profile owner only)                                                                              |
| `GET`    | `/api/budgets/{budget}`                          | Budget detail with enveloppes and shares                                                                                                                     |
| `DELETE` | `/api/budgets/{budget}`                          | Delete the budget (owner only; blocked when transactions reference it — retired budgets are deleted automatically)                                           |
| `POST`   | `/api/budgets/{budget}/publish`                  | Publish draft (budget owner **and** profile owner)                                                                                                           |
| `POST`   | `/api/budgets/{budget}/clone`                    | Clone published budget into a draft (profile owner; `viewer`+ on the budget)                                                                                 |
| `POST`   | `/api/budgets/{budget}/enveloppes`               | Assign an enveloppe (`enveloppe_id`, `type`, `amount`) to a draft — requires `contributor`+ on the enveloppe (`none`/`viewer` → `422` on `enveloppe_id`)     |
| `PATCH`  | `/api/budgets/{budget}/enveloppes/{enveloppeId}` | Update amount (and type)                                                                                                                                     |
| `DELETE` | `/api/budgets/{budget}/enveloppes/{enveloppeId}` | Remove an assignment                                                                                                                                         |
| `GET`    | `/api/budgets/{budget}/shares`                   | List shares (profile members only)                                                                                                                           |
| `POST`   | `/api/budgets/{budget}/shares`                   | Assign a permission to a **profile member** (`user_id`, `permission` — `owner` is rejected with a validation error; use transfer-ownership)                  |
| `PATCH`  | `/api/budgets/{budget}/shares/{userId}`          | Change a member's permission (owner only; `owner` is rejected — use transfer-ownership)                                                                      |
| `DELETE` | `/api/budgets/{budget}/shares/{userId}`          | Revoke a share                                                                                                                                               |
| `POST`   | `/api/budgets/{budget}/transfer-ownership`       | Transfer ownership to a member (`user_id`); the previous owner becomes `contributor`                                                                         |

## User Interface

### No budgets

The empty state shows a screen asking the user to create a new one. Creating a budget
inserts a draft and asks for the **currency** (picker in the confirmation dialog).

### Budget selector

A dropdown on the top row shows the budget list — **non-retired budgets only**
(draft first, then published, newest first; retired budgets are excluded):

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
- **Delete** — owner only; deletes the budget; asks for confirmation (blocked
  when transactions reference it).

### Income vs expense bars

Underneath the selector, two rows — **Revenues** and **Expenses** — each showing a bar
segmented with the corresponding enveloppes. The bars let the user see whether spending
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
- Segments use the enveloppe's color.
- Both bars are normalized against the same total (the larger of the two), so the
  comparison is visual. The `###` area above illustrates the leftover space when
  income exceeds expenses (the opposite is possible too).

### Enveloppes table

Below the bars, a table of enveloppes: the enveloppe, its type (fixed or variable),
the amount assigned, the remaining sum and a delete button.

Planned amounts are **budget data**: they are visible to anyone with read
access to the budget and are **never masked** by enveloppe permissions —
enveloppe amount privacy governs transaction data only (see
[Enveloppe](enveloppe.md)).

- In draft mode the amount is editable and a button to add a new enveloppe is visible.
- When published, editing controls disappear and the table is view-only.
- Revenue enveloppes are listed first; the first cell indicates the kind with an
  icon + colour.
- Only positive integers are allowed.
- **Left to spend** for expense rows is the running balance:
  `total income − cumulative expenses (up to and including this row)`.
- The footer row shows the total/remaining with a thumbs-up icon for a left-over or a
  warning icon when spending exceeds income.
- Variable amounts are expected values and may be re-adjusted with a budget revision
  (a new draft) — a hint below the table communicates this.

| Enveloppe        | Type     | Amount | Left to spend |     |
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

1. **~~Transaction tracking against budgets~~ — implemented**: transactions are
   recorded against the latest published budget and tracked in the
   [Ledger](ledger.md).

Email-based budget sharing is replaced by profile membership + per-resource
permissions — see [Shared Finances](shared-finances.md). Profile invites and
the contributor/viewer roles are part of that current model (invites live at
the profile level; contributor/viewer are resource-level permissions).
