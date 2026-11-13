# Envelope

An envelope groups transactions by purpose (e.g. mortgage, groceries) — a
named spending/income bucket owned by a user, with a name, a
direction (`expense` or `revenue`) and a color.
Envelopes are managed on the _Envelopes_ page and referenced by
[Budgets](budget.md) and [Ledgers](ledger.md).

For first-run setup, the profile owner should create at least one revenue and
three expense envelopes before opening a ledger. The setup checklist counts
the profile owner's account-owned envelopes; see [Getting started](onboarding.md).

The app uses the "envelope" spelling consistently.

## Data Schema

Backed by the `envelopes` table:

```typescript
type Envelope = {
    id: number; // PK
    user_id: number; // FK users.id — the owner (the creator)
    name: string; // max 60 chars, unique per owner
    direction: 'expense' | 'revenue'; // stored in the `type` column of the `envelopes` table
    color: string; // 7-char hex, e.g. "#6366f1" (default)
    created_at: string; // ISO date time
    updated_at: string; // ISO date time
};

type ResourcePermission = 'owner' | 'contributor' | 'viewer';

type EnvelopePermission = {
    envelope_id: number; // FK: envelopes.id
    user_id: number; // FK: users.id
    permission: ResourcePermission;
    created_at: string; // ISO date time
    updated_at: string; // ISO date time
};
```

Envelopes stay **user-owned** — they are not profile-scoped. Permission rows
are stored in an `envelope_permissions` table and can only be granted to
existing **profile members** of the owner (see
[Shared Finances](shared-finances.md)).

## Storage Conventions

- `name` is unique per owner (`unique(user_id, name)`), max 60 characters.
- `color` is a 7-character hex string; defaults to `#6366f1`.
- `direction` is stored in the `type` column of the `envelopes` table with
  the values `expense` | `revenue` (this is the DB-level name; the UI and docs
  speak of "direction").
