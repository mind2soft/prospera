# Scheduled transactions

## Implementation status

[Issue #15](https://github.com/mind2soft/prospera/issues/15) is being delivered
incrementally. **The recurrence resolver and internal encrypted schedule
storage are implemented.** There is no schedule API/UI yet, no transactions
are generated, and no reminders are sent. The payment-reminder toggle stays
disabled. There are no `schedules:sync` or `schedules:remind` commands yet.

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
lookup occurs. A future materializer must preserve the date and store
`event_at` at `00:00:00`, following the existing transaction convention.

The resolver jumps by recurrence intervals, not every elapsed day since the
schedule began. Callers should use bounded windows such as one published
budget month. Resolving consecutive, non-overlapping windows produces the
same occurrences as resolving their combined window, allowing deterministic
catch-up after downtime. This is **not** transaction-level idempotency:
the future materializer must enforce that with database uniqueness/locking.

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
  does not convert currency units to/from minor units. Future HTTP/UI callers
  must convert their input explicitly once, then sign it from the envelope's
  direction. Fractional values are rejected; zero is allowed.
- Amounts use the existing AES-256-GCM format and guarded Eloquent writes.
  The AAD binds the envelope key scope/id, table/column, and start date.
  Moving a schedule to another envelope is forbidden; changing its start
  date requires assigning its amount in the same save. The amount is hidden
  from default array/JSON serialization; a future owner-only resource must
  explicitly authorize and expose it.
- Schedule amounts use **envelope-scoped data keys**, not a default or
  arbitrarily selected financial profile's keys. An envelope can span several
  profiles; changing/deleting a profile or transferring the envelope must not
  strand its schedules. See [key lifecycle](encryption.md#envelope-scoped-keys).
- The first schedule save creates its envelope's key lazily in the same
  transaction; no key is created merely by creating an envelope. A missing
  key for an existing schedule fails closed instead of creating a replacement.
- Saves validate recurrence fields, reminder days (null or 0–2,147,483,647),
  and amount sign, including `saveQuietly`/eventless saves. HTTP validation,
  authorization and resource serialization remain future work, not model
  responsibilities. Numeric day/month/reminder fields must be integers, not
  floats or numeric strings, at this internal boundary.
- Writers lock the envelope before schedules/keys, serializing first-key
  creation, direction changes, deletion, and key rotation. Active schedules
  block explicit envelope deletion and direction changes. Deleted schedules
  are purged before an otherwise valid direction change; envelope/account
  deletion cascades schedule and key rows. Profile membership alone never
  makes a schedule profile-owned. Ownership follows the envelope.
- Schedules are soft-deleted and included in amount-key rotation, pruning,
  and verification. This prepares the lifecycle for the future requirement
  that generated rows in closed ledgers retain their schedule provenance.
  **No generated transactions or provenance columns exist yet.**

## Remaining work (#15)

- Owner-only CRUD, validation at the HTTP boundary, preview dates, and
  generated-transaction provenance/uniqueness (`ledger_id`, `schedule_id`,
  `event_at`). Add the provenance FK and generated-row mutation guards
  together with materialization rather than exposing half-managed entries.
- Reconciliation for open ledgers with published-budget month coverage;
  schedule create/edit/delete, budget publish, ledger open/reopen, and daily
  catch-up triggers. Closed ledgers must remain untouched, including when a
  schedule is soft-deleted. Generated rows must be read-only server-side.
- Daily `schedules:sync` and `schedules:remind`, durable per-occurrence and
  recipient reminder deduplication, send-time recipient/permission/settings
  checks, masked amounts, and English/French mail content. See
  [notification requirements](notifications.md) and the issue's reminder
  acceptance criteria. Never put decrypted amounts in queued payloads.
- Localized owner-only schedules drawer/dialog and generated-row badges;
  re-enable payment reminders only once sending is implemented and tested.
- Integration and e2e coverage, plus updates to budget/ledger/envelope docs
  and the README's scheduled commands when those capabilities actually ship.

The recurrence unit suite runs without a database or Laravel boot:

```bash
vendor/bin/phpunit tests/Unit/Schedules
vendor/bin/phpunit tests/Feature/EnvelopeScheduleStorageTest.php tests/Unit/Security
```
