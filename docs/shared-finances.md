# Shared Finances

This document describes financial profiles and how data is shared between users.

## Financial profiles

Financial profiles group users. Budgets and ledgers are scoped to a profile —
profile membership is what makes data visible between users.

Profiles are managed in the user settings called "Profiles". By default, when a
user creates an account, they get an owned "Default profile" created.

A profile has exactly **one owner**; other members are **collaborators**.

## Invites

Invites are **link-based** (`FinancialProfileInvite`): the owner generates an
invite for an email and gets a link containing a hash key (`invite_key`).

- An **existing user** accepts by opening the link while signed in — the invite
  must match their account email.
- A **new user** registers through the invite link; the invite key is carried
  through registration. Membership is **not** granted at account creation: the
  invite is **reserved** for the new account (`pending_user_id`), and
  membership is granted (and the invite consumed) when the account **verifies
  its email**. If the email was changed before verification, or the invite was
  revoked, no membership is granted. A reserved invite can't be used by anyone
  else (treated as invalid).
- Re-inviting the same email (case-insensitive) **replaces** the previous
  unreserved invite for that profile — the raw link key is never stored, so
  this is how a lost link is re-issued. Inviting an email that already belongs
  to a member is rejected with a validation error on `email`.
- Invites expire after **48 hours** (`expires_at` = creation time + 48h);
  expired invites are rejected with a validation error.
- Invites can be revoked by the owner before acceptance; accepted invites are
  deleted.
- Stale invites are pruned daily by the scheduled `php artisan invites:prune`
  command: expired unreserved invites, and reserved invites more than a week
  past expiry.
- The public invite endpoints (`GET /invites/{inviteKey}`,
  `GET /api/invites/{inviteKey}`) and `POST /api/invites/{inviteKey}/accept`
  are rate-limited (20 per minute per IP).

### Registration through an invite

- `GET /invites/{inviteKey}` renders the registration page with invite
  context: the inviter's name, the profile name, and the invite email
  pre-filled and **locked**.
- The registration endpoint accepts `invite_key` alongside the normal
  registration fields.
- On success the invite is **reserved** for the new account; it is consumed
  and membership is created once the account verifies its email.
- An invalid, expired, reserved or email-mismatched invite shows an explicit error
  page.

## Transfer of ownership

A profile must always have exactly one owner. When the owner's account is
deleted, ownership passes to the **earliest joiner** — the member with the
lowest `financial_profile_user.created_at`, ties broken by the lowest `user_id`.
If the profile has no other member, the profile is deleted along with the
owner's resources in it. **Account deletion is never blocked**: it proceeds in
dependency order — transactions first, then ledgers (cascading their
transactions), then budgets, then envelopes — which cannot violate the
integrity rules, so solo-profile deletion is never blocked in practice.

Resources (budgets, ledgers, envelopes) owned by a member **auto-transfer to
the profile owner** when that member is removed from the profile (or leaves
voluntarily) or when their account is deleted. Auto-transfer edge cases:

- The removed member's permission rows on the profile's resources are deleted.
- When a member **leaves or is removed** (without deleting their account),
  their envelopes referenced by this profile transfer to the profile owner,
  **except** envelopes still referenced by another profile the member belongs
  to: those stay owned by the member, and the profile owner is granted
  `contributor` on them so this profile's budgets/ledgers keep working.
- If an envelope's name collides with the recipient owner's existing name,
  ` (imported)` is appended to resolve the `unique(user_id, name)` constraint.
- Envelopes are user-owned and referenced by id, so an envelope transferring
  to a profile owner remains valid for references from other profiles'
  budgets/ledgers.
- On account deletion, an envelope referenced by budgets/ledgers of several
  profiles transfers to the owner of the **earliest-created** of those
  profiles (falling back to that profile's earliest other member);
  unreferenced envelopes are deleted with the account.

Ownership can also be transferred **explicitly** — by the owner, via the Share
drawer (resources) or the Profiles page (profile) — to another member; the
previous owner becomes `contributor` on a resource, or a **collaborator** on a
profile (profiles only have an owner and collaborators). On an envelope
transfer, any permission row the new owner had on it is dropped. See [Budget](budget.md),
[Ledger](ledger.md) and [Envelope](envelope.md).

## Permission model

Permissions exist at two levels:

1. **Profile membership** — makes a user eligible to see and be granted
   resources of the profile. On its own, membership grants **read-only
   visibility** of the profile's budgets and ledgers (viewer-equivalent
   default).
2. **Resource-level permissions** — per-resource rows that escalate a member's
   access:

```typescript
type ResourcePermission = 'owner' | 'contributor' | 'viewer';
```

- The resource **owner** has full control (edit, share, transfer, delete).
- **Viewer** is read-only.
- **Contributor** semantics are per resource type — see
  [Budget](budget.md), [Ledger](ledger.md) and [Envelope](envelope.md).

The **Share** drawer (Google Drive-like, used on the Budget, Ledger and
Envelopes pages) no longer invites users — it assigns permissions to existing
**profile members** and allows transferring ownership of the resource.

Envelopes are the exception: they stay **user-owned** (the creator is the
owner) and are not profile-scoped for their data. Only the owner can record
transactions against an envelope, unless contributors are added. Envelope
amount privacy governs **transaction data only**: users without even viewer
permission on an envelope can still **see** its metadata (name, color,
direction) but must **never** see its transaction amounts or totals — in
views showing transactions (e.g. the ledger), such rows show the envelope's
metadata with **masked amounts** (`—`); the server masks them (amount `null`
plus a `masked` flag) so raw amounts never reach clients lacking permission,
and totals are computed by dedicated server-side endpoints that validate
permissions. Budget **planned** amounts are budget data — they follow budget
read access, not envelope permissions (see [Budget](budget.md)).

