# Getting started

New accounts see the getting-started guide the first time they open the
dashboard. **Getting started and help** in the user menu opens it again at any
time. The guide links to focused pages for [envelopes](envelope.md),
[ledgers](ledger.md), [transactions](ledger.md#transactions), and
[budgets](budget.md).

The first visit is tracked by `users.onboarding_seen_at`. Existing accounts
are marked as already seen during the migration; new accounts are redirected
to the guide once. Invite acceptance still preserves its success notice on the
new user's first onboarding visit.

## Suggested first steps

1. Create at least one revenue envelope and three expense envelopes. Start
   with useful broad categories (for example, Paycheck, Housing, Groceries and
   Transport); add detail later when it helps.
2. Create a ledger and record transactions as they happen. Add at least one
   transaction to each of the profile owner's envelopes.
3. After at least 30 elapsed days of transaction history, create the first
   budget. The app shows the remaining envelope and history requirements on
   the relevant pages.
4. Review and adjust the first draft before publishing it for the current
   month.

## Setup rules and scope

Ledger creation requires at least one revenue envelope and three expense
envelopes owned by the financial profile's owner. This is enforced by the
server as well as surfaced in the Ledger and Envelopes pages. It applies to
successor ledgers too; ledger periods remain independently selectable and
need not be twelve months long.

The first budget can be created when both conditions are met:

- At least 30 elapsed days have passed since the first transaction was
  recorded in one of the owner's envelopes on this profile's ledgers. The
  waiting period uses the app's record-creation time, so entering historical
  event dates does not bypass it.
- Every envelope owned by the profile owner has at least one transaction on a
  ledger in this profile.

Envelopes are account-owned rather than profile-owned, so the setup checklist
uses the current profile owner's envelopes. Shared-profile ledger history is
scoped to the selected profile. Once the first draft has been created,
subsequent drafts and revisions use the existing budget lifecycle and are not
blocked by first-budget setup. The profile keeps this completion state even if
the first draft is later deleted.

The first draft includes an assignment for each of the owner's envelopes
with activity during the trailing 30 days. Its starting amount is that
envelope's **net signed total** over that window (revenues positive, expenses
negative), with the assignment marked variable. The amount is only a starting
point: review it before publishing. Transactions are encrypted at rest, so
the server decrypts and sums them in PHP; the database never aggregates
encrypted amount columns. Since budget assignments use whole currency units,
the signed total is rounded to the nearest whole unit (half away from zero)
before it is assigned. Envelopes with no activity in the trailing window are
not pre-assigned.

## Page guidance

- **Envelopes** shows how many revenue and expense envelopes remain before a
  ledger can be opened. When the minimum is reached, a one-time prompt offers
  to continue to the Ledger page or keep organizing envelopes.
- **Ledger** links to envelope setup when the minimum has not been reached.
  The first-budget progress message reports days and envelopes still needed.
- **Budget** explains the first-budget requirements and links back to the
  getting-started guide.
- A question-mark help link beside each page title opens its focused guide.

The one-time congratulations prompts are remembered in the browser for each
profile. They are optional navigation aids; server-side setup checks remain
authoritative.
