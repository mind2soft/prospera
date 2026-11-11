# Shared Finances

This document describes financial profiles and how data is shared between users.

## Financial profiles

Financial profiles group users. Budgets and ledgers are scoped to a profile —
profile membership is what makes data visible between users.

Profiles are managed in the user settings called "Profiles". By default, when a
user creates an account, they get an owned "Default profile" created — the
exception is **registration through an invite** (see below): the account skips
the default profile and joins the shared profile instead, which becomes their
default.

Each user has **their own default profile** — a per-user `is_default` flag on
the `financial_profile_user` membership pivot (issue #41). Users can select
their default profile from **Settings → Profiles** or the user-menu profile
switcher; on login the server-side default is the fallback when the locally
stored current profile is gone. When a user's default membership disappears
(the profile is deleted, or they leave / are removed), their default falls
back to the **earliest-created owned profile** they still have, else the
**earliest remaining membership**, and a fresh seeded default profile is
created when nothing remains — an account never ends up without a profile.
(The former "the Default profile cannot be deleted" rule is gone: profiles
are deletable by their owner while they have no budgets or ledgers, and the
fallbacks above keep every account usable.)

A profile has exactly **one owner**; other members are **collaborators**.

## Invites

Invites are **link-based** (`FinancialProfileInvite`): the owner generates an
invite for an email and gets a link containing a hash key (`invite_key`).

- Invites carry the **role** the invitee will be granted — only
  `collaborator` is grantable today (a profile has exactly one owner;
  ownership transfer never happens through invites). The role appears in the
  invite API responses, the landing-page context and the invitation email.
- Every share event sends the invitee one **queued invitation email** (see
  [Notifications](notifications.md)):

  - When the email is **not linked to an account**, the mail invites the
    recipient to create an account through the invite link (inviter name and
    email, profile name, invite URL, 48 h expiry).
  - When the email matches a **registered account**, the mail carries a
    **one-click accept link** (`GET /invites/{inviteKey}/accept`): opening
    it while signed in with the matching account email accepts the invite
    straight through and lands on the dashboard. The mail states the
    attributed role and that **ignoring the request declines the share**.
    Guests are routed to the sign-in page first (the accept completes after
    signing in), and every other case — email mismatch, expired, reserved,
    already a member — falls back to the invite landing page, behaving
    exactly as opening the plain invite link. Registered recipients can turn
    these emails off via Settings → Notifications ("Profile invitations").
- An **existing user** accepts by opening the link while signed in — the invite
  must match their account email.
- A **new user** registers through the invite link; the invite key is carried
  through registration. Membership is **not** granted at account creation: the
  invite is **reserved** for the new account (`pending_user_id`), and
  membership is granted (and the invite consumed) when the account **verifies
  its email**. If the email was changed before verification, or the invite was
  revoked, no membership is granted — the account is then seeded with its own
  default profile (see below). A reserved invite can't be used by anyone
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
  context: the inviter's name, the profile name, the granted role, and the
  invite email pre-filled and **locked**.
- The registration endpoint accepts `invite_key` alongside the normal
  registration fields.
- On success the invite is **reserved** for the new account; it is consumed
  and membership is created once the account verifies its email.
- The personal "Default profile" is **not** created for registrations that
  carry a usable invite: the shared profile becomes the new account's only
  profile (and therefore its default experience — the profile switcher falls
  back to the only profile). If the invite is gone by the time the account
  verifies (revoked, pruned, or the email changed), no membership is granted
  and the account is seeded with its own default profile then. The same
  zero-profile guarantee applies at every membership exit: when a member
  **leaves or is removed**, or when a profile they belonged to is **deleted**,
  an account left without any profile gets its own default profile seeded —
  an account never ends up with zero profiles.
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

### Account termination

These rules are exactly what the public privacy policy (the `/privacy` page,
[resources/content/privacy.en.md](../resources/content/privacy.en.md))
communicates to users — keep the two in sync:

- every financial profile left **without any remaining member** — including
  all of its ledgers, budgets and transactions — is deleted;
- every shared profile that **still has other members keeps its data**:
  ownership passes to the **earliest-joined remaining member** (lowest
  `financial_profile_user.created_at`, ties broken by the lowest `user_id`),
  matching the ownership-transfer rules above;
- **the user is responsible for revoking sharing permissions before deleting
  their account** if they do not want a financial profile to keep existing —
  and be transferred — without them.

Resources (budgets, ledgers, envelopes) owned by a member **auto-transfer to
the profile owner** when that member is removed from the profile (or leaves
voluntarily) or when their account is deleted. Auto-transfer edge cases:

- The removed member's permission rows on the profile's resources are deleted.
- **Envelope grants follow the profile relationship.** Envelopes are
  user-owned, so their grants are not tied to one profile: when a member
  leaves or is removed, or a profile is deleted, the envelope grants
  between the departing user and each former co-member are deleted **in
  both directions** — but only when the two users **no longer share any
  profile**. Users who still share another profile keep their grants. The
  single exception is a grant on an envelope still referenced by
  budgets/ledgers of a profile the grantee belongs to (the departed
  member's envelope still used in the profile they left — see the next
  point): it is kept so that profile keeps working. As defence in depth,
  `Envelope::permissionFor` resolves any other stale grant (users sharing
  no profile) to `none`.
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

The **Share drawer** (Google Drive-like, `resources/js/components/share/ShareDrawer.vue`)
is shared by the Budget, Ledger and Envelopes pages. It no longer invites
users — it assigns permissions to existing **profile members** and allows
transferring ownership of the resource. It is **presentational** (props and
emits only); each page owns its queries and mutations.

- **People with access** — all members (with their effective permission) for
  budgets and ledgers; the owner row is listed first, labeled **Owner**, and
  carries no controls. Members show a colored initial avatar, name
  (with a "(you)" marker for the current user) and email; the owner changes
  permissions through a per-member dropdown and revokes access with the
  row's ✕ action. Members **without an explicit share row** (implicit
  viewers) are marked `explicit: false` by the API and show **no revoke
  action** — revoking would 404, since there is nothing to delete.
