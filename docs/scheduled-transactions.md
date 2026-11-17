# Scheduled transactions

## Implementation status

[Issue #15](https://github.com/mind2soft/prospera/issues/15) is being delivered
incrementally. **Only the pure recurrence resolver is implemented.** No
schedules are persisted or exposed through the API/UI yet, no transactions
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

## Remaining work (#15)

- Encrypted `envelope_schedules` storage, soft deletion, owner-only CRUD,
  validation at the HTTP boundary, preview dates, and generated-transaction
  provenance/uniqueness (`ledger_id`, `schedule_id`, `event_at`). Schedule
  amounts must use the existing [encryption](encryption.md) scheme.
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
```
