# Data retention

This document describes the **automated retention prune** behind the
promises published in the localized privacy policies
(`resources/content/privacy.{en,fr}.md`) — issue
[#61](https://github.com/mind2soft/prospera/issues/61). The policy page is
the user-facing commitment; this document is how the service implements it.

## The rules

The prune runs daily (`php artisan retention:prune`, scheduled with
`withoutOverlapping` and `onOneServer`) and deletes, per resource:

| Resource | Kept until | Deleted |
| -------- | ---------- | ------- |
| **Ledger** (open or closed), with **all of its transactions** — pending deletions included | 7 years after its `end_date` | at the start of the day after the 7th anniversary |
| **Published budget**, with its shares, envelope assignments and envelope-limit notification claims | 7 years after the **end of its effective month** | at the start of the day after the 7th anniversary of the month's last day |
| **Budget draft** | 7 years after its `created_at` | when a daily run finds it past the window |

Examples (the ones the privacy policy publishes):

- a ledger ending **December 31, 2026** is kept through 2033 and deleted at
  the start of **2034**;
- a published budget effective for **January 2026** (month ending
  January 31, 2026) is deleted at the start of **February 2033**;
- a draft created **March 10, 2026** is deleted on **March 11, 2033**.

The boundary is *strict*: a resource whose period ended exactly on the
cutoff date is kept ("kept until 7 years after…"), the first eligible day
is the day after the anniversary. The window is configurable via
`FINANCIAL_RETENTION_YEARS` (default `7`, the published policy value).

## What is never pruned

- **Envelopes and scheduled transactions** — not bound to a period; they
  are removed with their owner's account (see
  [shared finances](shared-finances.md)).
- **Financial profiles** — deleting a ledger or budget never deletes the
  profile, its members, or its amount keys.
- **Contact submissions** (`contact:prune`, 90 days by default),
  **never-verified accounts holding no data**
  (`users:prune-unverified`, 30 days), **deleted-transaction purges**
  (`transactions:purge-deleted`, 3-minute undo window) and **failed
  queued jobs** (30 days) each follow their own, separate retention rules.
- Ledger end dates are immutable today; the prune keys on the covered
  period, so open, closed, expired and re-opened ledgers all share the
  same rule.

## How the prune works

- **Plaintext selection only.** Candidates are selected from the date
  columns (`ledgers.end_date`, `budgets.effective_year` /
  `effective_month` / `created_at` / `published_at`) — **no decryption,
  no SQL arithmetic, and no filtering on encrypted amounts**
  ([encryption at rest](encryption.md)). Reports name resources; they
  never contain financial data.
- **Idempotent and catch-up-safe.** There is no last-run watermark: the
  selection is purely date-based, so re-runs, overlapping runs and
  catch-ups after downtime converge to the same result. Nothing is due
  before 2033 under the default window.
- **Bounded.** Candidates are paged (`lazyById`); a large backlog is
  processed chunk by chunk, one resource at a time.
- **Per-resource transaction boundaries.** Each ledger is deleted through
  `Ledger::deleteWithTransactions()` — the ledger-first serialization
  lock, its transactions force-deleted, then the ledger row. Each budget
  follows the same path as the budget controller's delete endpoint: row
  lock, eligibility re-check under the lock, cascade of shares,
  assignments and envelope-limit claims, then a schedule reconciliation
  job queued **after commit** so the removed published coverage takes its
  generated rows out of the open ledgers
  ([scheduled transactions](scheduled-transactions.md)).
- **Eligibility is re-checked under the lock.** A resource selected by the
  scan is re-verified against its fresh, locked row before any deletion;
  a resource that became ineligible (or vanished) is skipped, never
  partially deleted.
- **Failures are isolated and reported.** One resource failing to delete
  is `report`ed and skipped; the run continues with the rest and exits
  non-zero when something failed. The next daily run retries the
  remainder.

## Exceptions and operator cautions

- **No advance notice is sent**: the published policy does not promise a
  warning before the irreversible retention deletion, and the prune sends
  none. (The separate *expired ledger* notification
  [notifications.md](notifications.md) fires years earlier, when an open
  ledger first passes its end date.)
- **Backups**: row deletion is not backup erasure, and the policy does not
  claim it is. Operators keep their own backup-retention rules.
- **Dry-run first**: `php artisan retention:prune --dry-run` lists the
  candidate resources and counts without writing anything.
- **Deactivation**: self-hosted operators who prefer to keep history
  indefinitely can remove the schedule line from `routes/console.php` —
  but then the service no longer behaves as its privacy policy promises,
  so `FINANCIAL_RETENTION_YEARS` (e.g. a very large value) or a policy
  correction is the better lever.
