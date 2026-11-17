# Notifications

Email notification settings — the single place where any system notification
is gated. This feature ships the **settings** (UI, persistence, gate helper);
the notifications themselves are shipped by their own issues.

> **Status:** every toggle has an email behind it — **payment reminders**,
> **ledger expired**, **contributor transactions**, **envelope limit
> exceeded** and **profile invitations** are all sent today.

## Catalog & semantics

A **master switch** plus individual toggles, each with a one-line localized
description on the settings page. **Master off = no system emails,
regardless of the individual switches.**

| Toggle | Default | Status | Meaning |
| ------ | ------- | ------ | ------- |
| **Payment reminders** | on | Implemented ([#15](https://github.com/mind2soft/prospera/issues/15), [scheduled-transactions.md](scheduled-transactions.md#payment-reminders)) | An upcoming occurrence of a [scheduled transaction](scheduled-transactions.md) whose schedule has a `reminder_days` option set — sent once per occurrence, `reminder_days` days before it (0 = on the day), to the envelope owner and the members of the profiles referencing the envelope. |
| **Ledger expired** | on | Implemented ([#50](https://github.com/mind2soft/prospera/issues/50)) | An open ledger you own (or are a member of) whose **end date has passed** — sent once, when the daily run first detects it. |
| **Contributor transactions** | on | Implemented ([#51](https://github.com/mind2soft/prospera/issues/51)) | Another user records a transaction in a ledger you are the owner of — one email per transaction (the recipient list stays narrow to ledger owners to avoid noisy group mails; broader audiences can be added later). |
| **Envelope limit exceeded** | on | Implemented ([#52](https://github.com/mind2soft/prospera/issues/52)) | Recorded spending for an envelope within a month **exceeds its allocation on that month's published budget** — checked when the exceeding transaction is recorded; recipients are the envelope owner + envelope contributors. Amounts are masked for recipients holding less than `viewer` permission on the envelope. |
| **Profile invitations** | on | Implemented | Someone shares a financial profile with you — the invite email with the accept link to the invite confirmation (issue #39). The toggle only gates emails **received**; invitations you send are always delivered to their recipients. |

Timing notes: notifications are queued per-event emails (no digests — a
digest mode can be added later as a new option without a schema break).

**Payment reminders** ([#15](https://github.com/mind2soft/prospera/issues/15)):
the daily `php artisan schedules:remind` run queues one
[`App\Mail\PaymentReminder`](../app/Mail/PaymentReminder.php) per
(schedule, occurrence, recipient) once `today ≥ occurrence − reminder_days`,
claimed in `schedule_reminders` (unique index) so re-runs, overlap,
retries and catch-up never double-send. Occurrences that passed during
downtime are skipped, never reminded late; an edit moving an occurrence
re-arms only the new date. Recipients are the envelope owner plus the
members of the profiles referencing the envelope, deduplicated, verified
only and re-resolved at send time; the amount is omitted below envelope
`viewer`. The full rules live in
[scheduled-transactions.md](scheduled-transactions.md#payment-reminders).

**Ledger expired** ([#50](https://github.com/mind2soft/prospera/issues/50)):
a daily scheduled command (`php artisan ledgers:notify-expired`, see
[Scheduled tasks](../README.md#scheduled-tasks)) selects open ledgers
(`closed_at` null) whose `end_date` has passed and queues one email per
eligible recipient — the **ledger owner plus every member of the ledger's
financial profile**. All profile members can view ledgers by default
(implicit viewer, see [ledger.md](ledger.md)), so the whole profile learns
that the period ended; explicit `contributor`/`viewer` rows are members
too. Recipients are deduplicated: one email per user per ledger.

- **Sent once per ledger, ever.** The dedup state is the nullable
  `ledgers.expired_notified_at` timestamp, claimed atomically
  (`UPDATE … SET expired_notified_at = now() WHERE id = ? AND
  expired_notified_at IS NULL`) before dispatching, so overlapping runs and
  command re-runs never double-send. The claim and the queued mail rows
  commit in the same transaction — the once-ever guarantee therefore relies
  on the `database` queue (the shipped default, see
  [Scheduled tasks](../README.md#scheduled-tasks)) writing to the same
  database. A scheduler catch-up after missed days still finds every
  expired-and-unnotified ledger — "first detects" means the first run after
  the end date. A ledger closed after the run but before delivery still
  receives its email: the mail is already queued and the closing action
  does not withdraw it.
- **Closed ledgers are never notified.** A ledger closed before expiry and
  re-opened after its end date counts as first detected on the next run,
  unless it was already notified; re-opening never resets
  `expired_notified_at`. Ledgers have no update endpoint today, so an end
  date cannot change — if one is added later, moving the end date must
  clear `expired_notified_at` (see [ledger.md](ledger.md)).
- **No amounts.** The email carries the ledger and profile names, the
  start and end dates, and a link to the ledger page — nothing needs
  decryption or masking.

**Contributor transactions**
([#51](https://github.com/mind2soft/prospera/issues/51)): when a
transaction is recorded through `POST /api/ledgers/{ledger}/transactions`
(`LedgerController::addTransaction`) by anyone but the ledger owner, one
email is queued to the ledger's **current** owner. The dispatch happens
after the insert's database transaction commits
([`App\Mail\ContributorTransaction`](../app/Mail/ContributorTransaction.php)),
so a rolled-back insert never notifies; the recipient, the ownership and
every gate are re-resolved **at send time**, exactly as queued.

- **One email per transaction, once per recording.** No digest, no
  re-dispatch path: the mail is queued exactly once, inside the recording
  request, and a second transaction over the same ledger notifies again.
  Delivery is **at-least-once at the queue level**, like every queued mail
  in this app: `RetriesDelivery` re-runs the same mailable until the
  transport accepts the message (5 tries over about 20 minutes), and a
  re-evaluated send-time gate suppresses nothing a first attempt delivered.
  The issue's stronger "unique job / sent marker" option — a
  `transactions.notified_at` claim — would trade this for an at-most-once
  guarantee that risks losing a mail whose delivery was acknowledged as
  failed; it can be added on top if the maintainer prefers it
  (issue #51, "dedup" section).
- **No email** when the actor **is** the ledger owner (checked at dispatch
  and again at send), or when the transaction has been **deleted** by the
  time the job runs (its row is soft-deleted, so it disappears from the
  default query — the undo window's purges are honoured).
  [Schedule-generated rows](scheduled-transactions.md) never dispatch this
  mail: nothing was recorded by "another user", and reconciliation does
  not go through the recording endpoint.
- **Ownership transfers while queued are honoured.** The recipient is
  resolved at send time from the `owner` row in `ledger_permissions` —
  ownership transfer or auto-transfer (see
  [shared-finances.md](shared-finances.md)) moves the mail to the new
  owner; if the current owner **is** the actor, nothing is sent. The
  mailable receives **IDs only** (transaction id, ledger id) — names,
  locale, and the recipient's email are re-resolved at send time, and no
  address or amount rides in the queue payload.
- **Amount masking:** envelope permissions are independent of ledger
  permissions (see [ledger.md](ledger.md),
  [envelope.md](envelope.md)). The recipient's envelope permission is
  resolved at send time; below `viewer`, the email carries no amount at
  all — envelope name, direction and date only (metadata, the same rule as
  the ledger API). No placeholder hints at the masked value. Amounts are
  read in PHP from the decrypted `Transaction::amount` — never in SQL —
  shown positive (sign convention, see [budget.md](budget.md)) and
  formatted with the currency of the published budget effective for the
  transaction's month (falling back to the latest published budget, per
  the ledger page's reporting currency).

**Envelope limit exceeded**
([#52](https://github.com/mind2soft/prospera/issues/52)): when a
transaction is recorded on an **expense** envelope — through
`POST /api/ledgers/{ledger}/transactions` (the queued
`App\Jobs\CheckEnvelopeLimit` job) or as a batch of
[schedule-generated rows](scheduled-transactions.md) (the queued
`App\Jobs\CheckEnvelopeLimitGroup` job, per ledger × envelope × month whose
spending grew), both dispatched after commit and calling the same
`App\Services\EnvelopeLimitChecker` — the recorded spending for the month
is compared to the envelope's allocation on that month's published budget,
and the **crossing** is notified.

- **Per-ledger scope.** The check compares the envelope's month totals
  **within the recording's ledger** — the same number the ledger page
  reports as "remaining allocated" (see
  [ledger.md](ledger.md) — Allocation reporting). A profile with several
  open ledgers covering the same month checks each ledger separately; the
  alternative (summing across all the profile's ledgers) was rejected to
  stay consistent with what the ledger page shows.
- **Crossing, not over-ness.** The notification fires on the transaction
  that takes the envelope from within its allocation
  (`recorded_before ≥ allocated`) to strictly over it
  (`recorded_after < allocated`) — the exact boundary (recorded equal to
  the allocation) is within. Later over-limit transactions do not send
  again: the crossing is the one actionable moment.
- **Batch-safe crossing.** The checker locks the ledger, then the published
  budget, then the envelope (all shared), and refreshes the profile key. It
  sums the month's rows in **ascending id order** in exact minor units. The
  crossing row is where a running prefix last goes from within to strictly
  over. Mail is sent only when the final total is over and the crossing row
  is at or after the first changed row (the recorded transaction, or the
  lowest generated row inserted or increased). An atomic batch of 3 × 60.00
  against 100.00 therefore notifies, on its second row. A crossing that moves
  onto a later manual row, because an earlier generated amount increased,
  also notifies. Nothing is sent while the final total is within the
  allocation, or when the last crossing row precedes the first changed row
  (for example, manual over-spending that predates newly generated rows).
- **Once per (published budget, envelope, ledger).** The dedup state is a
  row in `envelope_limit_notifications`, whose unique index over the three
  ids **is** the atomic claim. It is inserted inside the check's
  transaction, and the mails are queued only **after the outermost
  transaction commits**, so a rolled-back check never mails, and concurrent
  recordings and job retries never double-send. As before, the claim
  commits before the mails are queued, so delivery stays at most once: a
  process dying between the two loses that mail. A **budget revision** (a new published budget for the
  month, so a new `budget_id`) re-arms the check. Deleting transactions so
  the envelope drops back under does **not** re-arm it (no email
  ping-pong). Publishing a budget after transactions already exceed it
  does **not** send: the reconciliation that follows only checks spending
  that grew, and over-spending that predates the generated rows is not a
  crossing caused by them.
- **Recipients.** The envelope **owner** (`envelopes.user_id`) plus every
  user holding an explicit envelope share at **contributor level or
  above** (`envelope_permissions`; envelope `viewer`s and other profile
  members are not included), restricted to **members of the ledger's
  financial profile** — envelopes are user-owned and can be referenced
  from several profiles, so a profile's ledger activity never leaks
  outside it — and deduplicated. The user who recorded the transaction is
  included when they are the owner or a contributor: a useful warning for
  them too.
- **Re-resolved at send time.** The mailable carries **IDs only** (budget,
  envelope, ledger, recipient); at send time it re-checks the recipient's
  envelope permission — below **envelope-owner-or-contributor** the whole
  mail is suppressed (holders of less than `viewer` get no amounts at all,
  so a permission downgrade while the mail waits in the queue can never
  leak a value), alongside the verified address and the toggle gates.
- **No SQL on encrypted columns.** The allocation and the month's recorded
  total are read in PHP from the decrypted model values — no `SUM(amount)`,
  no `WHERE amount < …` — exactly like the ledger page's reporting
  (docs/encryption.md, docs/ledger.md). The email shows allocated, spent
  and over amounts positive, in the budget's currency, per recipient
  locale — or **no amounts at all** when the allocation is gone by send
  time (a stale notification renders metadata only).
- **The link** points at the ledger page; the month itself is stated in
  the body (the ledger page's month navigation is client-side state with
  no URL today).

**Invite emails and unregistered recipients**: the profile-invitation email
may target an email address that has no Prospera account yet. Such
recipients have no settings to honor and always receive the invite (they
cannot opt out of an account they don't have). Once the address belongs to
an account, the toggle (and the master switch) gates the email, re-checked
at send time.

**User-chosen names in the subject**: the invite subject carries the
inviter's and the profile's names, which anyone can choose. There they are
flattened to one line, stripped of anything that looks like a link or a
domain, and capped at 40 characters (`ProfileInvite::subjectText`); when
nothing is left, a generic subject is used. The plain-text body states the
full names.

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

- Emails are **queued**, encrypted in the queue (`ShouldBeEncrypted` —
  payloads carry links and user content), and retried with a backoff
  (`App\Mail\Concerns\RetriesDelivery`: 5 tries over about 20 minutes,
  then logged). Locally `composer dev` runs a worker (works with
  `MAIL_MAILER=log`); in production the scheduler runs it — see
  [Scheduled tasks](../README.md#scheduled-tasks).
- Sent to **verified** addresses only (Fortify-verified accounts; unverified
  users get nothing, silently). Exception — **profile invitations**: the
  share must reach the owner of an existing (not yet verified) account too;
  they cannot re-register with their email, and the accept link
  routes them through email verification before accepting.
- Localized per recipient's `users.locale` (fallback `en`), including
  plain-text parts: the copy lives in `lang/{locale}/mail.php`, one key set
  per available locale (checked by `tests/Feature/QueuedMailTest.php`).
- Plain-text views print values with `{!! !!}`: `{{ }}` HTML-escapes, so a
  name like `Smith & Co` would arrive as `Smith &amp; Co`. Never pass a view
  variable named `$message` — the mailer injects its own.
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
Every toggle controls an implemented email; a toggle added before its
notification ships must not be offered as a control that does nothing.
