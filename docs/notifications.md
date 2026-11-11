# Notifications

Email notification settings — the single place where any system notification
is gated. This feature ships the **settings** (UI, persistence, gate helper);
the notifications themselves are shipped by their own issues (payment
reminders first, with scheduled transactions — issue #15).

## Catalog & semantics

A **master switch** plus individual toggles, each with a one-line localized
description on the settings page. **Master off = no system emails,
regardless of the individual switches.**

| Toggle | Default | Meaning |
| ------ | ------- | ------- |
| **Payment reminders** | on | Upcoming scheduled-transaction reminders (sent when a schedule has a `reminder_days` option set). |
| **Ledger expired** | on | An open ledger you own (or are a member of) whose **end date has passed** — sent once, when the daily run first detects it. |
| **Contributor transactions** | on | Another user records a transaction in a ledger you are the owner of — one email per transaction (the recipient list stays narrow to ledger owners to avoid noisy group mails; broader audiences can be added later). |
| **Envelope limit exceeded** | on | Recorded spending for an envelope within a month **exceeds its allocation on that month's published budget** — checked when the exceeding transaction is recorded; recipients are the envelope owner + envelope contributors. Amounts are masked for recipients holding less than `viewer` permission on the envelope. |
| **Profile invitations** | on | Someone shares a financial profile with you — the invite email with the accept link to the invite confirmation (issue #39). The toggle only gates emails **received**; invitations you send are always delivered to their recipients. |

Timing notes: notifications are queued per-event emails (no digests — a
digest mode can be added later as a new option without a schema break).

**Invite emails and unregistered recipients**: the profile-invitation email
may target an email address that has no Prospera account yet. Such
recipients have no settings to honor and always receive the invite (they
cannot opt out of an account they don't have). Once the address belongs to
an account, the toggle (and the master switch) gates the email, re-checked
at send time.

## Storage

The settings live in the `users.notification_settings` JSON column, cast to
the immutable
[`App\Settings\NotificationSettings`](../app/Settings/NotificationSettings.php)
value object:

```typescript
type NotificationSettings = {
    master: boolean;
    payment_reminders: boolean;
    ledger_expired: boolean;
    contributor_transactions: boolean;
    envelope_limit_exceeded: boolean;
    profile_invite: boolean;
};
```

- `null` (the column default) means "the user never opened the settings
  page" — every toggle then reads as **on**.
- Missing and unknown keys hydrate with the defaults, so adding new toggles
  never requires a data migration.

## The gate helper contract

[`User::wantsNotification(string $key): bool`](../app/Models/User.php) —
master **and** per-type — is **the only gate**: every notification must
route through it, and nobody reads the raw JSON keys directly.

Because jobs may be enqueued before a user flips a toggle, the check happens
**at send time** (inside the notification/queued send), not only at dispatch
time.

## Email conventions (for all consumers of these settings)

- Emails are **queued** (works with `MAIL_MAILER=log` locally and the
  existing queue worker).
- Sent to **verified** addresses only (Fortify-verified accounts; unverified
  users get nothing, silently). Exception — **profile invitations**: the
  share must reach the owner of an existing (not yet verified) account too;
  they cannot re-register with their email, and the accept link
  routes them through email verification before accepting.
- Localized per recipient's `users.locale` (fallback `en`), including
  plain-text parts.
- Every notification email carries a short "Manage your notifications"
  footer link to **Settings → Notifications** (requires login) — except the
  account-creation variant of the profile invitation, whose recipient has no
  account to manage settings with.
- Recipients holding less than `viewer` permission on a resource receive the
  notification **without the amounts** (see
  [envelopes](envelope.md) — amount-masking rule).

## API

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/settings/notifications` | The settings page (current settings as props, defaults applied). |
| `PATCH` | `/settings/notifications` | Autosave — the page sends the full catalog (master included) so the stored object is always complete. Auth + CSRF. |

## UI

**Settings → Notifications**: a settings list of switches (shadcn-vue), the
master switch visually emphasized. Individual rows show the localized
description under the toggle. **Autosave** (no save buttons — project
convention); master off keeps the individual rows interactive but
semantically ignored — grayed, with an "all notifications are off" hint.
