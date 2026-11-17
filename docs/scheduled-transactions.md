# Scheduled transactions

## Implementation status

[Issue #15](https://github.com/mind2soft/prospera/issues/15) was delivered
incrementally. **The recurrence resolver, encrypted storage, owner-only
definition API, transaction provenance and materialization into open ledgers
(with the daily `schedules:sync` catch-up), the owner-only schedules drawer
on the Envelopes page and the daily payment reminders (`schedules:remind`)
are implemented.**

## Calendar rules (implemented)

`App\Services\Schedules\ScheduleRule` is an immutable, validated value object
containing only recurrence fields, not amounts, ownership, or permissions:

| Field | Rule |
| --- | --- |
| `period` | `once`, `weekly`, `biweekly`, `monthly`, or `yearly` |
| `startDate` | Required ISO `YYYY-MM-DD`, first eligible date |
| `endDate` | Optional inclusive ISO date, on/after `startDate` |
| `weekdays` | Required for weekly/biweekly only; one or more ISO weekdays (Mon = 1, Sun = 7), deduplicated and sorted |
| `dayOfMonth` | Required for monthly/yearly only; integer 1–31 |
| `month` | Required for yearly only; integer 1–12 |

Unused fields must be empty (`[]` for weekdays, `null` for day/month).
Invalid periods, dates, bounds, or period-specific fields throw
`InvalidArgumentException`. Dates must be real Gregorian calendar dates
in years 0001–9999; relative dates, timestamps and overflowing dates are not
accepted.

`ScheduleOccurrenceResolver::resolve($rule, $from, $to)` returns unique,
chronologically sorted ISO dates in the **inclusive intersection** of the
query window and the rule bounds. The query requires valid ISO dates and
`from <= to`. Disjoint windows return `[]`.

- **Once:** exactly `startDate`.
- **Weekly:** each selected weekday on/after `startDate`, every seven days.
- **Biweekly:** each selected weekday's first occurrence on/after
  `startDate`, then every 14 days. Equivalently, the first occurrence anchors
  a seven-day active window followed by seven inactive days. This does not
  use ISO week parity or restart at a query/year boundary. For example,
  starting Wed 2026-03-04 with Mon + Thu selected gives Mar 5, 9, 19, 23
  (not Mar 5, 16, 19). A later query keeps those same anchors.
- **Monthly:** `dayOfMonth` in each eligible month, clamped to that month's
  last day. Day 31 gives Jan 31, Feb 28/29, Mar 31, Apr 30: clamping never
  changes subsequent months' requested day.
- **Yearly:** `month` + `dayOfMonth`, with the same clamping. Feb 29 becomes
  Feb 28 in non-leap years and returns to Feb 29 in leap years.

Dates are calendar labels, not instants. UTC midnight is used internally only
as a stable arithmetic container; no timezone conversion or current-clock
lookup occurs. The materializer preserves the date and stores `event_at` at
`00:00:00`, following the existing transaction convention.

The resolver jumps by recurrence intervals, not every elapsed day since the
schedule began. Callers should use bounded windows such as one published
budget month. Resolving consecutive, non-overlapping windows produces the
same occurrences as resolving their combined window, allowing deterministic
catch-up after downtime. This is **not** transaction-level idempotency: the
materializer enforces that with a unique index and ledger locking.

```php
use App\Services\Schedules\ScheduleOccurrenceResolver;
use App\Services\Schedules\ScheduleRule;

$rule = new ScheduleRule('monthly', '2026-01-01', dayOfMonth: 31);
$dates = (new ScheduleOccurrenceResolver)->resolve($rule, '2026-02-01', '2026-03-31');
// ['2026-02-28', '2026-03-31']
```

## Encrypted storage (implemented)

`EnvelopeSchedule` persists the recurrence fields above using snake_case
column names, with `envelope_id`, `amount`, nullable `reminder_days`,
timestamps, and `deleted_at`. `weekdays` is JSON (empty for non-weekly rules).
`start_date`/`end_date` stay ISO date strings, not timestamps. `rule()` adapts
the model directly to the tested recurrence resolver.

- `amount` is a **signed integer in minor units**: `-12345` means an expense
  of 123.45 currency units. Unlike `Transaction::amount`, this internal model
  does not convert currency units to/from minor units. The definition API
  accepts an integer minor-unit magnitude and signs it from the envelope's
  direction. A future UI must convert human currency input exactly once.
  Fractional minor-unit values are rejected; zero is allowed.
- Amounts use the existing AES-256-GCM format and guarded Eloquent writes.
  The AAD binds the envelope key scope/id, table/column, and start date.
  Moving a schedule to another envelope is forbidden; changing its start
  date requires assigning its amount in the same save. The amount is hidden
  from default array/JSON serialization; the API's explicit owner-authorized
  serializer exposes it only after locking and rechecking ownership.
- Schedule amounts use **envelope-scoped data keys**, not a default or
  arbitrarily selected financial profile's keys. An envelope can span several
  profiles; changing/deleting a profile or transferring the envelope must not
  strand its schedules. See [key lifecycle](encryption.md#envelope-scoped-keys).
- The first schedule save creates its envelope's key lazily in the same
  transaction; no key is created merely by creating an envelope. A missing
  key for an existing schedule fails closed instead of creating a replacement.
- Saves validate recurrence fields, reminder days (null or 0–366,
  `EnvelopeSchedule::MAX_REMINDER_DAYS`),
  and amount sign, including `saveQuietly`/eventless saves. HTTP validation,
  authorization and resource serialization are implemented separately at the
  API boundary. Numeric day/month/reminder fields must be integers, not
  floats or numeric strings, at this internal boundary.
- Writers lock the envelope before schedules/keys, serializing first-key
  creation, direction changes, deletion, and key rotation. Active schedules
  block explicit envelope deletion and direction changes. Deleted schedules
  are purged before an otherwise valid direction change; envelope/account
  deletion cascades schedule and key rows. Profile membership alone never
  makes a schedule profile-owned. Ownership follows the envelope.
- Schedules are soft-deleted and included in amount-key rotation, pruning,
  and verification, so generated rows in closed ledgers keep a valid
  provenance FK to a deleted schedule (see [Materialization](#materialization-implemented)).

## Definition API (implemented)

All endpoints live in the session-authenticated, verified-email **web**
middleware group, including CSRF protection for writes. The envelope owner
alone may list or mutate schedule definitions. A visible envelope's profile
co-members/referencing-profile members receive **403**, even with an envelope
viewer/contributor grant; outsiders and stale grantees without a current
profile relationship receive **404**. Nested bindings reject schedules of
another envelope and exclude soft-deleted schedules.

| Method | Path | Response |
| --- | --- | --- |
| `GET` | `/api/envelopes/{envelope}/schedules` | `200 { schedules: [...], meta: { current_page, last_page, per_page, total } }` |
| `POST` | `/api/envelopes/{envelope}/schedules` | `201 { schedule: ... }` |
| `PATCH` | `/api/envelopes/{envelope}/schedules/{schedule}` | `200 { schedule: ... }` |
| `DELETE` | `/api/envelopes/{envelope}/schedules/{schedule}` | `204`, soft-deletes the definition |

Writes accept JSON. Required create fields: `period`, `start_date`, `amount`.
The **input** `amount` is a nonnegative integer minor-unit magnitude, capped
at 99,999,999,999 (the same maximum currency value as manual transactions).
The **output** `amount` is signed by the envelope direction and still in
minor units. For example, POST `amount: 12345` on an expense envelope returns
`amount: -12345`, representing a 123.45 expense. Numeric strings, booleans,
fractional minor units, and negative magnitudes are rejected. Ownership and
storage metadata (`id`, `envelope_id`, `profile_id`, `user_id`, `deleted_at`,
`created_at`, `updated_at`) must not be supplied, even as null.

Recurrence fields follow the canonical rules above. The API requires unique
integer ISO weekdays in a JSON list; the model/resolver also normalize their
order. End dates are inclusive and may not precede the start date.
`reminder_days` may be null (no reminder) or an integer 0–366 (see
[Payment reminders](#payment-reminders)). Unused weekday/day/month fields must
be empty/null.

PATCH accepts partial definitions and validates the merged result against
the **fresh locked row**, not stale route-bound data. When the period changes,
omitted fields that are no longer relevant are cleared automatically;
explicitly supplied irrelevant fields still cause 422. For example, changing
monthly → weekly with `weekdays: [1, 4]` clears the old `day_of_month`.
Changing the start date explicitly re-binds the existing encrypted amount
without changing its value. Metadata-only changes leave ciphertext unchanged.
An empty PATCH is a no-op.

Responses expose the recurrence fields, signed minor-unit amount, timestamps,
`open_ledger_entries` and `next_occurrences`. `open_ledger_entries` counts the
schedule's generated rows in **open** ledgers (any profile), i.e. the entries
an edit or a deletion rewrites; closed-ledger history is not counted. It is a
single grouped `count(*)` over provenance ids per listed page (no amounts are
read), taken without ledger locks, so it is approximate under concurrent
reconciliation. It is `0` on creation and is computed **before** the queued
reconciliation of an update. `next_occurrences` holds at most **five**
upcoming ISO dates, inclusive of today. Optional IANA `timezone` on GET/POST/PATCH chooses the requesting
owner's calendar day; without it the application timezone (UTC) applies,
matching [LocalCalendar](budget.md). Dates themselves are never shifted.
The preview uses the canonical resolver over a bounded period-specific
window (35/70 days, five months, or five years), starting no earlier than the
schedule start; distant future schedules and year 9999 are supported.

GET is id-ordered and paginated (`page`, default 1; `per_page`, default 50,
maximum 100). A composite `(envelope_id, id)` index supports scoped listing
and key maintenance. Authorization occurs before validation, is rechecked
under the envelope row lock, and precedes amount decryption. Reads take a
shared envelope lock; writes take an exclusive envelope lock before schedule
rows, consistently with key rotation and envelope lifecycle operations.

Create and update queue reconciliation of the affected open ledgers after
commit; DELETE removes the schedule's generated rows from open ledgers in the
same database transaction (see [Materialization](#materialization-implemented)).
Reminders follow the stored `reminder_days` on the next daily
[reminder run](#payment-reminders). The Envelopes page drawer below is the UI
entry point.

## Materialization (implemented)

`App\Services\Schedules\ScheduleReconciler` converges **one open ledger** at
a time with its expected generated rows.

### What lands where

An occurrence on date `D` of an active (not soft-deleted) schedule is recorded
in ledger `L` when:

1. `L` is **open** (`closed_at` null). Expired-but-open ledgers count: a
   ledger is never auto-closed.
2. `L.start_date ≤ D ≤ L.end_date`.
3. The month of `D` is covered by a **published budget of `L`'s profile**
   that assigns the schedule's envelope. The latest published budget of a
   month wins, like allocation reporting.

Coverage is a **whole calendar month**: once a month is covered, every
occurrence of that month is generated at once, including dates later than
today. Because budgets can only be published for the current month, months
after the current one are never covered, so no row is generated beyond the
current month. Months without a published budget or without the envelope
assigned get nothing. Closed ledgers are never touched.

Generated rows are ordinary `transactions` rows with:

- `schedule_id` set (nullable FK to `envelope_schedules`, `restrict` on
  delete, which works with soft-deleted schedules);
- `user_id = null` (the API and the ledger show "System" as the author);
- `event_at` at `00:00:00` of the occurrence date;
- the schedule's signed amount, written in **exact integer minor units**
  (`Transaction::setAmountMinorUnits`). Schedule amounts are minor units,
  while `Transaction::amount` is currency units. The reconciler never goes
  through the currency-unit accessor or floats.

A unique index on `(ledger_id, schedule_id, event_at)` makes one occurrence
exist at most once per ledger. Manual rows have a null `schedule_id`, so
manual duplicates on the same day stay allowed.

### Read-only rows

Generated rows are system-managed:

- `DELETE /api/transactions/{id}` and its restore endpoint reject them for
  everyone (ledger owner included) with a `422` validation error on
  `transaction`. There is no transaction update endpoint.
- A manual `POST` may not carry `schedule_id` (`422`).
- `schedule_id` is immutable on saves and refused in bulk updates. It is not
  mass-assignable.
- The ledger API exposes a boolean `scheduled`. The ledger page shows a
  localized **Scheduled** badge, "System" in the user column, and no delete
  action. Amount masking follows the usual envelope permissions.
- No contributor-transaction mail is sent for generated rows.

To change a generated row, edit its schedule.

### Reconciliation

Each run recomputes the full expected set and converges the ledger to it. It
inserts missing rows, rewrites changed amounts in place (same row ids), and
permanently deletes superfluous rows. The relevant set is the **union** of
current coverage and the ledger's existing generated rows (their
envelopes/months), so rows whose coverage, assignment or schedule disappeared
(including soft-deleted schedules) are cleaned up. Manual rows are never
read for reconciliation and never changed.

There is no last-run watermark. Repeated or overlapping runs, retries and
catch-up after downtime all converge to the same rows without duplicates.

Lock order (see [financial write serialization](financial-locking.md)):

1. Ledger row, exclusive. The read-only state is re-read under it. The
   profile key memo is refreshed.
2. Covering published budgets, shared, ascending id.
3. Every relevant envelope (coverage ∪ existing generated rows), shared,
   ascending id. Envelope key memos are refreshed.
4. Schedule reads, then transaction writes.

No profile root is taken beneath the ledger. Money is compared and written in
PHP only: no SQL arithmetic or filtering on encrypted amounts. Each ledger is
one database transaction with deadlock retries.

### Triggers

All triggers are ID-only queued jobs dispatched **after commit**. Jobs are
deliberately not unique or coalesced: each recomputes from current state
under the ledger lock, so an edit committed while a run is in progress gets
its own run.

| Change | Job | Ledgers reconciled |
| --- | --- | --- |
| Schedule created or edited | `ReconcileScheduleLedgers` (envelope) | Open ledgers of profiles with a published budget assigning the envelope, plus open ledgers holding the envelope's generated rows |
| Schedule deleted | none, synchronous (below) | Its open-ledger rows are removed in the same transaction |
| Budget published (including replacement) or a published budget deleted | `ReconcileScheduleLedgers` (profile) | Every open ledger of the profile, in full |
| Ledger created or reopened | `ReconcileLedgerSchedules` | That ledger |
| Daily `php artisan schedules:sync` | none, in-process | Every open ledger |

Fan-out jobs discover the ledgers when they run, after the triggering commit.
Ledgers created or reopened concurrently dispatch their own job. Reopening a
ledger applies everything that changed while it was closed, including the
removal of rows of schedules deleted in the meantime.

`schedules:sync` runs daily (`->daily()->withoutOverlapping()->onOneServer()`).
It reconciles each open ledger in its own transaction. A failing ledger is
reported (`report()` plus an error line naming the ledger) without stopping
the others, and the command then exits non-zero. It prints the inserted,
updated and deleted totals.

### Schedule deletion

`DELETE /api/envelopes/{envelope}/schedules/{schedule}`
(`App\Services\Schedules\ScheduleDeletion`) soft-deletes the schedule and
permanently removes its generated rows from **open** ledgers atomically:

1. Discover every ledger holding rows of the schedule. This includes closed
   ledgers and soft-deleted rows.
2. Lock those ledgers in ascending id order, **before** the envelope.
3. Lock the envelope exclusively, reauthorize ownership on the fresh row, and
   lock the schedule.
4. Requery the ledger set. If a reconciliation committed rows in a ledger
   that was not locked, roll the whole attempt back and rediscover (at most
   three attempts). The set is never extended beneath the envelope lock.
5. Delete the open-ledger rows, then soft-delete the schedule.

Closed-ledger rows keep their `schedule_id` (FK to the soft-deleted
schedule). Reopening such a ledger removes them.

### Envelope limit notifications

Reconciliation records the expense groups (ledger × envelope × month) whose
spending **grew**: an inserted row, or an amount made more negative. It
dispatches an ID-only, after-commit `CheckEnvelopeLimitGroup` job for each
group, starting from the lowest changed row id. See
[notifications](notifications.md) for the batch-safe crossing rule. Removals
and decreases only lower spending, so they cannot cross an allocation and are
not checked.

## UI (implemented)

On the Envelopes page, the envelope menu (shown to the **owner** only) has a
**Schedules** item opening a right drawer
(`resources/js/components/schedules/ScheduleDrawer.vue`). Contributors and
viewers never get the item; the API refuses them anyway.

- **List** — view-only rows, each with a localized summary built by
  `resources/js/lib/schedule.ts` (`describeSchedule`): the rule ("Bi-weekly
  on Mon and Thu", "Monthly on day 31 (or the last day of shorter months)"),
  the bounds ("From Mar 1, 2026" / "From … to …"), the reminder ("No
  reminder", "Remind on the day", "Remind 3 days before", pluralized) and the
  API's next dates. Dates, weekday/month names, lists and numbers use the
  active locale's `Intl` formatters. The amount is shown with `Currency.vue`
  in the profile's latest published budget currency (minor units ÷ 100,
  display only). Edit and delete icon buttons, an **Add schedule** button and
  an empty state complete the drawer; load errors offer a retry.
- **Dialog** (`ScheduleDialog.vue`) — amount, repeat period, start (or the
  one-off date), optional end date, weekday toggles (weekly/bi-weekly),
  day of month (monthly/yearly), month (yearly), and a reminder select
  (None / Same day / Days before + a number). Only the fields of the chosen
  period are shown and sent; the others are sent empty. The weekday, day of
  month and month default from the start date until the user sets them (or
  the edited schedule defines them). The typed amount is
  converted to integer minor units from its digits (never by float
  multiplication) and sent unsigned: the server signs it. Client validation
  mirrors the server rules; server `422` messages are shown on their fields
  (`weekdays.N` → weekdays). Editing is dialog-only (no in-place editing).
- **Affected entries** — the edit dialog and the delete confirmation warn
  "Affects ~N ledger entries in open ledgers" from `open_ledger_entries`
  when it is positive.
- Every read and write goes through TanStack Vue Query
  (`resources/js/composables/scheduleQueries.ts`). Writes invalidate the
  envelope's schedule list, every ledger transaction report and the
  dashboard data. Generated rows of a create/edit appear once the queued
  reconciliation has run; deletions are synchronous.
- All copy lives under `envelopes.schedules`
  (`resources/js/locales/{en,fr}/envelopes.json`).
- `e2e/schedules.spec.ts` covers create → edit → delete with the ledger's
  Scheduled rows following. The e2e queue is `database` with no worker, so
  the spec drains it (`drainQueue()` in `e2e/support.ts`) after each write.

## Payment reminders

`php artisan schedules:remind` (`App\Console\Commands\SendScheduleReminders`)
runs daily (`->daily()->withoutOverlapping()->onOneServer()`) and queues
[`App\Mail\PaymentReminder`](../app/Mail/PaymentReminder.php) emails, gated
by the **Payment reminders** toggle ([notifications](notifications.md)).

### When

Each active (not soft-deleted) schedule with a non-null `reminder_days` `n`
reminds every occurrence `D` with `D − n ≤ today ≤ D` — `n = 0` reminds on the
day itself. Occurrences come from the canonical resolver over the window
`[today, today + n]` (the application calendar day, UTC, like
`ledgers:notify-expired`). Schedules with a null `reminder_days` and
soft-deleted schedules get none.

- **Catch-up, never late.** A run missed during downtime is caught up by the
  next run while the occurrence is still today or later. An occurrence that
  has already passed is **skipped, not sent late**: a reminder after the
  fact is noise rather than a warning.
- **Bounded lead.** `reminder_days` is validated to at most 366
  (`EnvelopeSchedule::MAX_REMINDER_DAYS`, mirrored by `MAX_REMINDER_DAYS` in
  `resources/js/lib/schedule.ts`) by the API and the model, so a run's
  window never spans more than a year.
- Reminding does not depend on materialization: an occurrence in a month
  without a published budget yet (e.g. the 1st of next month, reminded
  three days before) is still reminded.

### Once per occurrence and recipient

`schedule_reminders` holds one row per (schedule, occurrence date,
recipient), with a unique index that **is** the atomic claim. The row is
inserted (`insertOrIgnore`) and the mail queued in the same transaction —
the `database` queue commits them together — so re-runs, overlapping runs,
job retries and catch-up never double-send. Users filtered out at dispatch
(unverified, toggle off) are not claimed: re-enabling reminders before the
occurrence still reminds them.

The claim is keyed on the date. An edit that moves an occurrence re-arms the
reminder for the **new date only**; a reminder already queued for the old
date no longer matches the schedule and is suppressed at send time. Rows
cascade with their schedule (hard deletion only — soft-deleted schedules
keep them) and with the recipient's account. Each run first prunes the
claims of occurrences before today: those dates are never reminded again,
so the table only holds claims that still prevent a double send.

### Recipients

The envelope **owner** plus every member of the **profiles referencing the
envelope** — the profiles with a published budget assigning it
(`Budget::publishedAssigning`), i.e. the profiles whose open ledgers the
schedule materializes into. Draft-only assignments do not count.
Recipients are deduplicated (one email per user per occurrence, however many
profiles they share), **verified only**, and **re-resolved at send time**: a
member who left the profile, or a profile whose budgets no longer assign the
envelope, gets nothing.

### Send time

The mailable carries IDs only (schedule id, occurrence date, recipient id) —
no name, address or amount rides in the (encrypted) queue payload. At send
time it re-checks that the schedule still exists, is not soft-deleted, still
has a reminder and still occurs on that date, that the date has not passed,
that the recipient is still eligible (above) and verified, and the
`User::wantsNotification(NotificationSettings::PAYMENT_REMINDERS)` gate
(master and per-type).

- **Amount masking.** The recipient's envelope permission is resolved at
  send time. Below `viewer` (`none`, metadata-only profile members) the email
  shows the envelope name and the date only — no amount line at all.
- **Amount.** Decrypted in PHP from `EnvelopeSchedule::amount` (exact minor
  units) only once masking passed, shown positive and formatted with the
  currency of the latest published budget assigning the envelope in one of
  the recipient's profiles — the occurrence month's budget first — else of
  any referencing profile, else USD. No SQL touches the encrypted column.
- **Localization.** Subject and plain-text body follow `users.locale`
  (fallback `en`), from `lang/{en,fr}/mail.php` (`payment_reminder`). The
  text view prints with `{!! !!}` (no `&amp;`) and ends with the "Manage your
  notifications" link to Settings → Notifications.

The recurrence unit suite runs without a database or Laravel boot:

```bash
vendor/bin/phpunit tests/Unit/Schedules
vendor/bin/phpunit tests/Feature/EnvelopeScheduleStorageTest.php tests/Unit/Security
vendor/bin/phpunit tests/Feature/EnvelopeScheduleApiTest.php
php artisan test tests/Feature/PaymentReminderTest.php
php artisan test --filter='ScheduleMaterializationTest|ScheduleLimitNotificationTest|PostgresScheduleDeletionTest|EnvelopeLimitCheckerTest'
npx vitest run resources/js/lib/schedule.test.ts
TZ=UTC npm run e2e -- e2e/schedule-api.spec.ts e2e/schedules.spec.ts e2e/ledger.spec.ts e2e/notifications.spec.ts
```
