# Enveloppe

An enveloppe groups transactions by purpose (e.g. mortgage, groceries) — a
named spending/income bucket owned by a user, with a name, a
direction (`expense` or `revenue`) and a color.
Enveloppes are managed on the _Enveloppes_ page and referenced by
[Budgets](budget.md) and [Ledgers](ledger.md).

The app uses the "enveloppe" spelling consistently.

## Data Schema

Backed by the `enveloppes` table:

```typescript
type Enveloppe = {
    id: number; // PK
    user_id: number; // FK users.id — the owner (the creator)
    name: string; // max 60 chars, unique per owner
    direction: 'expense' | 'revenue'; // stored in the `type` column of the `enveloppes` table
    color: string; // 7-char hex, e.g. "#6366f1" (default)
    created_at: string; // ISO date time
    updated_at: string; // ISO date time
};

type ResourcePermission = 'owner' | 'contributor' | 'viewer';

type EnveloppePermission = {
    enveloppe_id: number; // FK: enveloppes.id
    user_id: number; // FK: users.id
    permission: ResourcePermission;
    created_at: string; // ISO date time
    updated_at: string; // ISO date time
};
```

Enveloppes stay **user-owned** — they are not profile-scoped. Permission rows
are stored in an `enveloppe_permissions` table and can only be granted to
existing **profile members** of the owner (see
[Shared Finances](shared-finances.md)).

## Storage Conventions

- `name` is unique per owner (`unique(user_id, name)`), max 60 characters.
- `color` is a 7-character hex string; defaults to `#6366f1`.
- `direction` is stored in the `type` column of the `enveloppes` table with
  the values `expense` | `revenue` (this is the DB-level name; the UI and docs
  speak of "direction").
- **Amount sign convention** — same as for budgets: amounts are always entered
  and displayed as positive integers and stored signed, derived from the
  enveloppe's direction (revenue positive, expense negative). See
  [Budget — Amount sign convention](budget.md#amount-sign-convention).

## Permissions

| Permission    | Enveloppe                                               | Transactions                       |
| ------------- | ------------------------------------------------------- | ---------------------------------- |
| `owner`       | Full control (edit, share, transfer, delete)            | Can record transactions against it |
| `contributor` | Read-only on the enveloppe itself (no edits)            | Can record transactions against it |
| `viewer`      | Read-only (sees name, color, direction **and amounts**) | Read-only                          |

- Enveloppe amount privacy governs **transaction data only**: budget
  **planned** amounts are budget data and follow budget read access, not
  enveloppe permissions (see [Budget](budget.md)).
- Enveloppe permissions are **user-level** and independent of any budget or
  ledger permission. Recording a transaction requires being the ledger owner
  or contributor, who must also be `contributor`+ on the target enveloppe —
  the ledger owner included (see [Ledger](ledger.md)). Assigning an enveloppe
  to a draft budget likewise requires `contributor`+ on it (see
  [Budget](budget.md)).
- Users who do not even have `viewer` permission can still **see** the
  enveloppe's metadata (name, color, direction) — required to render shared
  budgets and ledgers — but must **never** see its transaction amounts or
  totals. In views showing transactions (e.g. the ledger), such rows show the
  metadata with **masked amounts** (`—`) and a masked "remaining allocated";
  the server masks them (amount `null` plus a `masked` flag) so raw amounts
  never reach clients lacking permission.
- Amount totals are computed by dedicated server-side endpoints that validate
  permissions; raw transaction data is never exposed to clients lacking
  permission.

## API

All endpoints require authentication (session + CSRF). The frontend consumes
them via **vue-query** — this is an application-wide requirement for server
data fetching.

| Method   | Path                                             | Description                                                                                                                                                                                                   |
| -------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET`    | `/enveloppes`                                    | Enveloppes page (Inertia)                                                                                                                                                                                     |
| `POST`   | `/enveloppes`                                    | Create an enveloppe (`name`, `type` (`expense`\|`revenue`), `color`); responses expose `type` as `direction`                                                                                                  |
| `PUT`    | `/enveloppes/{enveloppe}`                        | Update an enveloppe (owner only; same fields as create)                                                                                                                                                       |
| `DELETE` | `/enveloppes/{enveloppe}`                        | Delete an enveloppe (owner only; blocked when referenced)                                                                                                                                                     |
| `GET`    | `/api/enveloppes`                                | List all enveloppes visible to the user (their own + ones shared with them via profile membership), each with a `permission` field: `"owner" \| "contributor" \| "viewer" \| "none"` (`none` = metadata-only) |
| `GET`    | `/api/enveloppes/{enveloppe}/totals`             | Computed transaction totals (optional `from_date`/`to_date` date query params, `to_date` ≥ `from_date`; `422` if invalid); `403` without `viewer`+                                                            |
| `GET`    | `/api/enveloppes/{enveloppe}/transactions`       | Transactions referencing the enveloppe; `403` without `viewer`+                                                                                                                                               |
| `GET`    | `/api/enveloppes/{enveloppe}/shares`             | List shares (profile members only)                                                                                                                                                                            |
| `POST`   | `/api/enveloppes/{enveloppe}/shares`             | Assign a permission to a profile member (`user_id`, `permission` — `owner` is rejected with a validation error; use transfer-ownership)                                                                       |
| `PATCH`  | `/api/enveloppes/{enveloppe}/shares/{userId}`    | Change a member's permission (owner only; `owner` is rejected — use transfer-ownership); returns `200` with `{ shares: [...] }`                                                                               |
| `DELETE` | `/api/enveloppes/{enveloppe}/shares/{userId}`    | Revoke a share (owner only)                                                                                                                                                                                   |
| `POST`   | `/api/enveloppes/{enveloppe}/transfer-ownership` | Transfer ownership to a member (`user_id`); the previous owner becomes `contributor`                                                                                                                          |

## User Interface

### Enveloppes page

- Lists the user's own enveloppes plus enveloppes shared with them via profile
  membership (everything returned by `GET /api/enveloppes`): color swatch,
  name and direction (icon + colour, consistent with the budget table).
- Creating and editing happens in a dialog (**name**, **direction** picker
  (`expense`/`revenue`) and **color** picker) — available to the **owner**
  only.
- Metadata-only rows (`permission` `none`, or `viewer`) render without amounts
  or edit controls.
- Deleting asks for confirmation and is **blocked** when the enveloppe is
  referenced by a budget or a transaction (validation error).
- Shared users see the enveloppe's name, color and direction in budgets and
  ledgers, but no transaction amounts unless they have at least `viewer`
  permission (planned budget amounts are budget data — see
  [Budget](budget.md)).
- **Share** — a Google Drive-like drawer (right side, not a dialog) listing
  the profile's members with their permission, allowing permission changes and
  ownership transfer — no invites (see
  [Shared Finances](shared-finances.md)).

## Integrity rules

- **Deleting an enveloppe that is referenced by any budget or transaction is
  blocked** with a validation error (data integrity is enforced, not
  cascaded).
- `name` must be unique per owner. On auto-transfer, a collision with the new
  owner's existing names is resolved by appending ` (imported)`.
- Ownership transfer follows the shared rule: ownership can be transferred
  explicitly to a member (the previous owner becomes `contributor`), and when
  the owner is removed from the profile or their account is deleted, the
  enveloppe auto-transfers to the profile owner (see
  [Shared Finances](shared-finances.md)). On transfer, any permission row the
  new owner had on the enveloppe is dropped and the previous owner's row is
  set to `contributor`.
