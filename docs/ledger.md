# Ledger

A ledger provides a yearly financial record of transactions. Paired with
budgets, it helps adjust expenses so income and spending either balance out,
or yield dividends.

## Concepts

- **Ledger** — a yearly financial record spanning a `start_date` → `end_date`
  range, with a **starting balance** (cash, investments, debts) and an
  **open**/**closed** state.
- **Transaction** — a money movement recorded against one of the user's
  envelopes — envelopes are the **only** requirement; budgets are only
  necessary for reporting (see below). Stored in the ledger.
- **Closed ledger** — a ledger that is read-only: transactions can no longer
  be added or deleted. Closed manually via the actions menu (and can be
  re-opened).

## Data Schema

```typescript
type Ledger = {
    id: number; // PK
    profile_id: number; // FK: financial_profiles.id
    name: string; // e.g. "2026"
    start_date: string; // ISO date, e.g. "2026-01-01"
    end_date: string; // ISO date, e.g. "2026-12-31"
    starting_balance: number; // signed: positive = cash/assets, negative = debt
    closed_at: string | null; // ISO date time; null = open
    status: 'open' | 'closed'; // derived from closed_at (serialized enum)
    created_at: string; // ISO date time
};

type ResourcePermission = 'owner' | 'contributor' | 'viewer';

type LedgerPermission = {
    ledger_id: number; // FK: ledgers.id (table: ledger_permissions)
    user_id: number; // FK: users.id — must be a profile member
    permission: ResourcePermission;
    created_at: string; // ISO date time
    updated_at: string; // ISO date time
};

type Transaction = {
    id: number; // PK
    ledger_id: number; // FK: ledgers.id
    user_id: number; // FK: users.id, nullable with nullOnDelete — who entered the transaction
    envelope_id: number; // FK: envelopes.id
    amount: number; // same number rule as for BudgetEnvelope
    event_at: string; // ISO date time, stored at 00:00:00
    created_at: string; // ISO date time
};

// The transaction payload also carries the envelope's display info:
// { name, color, direction } and the entering user's { id, name }
// ("Former member" for deleted users).
```

## Storage Conventions

### Amount sign convention

Same as for budgets: user input is always a **positive integer**; the server
negates amounts for envelopes with direction `expense`, so arithmetic works
naturally in SQL. Revenues are stored positive.

### Starting balance

The starting balance represents the sum of cash, investments, and debts.
Because the app only accepts positive numbers, the creation form asks for a
positive amount plus a **balance type** selector — `cash` (positive) or
`debt` (negative). In the creation payload `starting_balance` is always sent
**positive**; `starting_balance_type` (`cash` | `debt`) selects the stored
sign — the server negates the amount for `debt`. It is stored at ledger
level and surfaces at closing time and in future dashboards/analytics.

### Allocation reporting

Transactions are **independent of budgets** — no budget is required to
record them, and they never reference one (there is no `budget_id` on
transactions). Since budgets are bound to a month and year (see
[Budget — Budget lifecycle](budget.md#budget-lifecycle)), allocation
reporting simply matches transactions to the **published budget of their
month**: for a displayed month, "remaining allocated" is the envelope's
allocated amount on that month's published budget minus the transactions
recorded against that envelope within the displayed month. Months without
a published budget (or envelopes without an allocation on it) report as
unallocated ("remaining allocated" shows as `—`).

- Deleting a budget does not affect transactions (data is independent).
- **Envelopes referenced by transactions cannot be deleted.**

### Ledger lifecycle

- Ledgers are scoped to the **current profile** (`profile_id`) and visible to
  the profile's members per resource permissions — members have read-only
  visibility by default (see [Shared Finances](shared-finances.md) and
  [Permissions](#permissions)).
- **Open** ledgers accept transactions as long as the event date falls within
  the ledger's range — **no entry can be entered past the ledger's end date**.
- **Closing** is a manual action (ledger actions menu). Closed ledgers are
  read-only: transactions can no longer be added or deleted. A closed ledger
  can be re-opened.
- **Deleting a ledger deletes ALL of its transactions** (cascade). The UI
  always warns about this.

### Ownership

The ledger creator is the owner — there is no `owner_id` column on `ledgers`;
ownership is the `'owner'` row in `ledger_permissions`. Ownership can be
transferred from the Share drawer to a profile member; the previous owner
becomes `contributor`. When the owner is removed from the profile or their
account is deleted, the ledger auto-transfers to the profile owner (see
[Shared Finances](shared-finances.md)).

## Permissions

| Permission    | Ledger                                                       | Transactions                                                                                                     |
| ------------- | ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------- |
| `owner`       | Full control (create, close/reopen, share, transfer, delete) | Can add transactions — only to envelopes on which they are `contributor`+; can delete **any** transaction        |
| `contributor` | Read-only on the ledger itself (no close/delete)             | Can add transactions — only to envelopes on which they are `contributor`+; can delete **only their own** entries |
| `viewer`      | Read-only                                                    | Read-only (no add, no delete)                                                                                    |

- **Profile members can view ledgers by default** (viewer-equivalent), even
  without a permission row.
- **Only the owner can add transactions**, unless a member is explicitly
  granted `contributor` on the ledger — and **everyone**, the ledger owner
  included, may only add transactions to envelopes on which they are
  `contributor`+ (see [Envelope](envelope.md)).
- **Only the profile owner can create ledgers**; collaborators get `403`, and
  non-members (or unknown profiles) get `404`.
- **Transaction deletion** (open ledgers only): the ledger owner can delete
  any transaction; a ledger contributor can delete only their own entries;
  viewers cannot delete.
- **Amount masking** — transaction rows on envelopes the user lacks
  envelope-`viewer`+ permission on show the envelope's metadata with masked
  amounts (`—`) and masked "remaining allocated"; the server masks them
  (amount `null` + `masked` flag) — raw amounts never reach such clients (see
  [Envelope](envelope.md)). Budget planned amounts are budget data and are
  not masked by envelope permissions.

## API

All endpoints require authentication (session + CSRF). The frontend consumes
them via **vue-query** — this is an application-wide requirement for server
data fetching.

| Method   | Path                                       | Description                                                                                                                                                                                                                                    |
| -------- | ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET`    | `/api/ledgers`                             | List ledgers of the given `profile_id` (optional query param; the current profile is tracked client-side) — without it, ledgers of all the caller's profiles (newest first)                                                                    |
| `POST`   | `/api/ledgers`                             | Create a ledger in the current profile (`name`, `start_date`, `end_date`, `starting_balance` — always sent positive, `starting_balance_type` = `cash` \| `debt` selects the stored sign; profile owner only)                                   |
| `DELETE` | `/api/ledgers/{ledger}`                    | Delete the ledger **and all of its transactions** (owner only)                                                                                                                                                                                 |
| `POST`   | `/api/ledgers/{ledger}/close`              | Close the ledger (read-only; owner only)                                                                                                                                                                                                       |
| `POST`   | `/api/ledgers/{ledger}/reopen`             | Re-open a closed ledger (owner only)                                                                                                                                                                                                           |
| `GET`    | `/api/ledgers/{ledger}/transactions`       | Transactions filtered by `from_date` and `to_date` (query params). Rows on envelopes the requester lacks envelope-`viewer`+ permission on are **masked server-side**: `amount: null` + `masked: true` (metadata only — name, color, direction) |
| `POST`   | `/api/ledgers/{ledger}/transactions`       | Add a transaction (`envelope_id`, `amount`, `event_at`) — ledger owner or contributor, who must also be contributor+ on the target envelope                                                                                                    |
| `DELETE` | `/api/transactions/{transaction}`          | Delete a transaction (open ledgers only; ledger owner: any transaction; ledger contributor: own entries only; viewer: none)                                                                                                                    |
| `GET`    | `/api/ledgers/{ledger}/shares`             | List shares (profile members only)                                                                                                                                                                                                             |
| `POST`   | `/api/ledgers/{ledger}/shares`             | Assign a permission to a **profile member** (`user_id`, `permission` — `owner` is rejected with a validation error; use transfer-ownership)                                                                                                    |
| `PATCH`  | `/api/ledgers/{ledger}/shares/{userId}`    | Change a member's permission (owner only; `owner` is rejected — use transfer-ownership)                                                                                                                                                        |
| `DELETE` | `/api/ledgers/{ledger}/shares/{userId}`    | Revoke a share                                                                                                                                                                                                                                 |
| `POST`   | `/api/ledgers/{ledger}/transfer-ownership` | Transfer ownership to a member (`user_id`); the previous owner becomes `contributor`                                                                                                                                                           |

## User Interface

### Creating a ledger

The user must create a ledger by providing:

- a **starting date** (defaults to the current day),
- an **end date** (defaults to the last day of the year),
- a **name** (defaults to the full year),
- a **starting balance** with a **cash/debt** selector (amounts are only
  entered as positive numbers; `0` is allowed; see
  [Starting balance](#starting-balance)).

### Selector row

Once a ledger is created, a dropdown shows the current and past ledgers with
their states: **Open** or **Closed**. Next to it, like for the budget
interface, an actions menu with:

- **Close / Reopen** — toggles the ledger's read-only state.
- **Delete** — deletes the ledger (and all of its transactions); asks for
  confirmation.
- **Share** — a Google Drive-like drawer (right side, not a dialog) listing
  the profile's members with their permission, allowing permission changes and
  ownership transfer (see [Shared Finances](shared-finances.md)).

### Calendar + transactions table

Underneath, a full-month calendar (sticky, on the left) and a table of
transactions for that month to the right. Changing month changes the
transactions in the table to the selected month.

### Adding a transaction

Entering a transaction should be as easy as selecting one of the user's
envelopes and entering an amount — no budget is required. The envelope picker
client-side filters `GET /api/envelopes` (see [Envelope](envelope.md)) to
entries with `permission` `owner` or `contributor`. The transaction date
(`event_at`) defaults to today; the month calendar doubles as the date
picker — clicking a day selects it. Time is irrelevant: transactions are
stored at `00:00:00`.

The table (sorted chronologically ascending) shows:

- the day of the transaction (`event_at`),
- the envelope name (colour-coded chip),
- the amount (positive for revenue, negative for expenses) — **masked** (`—`)
  for envelopes the user lacks envelope-`viewer`+ permission on
  (server-masked; see [Permissions](#permissions)),
- the **remaining envelope's allocated budget** — allocated amount on the
  displayed month's published budget minus transactions for that envelope
  within the displayed month — masked (`—`) under the same rule, and also
  `—` when the envelope is unallocated for that month (see
  [Allocation reporting](#allocation-reporting)),
- the user avatar (user name and formatted `created_at` as tooltip) —
  "Former member" for deleted users,
- a delete button to remove the transaction (open ledgers only; the ledger
  owner can delete any transaction, a ledger contributor only their own
  entries).

### Read-only states

- **Closed ledger** — an amber banner states the ledger is read-only; the
  entry form and delete buttons are hidden.
- **No envelopes** — when the user has no envelope they may record against,
  an informational banner states that envelopes are required to record
  transactions, with a link to manage envelopes.
- **Insufficient permission** — members without the right to add transactions
  (viewer, or contributor lacking envelope permission) get the entry form
  hidden with an informational banner.

## UX Conventions

Same as the budget page: reusable shadcn-vue components, Iconify (Material
Symbols) icons, automatic saving, and confirmation before irreversible
changes (ledger deletion).