- **Grant access** (envelopes only) — the owner picks a member from their
  profile circle (people sharing a profile with them) and a permission.
- **Transfer ownership** — with a confirmation dialog; the previous owner
  becomes a contributor.
- **Done** — closes the drawer.

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

Each financial profile has its own **amount encryption key**: amounts are
encrypted at rest and decrypted by the server after these permission checks.
Members and collaborators never receive keys — sharing a resource grants
access to its decrypted amounts through the API only (see
[Encryption at rest](encryption.md)).

## Switching profile

In the user menu, above "Settings", a menu "Profiles" with a sub-menu listing
the available profiles. There _must_ be a visual distinction between owned
profiles and shared profiles. There must be a checkmark besides the currently
selected profile, and a "Default" marker with a "Set as default" action
(issue #41).

The selected profile is tracked client-side in `localStorage`. Next login
checks `localStorage` for the profile to use and falls back to the user's
**server-side default profile** (per-user `financial_profile_user.is_default`
— previously this fallback was the owned profile), else the first profile.
The default is selectable in the Profiles settings page and the switcher.

## Profile management UI

The "Profiles" settings page shows:

- the list of profiles, visually distinguishing **owned** vs **shared**;
- for owned profiles: rename, members list (name, email, role — **Owner** /
  **Collaborator**, derived from `is_owner` — joined date), member removal
  (owner only — the member's owned resources auto-transfer to the profile
  owner), invite creation with a copyable link, pending invites with revoke
  (invites expire after 48 hours), and ownership transfer to a member (the
  previous owner becomes a collaborator).
- a **"Default" badge** on the user's default profile with a **"Set as
  default"** action, on every profile the user belongs to (issue #41). The
  "Default profile" is deletable like any other profile (its resources
  guard applies); deletion reassigns the affected users' defaults
  (see above).
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
    is_default: boolean; // exactly 1 per user — the user's default profile
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
    role: string; // the role granted on acceptance — only 'collaborator' today
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
| `GET`    | `/api/profiles`                              | List all the user's profiles (owned + shared), each with the caller's `is_owner` and `is_default` membership flags; the currently selected profile is tracked client-side (`localStorage`), not server-flagged                                                                                                                          |
| `POST`   | `/api/profiles`                              | Create a profile (`name`) — the creator becomes owner                                                                                                                                                                                                                                                                                  |
| `PATCH`  | `/api/profiles/{profile}`                    | Rename a profile (owner only)                                                                                                                                                                                                                                                                                                          |
| `DELETE` | `/api/profiles/{profile}`                    | Delete a profile (owner only; blocked while it has budgets or ledgers). The former "Default profile cannot be deleted" rule is gone: deletion reassigns affected users' defaults (earliest-created owned profile, else earliest remaining membership, else a seeded default profile)                                                   |
| `GET`    | `/api/profiles/{profile}/members`            | List members (id, name, email, is_owner, joined_at)                                                                                                                                                                                                                                                                                    |
| `DELETE` | `/api/profiles/{profile}/members/{userId}`   | Remove a member (owner only; not the owner) or leave voluntarily (self). Resources owned by the removed member auto-transfer to the profile owner (except envelopes still referenced by another of their profiles — see [Transfer of ownership](#transfer-of-ownership)); their permission rows on the profile's resources are deleted |
| `POST`   | `/api/profiles/{profile}/transfer-ownership` | Transfer ownership to a member (`user_id`); the previous owner becomes a collaborator                                                                                                                                                                                                                                                  |
| `POST`   | `/api/profiles/{profile}/set-default`        | Set the caller's default profile (any member; one default per user, always exactly one)                                                                                                                                                                                                                                                |
| `GET`    | `/api/profiles/{profile}/invites`            | List pending invites (`id`, `email`, `role`, `awaiting_verification`, `expires_at`)                                                                                                                                                                                                                                                    |
| `POST`   | `/api/profiles/{profile}/invites`            | Create an invite (`email`, optional `role` — `collaborator` by default, only `collaborator` is grantable) → returns the invite link and queues the invitation email; replaces a previous unreserved invite for the same email (case-insensitive); `422` on `email` if already a member; rate-limited (the endpoint now triggers outbound email) |
| `DELETE` | `/api/profiles/{profile}/invites/{invite}`   | Revoke a pending invite                                                                                                                                                                                                                                                                                                                |
| `GET`    | `/api/invites/{inviteKey}`                   | Public invite validation (rate-limited); `404` when invalid or reserved, `410` expired                                                                                                                                                                                                                                                 |
| `POST`   | `/api/invites/{inviteKey}/accept`            | Accept an invite (signed-in user; the invite email must match the account email; rate-limited)                                                                                                                                                                                                                                         |
| `GET`    | `/invites/{inviteKey}/accept`                | One-click accept from the invitation email (signed-in + verified user with a matching account email → accepts and lands on the dashboard; guests go to the sign-in page and complete the accept after; all other cases fall back to the invite landing page; rate-limited)                                                              |

The invite link is the frontend route `/invites/{inviteKey}`: existing users
get an accept confirmation; new users are routed to registration with the key
(see [Registration through an invite](#registration-through-an-invite)), and
membership is granted once the new account verifies its email.