## Switching profile

In the user menu, above "Settings", a menu "Profiles" with a sub-menu listing
the available profiles. There _must_ be a visual distinction between owned
profiles and shared profiles. There must be a checkmark besides the currently
selected profile.

When the user first logs in, the selected profile is their owned profile. When
the profile is switched, it is saved in `localStorage`. Next login checks
`localStorage` for the profile to use, and defaults to the owned profile.

## Profile management UI

The "Profiles" settings page shows:

- the list of profiles, visually distinguishing **owned** vs **shared**;
- for owned profiles: rename, members list (name, email, role — **Owner** /
  **Collaborator**, derived from `is_owner` — joined date), member removal
  (owner only — the member's owned resources auto-transfer to the profile
  owner), invite creation with a copyable link, pending invites with revoke
  (invites expire after 48 hours), and ownership transfer to a member (the
  previous owner becomes a collaborator).
- Members may **leave a profile voluntarily** from this page (their owned
  resources auto-transfer to the profile owner).

## Data Schema

```typescript
type FinancialProfile = {
    id: number; // PK
    name: string; // a descriptive label
    created_at: string; // ISO date time
    updated_at: string; // ISO date time
};

// table: financial_profile_user
type FinancialProfileUser = {
    profile_id: number; // FK financial_profiles.id
    user_id: number; // FK users.id
    is_owner: boolean; // only 1 per profile
    created_at: string; // ISO date time — lowest wins for ownership transfer, ties by lowest user_id
    updated_at: string; // ISO date time
};
// unique [profile_id, user_id]

type FinancialProfileInvite = {
    id: number; // PK
    profile_id: number; // FK financial_profiles.id
    invite_key: string; // invite hash (never exposed by the API)
    pending_user_id: number | null; // FK users.id — new account the invite is reserved for until email verification
    email: string;
    invited_by: number; // FK users.id (the owner)
    expires_at: string; // ISO date time
    created_at: string; // ISO date time
    updated_at: string; // ISO date time
};
```

Resource permission rows (`budget_permissions`, `ledger_permissions`,
`envelope_permissions`) carry `created_at`/`updated_at`. Resource ownership
transfer follows the rules in
[Transfer of ownership](#transfer-of-ownership): explicit transfer (the
previous owner becomes `contributor`) or auto-transfer to the profile owner on
member removal / account deletion. Their shapes are defined in the
per-resource docs.

## API

All endpoints require authentication (session + CSRF). The frontend consumes
them via **vue-query** — this is an application-wide requirement for server
data fetching.

| Method   | Path                                         | Description                                                                                                                                                                                                                                                                                                                            |
| -------- | -------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET`    | `/api/profiles`                              | List all the user's profiles (owned + shared), each with `is_owner`; the currently selected profile is tracked client-side (`localStorage`), not server-flagged                                                                                                                                                                        |
| `POST`   | `/api/profiles`                              | Create a profile (`name`) — the creator becomes owner                                                                                                                                                                                                                                                                                  |
| `PATCH`  | `/api/profiles/{profile}`                    | Rename a profile (owner only)                                                                                                                                                                                                                                                                                                          |
| `DELETE` | `/api/profiles/{profile}`                    | Delete a profile (owner only; blocked while it has budgets or ledgers; the "Default profile" cannot be deleted but **can** be renamed)                                                                                                                                                                                                 |
| `GET`    | `/api/profiles/{profile}/members`            | List members (id, name, email, is_owner, joined_at)                                                                                                                                                                                                                                                                                    |
| `DELETE` | `/api/profiles/{profile}/members/{userId}`   | Remove a member (owner only; not the owner) or leave voluntarily (self). Resources owned by the removed member auto-transfer to the profile owner (except envelopes still referenced by another of their profiles — see [Transfer of ownership](#transfer-of-ownership)); their permission rows on the profile's resources are deleted |
| `POST`   | `/api/profiles/{profile}/transfer-ownership` | Transfer ownership to a member (`user_id`); the previous owner becomes a collaborator                                                                                                                                                                                                                                                  |
| `GET`    | `/api/profiles/{profile}/invites`            | List pending invites (`id`, `email`, `awaiting_verification`, `expires_at`)                                                                                                                                                                                                                                                            |
| `POST`   | `/api/profiles/{profile}/invites`            | Create an invite (`email`) → returns the invite link; replaces a previous unreserved invite for the same email (case-insensitive); `422` on `email` if already a member                                                                                                                                                                |
| `DELETE` | `/api/profiles/{profile}/invites/{invite}`   | Revoke a pending invite                                                                                                                                                                                                                                                                                                                |
| `GET`    | `/api/invites/{inviteKey}`                   | Public invite validation (rate-limited); `404` when invalid or reserved, `410` expired                                                                                                                                                                                                                                                 |
| `POST`   | `/api/invites/{inviteKey}/accept`            | Accept an invite (signed-in user; the invite email must match the account email; rate-limited)                                                                                                                                                                                                                                         |

The invite link is the frontend route `/invites/{inviteKey}`: existing users
get an accept confirmation; new users are routed to registration with the key
(see [Registration through an invite](#registration-through-an-invite)), and
membership is granted once the new account verifies its email.