- **Amount sign convention** — same as for budgets: amounts are always entered
  and displayed as positive integers and stored signed, derived from the
  envelope's direction (revenue positive, expense negative). See
  [Budget — Amount sign convention](budget.md#amount-sign-convention).
- **Encryption at rest** — the amounts recorded against envelopes are
  encrypted in the database; envelope totals are computed by the server from
  the decrypted amounts (see [Encryption at rest](encryption.md)).

## Permissions

| Permission    | Envelope                                                | Transactions                       |
| ------------- | ------------------------------------------------------- | ---------------------------------- |
| `owner`       | Full control (edit, share, transfer, delete)            | Can record transactions against it |
| `contributor` | Read-only on the envelope itself (no edits)             | Can record transactions against it |
| `viewer`      | Read-only (sees name, color, direction **and amounts**) | Read-only                          |

- Envelope amount privacy governs **transaction data only**: budget
  **planned** amounts are budget data and follow budget read access, not
  envelope permissions (see [Budget](budget.md)).
- Envelope permissions are **user-level** and independent of any budget or
  ledger permission. Recording a transaction requires being the ledger owner
  or contributor, who must also be `contributor`+ on the target envelope —
  the ledger owner included (see [Ledger](ledger.md)). Assigning an envelope
  to a draft budget likewise requires `contributor`+ on it (see
  [Budget](budget.md)).
- Users who do not even have `viewer` permission can still **see** the
  envelope's metadata (name, color, direction) — required to render shared
  budgets and ledgers — but must **never** see its transaction amounts or
  totals. In views showing transactions (e.g. the ledger), such rows show the
  metadata with **masked amounts** (`—`) and a masked "remaining allocated";
  the server masks them (amount `null` plus a `masked` flag) so raw amounts
  never reach clients lacking permission.
- Amount totals are computed server-side by the profile-scoped endpoints
  that show them (the ledger's transactions, the dashboard's widget data),
  which validate permissions; raw transaction data is never exposed to
  clients lacking permission.
- **Transaction data never crosses profile boundaries.** An envelope can be
  used by ledgers of several profiles of its owner, but an envelope
  permission alone never reveals transactions: they are only served
  through a ledger (or a dashboard of a profile) the caller can read. There
  is deliberately no envelope-wide transactions or totals endpoint — it
  would aggregate entries across every profile using the envelope.

## API

All endpoints require authentication (session + CSRF). The frontend consumes
them via **TanStack Query** (`@tanstack/vue-query`) — this is an application-wide requirement for server
data fetching.

| Method   | Path                                           | Description                                                                                                                                                                                                  |
| -------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET`    | `/envelopes`                                   | Envelopes page (Inertia)                                                                                                                                                                                     |
| `POST`   | `/envelopes`                                   | Create an envelope (`name`, `type` (`expense`\|`revenue`), `color`); responses expose `type` as `direction`                                                                                                  |
| `PUT`    | `/envelopes/{envelope}`                        | Update an envelope (owner only; same fields as create; direction locked once referenced)                                                                                                                     |
| `DELETE` | `/envelopes/{envelope}`                        | Delete an envelope (owner only; blocked when referenced)                                                                                                                                                     |
| `GET`    | `/api/envelopes`                               | List all envelopes visible to the user (their own + ones shared with them via profile membership), each with a `permission` field: `"owner" \| "contributor" \| "viewer" \| "none"` (`none` = metadata-only) |
| `GET`    | `/api/envelopes/{envelope}/shares`             | List shares — the owner row first (labeled `owner`), then explicit rows, each with an `explicit` flag (owner and profile co-members only; a co-member only sees the rows of people sharing a profile with them — grantees from the owner's other profiles are not listed, the owner sees every row) |
| `POST`   | `/api/envelopes/{envelope}/shares`             | Assign a permission to a profile member (`user_id`, `permission` — `owner` is rejected with a validation error; use transfer-ownership)                                                                      |
| `PATCH`  | `/api/envelopes/{envelope}/shares/{userId}`    | Change a member's permission (owner only; `owner` is rejected — use transfer-ownership); returns `200` with `{ shares: [...] }`                                                                              |
| `DELETE` | `/api/envelopes/{envelope}/shares/{userId}`    | Revoke a share (owner only)                                                                                                                                                                                  |
| `POST`   | `/api/envelopes/{envelope}/transfer-ownership` | Transfer ownership to a member (`user_id`); the previous owner becomes `contributor`                                                                                                                         |

## User Interface

### Envelopes page

- Lists the user's own envelopes plus envelopes shared with them via profile
  membership (everything returned by `GET /api/envelopes`): color swatch,
  name and direction (icon + colour, consistent with the budget table).
- Creating and editing happens in a dialog (**name**, **direction** picker
  (`expense`/`revenue`) and **color** picker) — available to the **owner**
  only.
- Metadata-only rows (`permission` `none`, or `viewer`) render without amounts
  or edit controls.
- Deleting asks for confirmation and is **blocked** when the envelope is
  referenced by a budget or a transaction (validation error).
- Shared users see the envelope's name, color and direction in budgets and
  ledgers, but no transaction amounts unless they have at least `viewer`
  permission (planned budget amounts are budget data — see
  [Budget](budget.md)).
- **Share** — the unified Google Drive-like drawer (right side, not a dialog)
  listing the envelope's explicit shares with the **owner row first**
  (labeled **Owner**), a **Grant access** section picking members from the
  owner's profile circle, permission changes, revoke and ownership transfer —
  no invites (see [Shared Finances](shared-finances.md)).

## Integrity rules

- **Deleting an envelope that is referenced by any budget or transaction is
  blocked** with a validation error (data integrity is enforced, not
  cascaded).
- **Changing the direction of an envelope that is referenced by any budget
  or transaction is blocked** with a validation error on `type`: stored
  amounts are signed from the direction at write time, so flipping it would
  mix both sign conventions. Name and color stay editable. The check runs
  with the envelope row locked; recording a transaction or assigning the
  envelope to a budget reads the direction under a shared lock, so neither
  can race the check.
- `name` must be unique per owner. On auto-transfer, a collision with the new
  owner's existing names is resolved by appending ` (imported)`.
- Ownership transfer follows the shared rule: ownership can be transferred
  explicitly to a member (the previous owner becomes `contributor`), and when
  the owner is removed from the profile or their account is deleted, the
  envelope auto-transfers to the profile owner (see
  [Shared Finances](shared-finances.md)). On transfer, any permission row the
  new owner had on the envelope is dropped and the previous owner's row is
  set to `contributor`.
