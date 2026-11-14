# Getting started

New accounts see the getting-started page (`/onboarding`) the first time they
open the dashboard. **Getting started and help** in the user menu opens it
again at any time; `/help` redirects to its field guides (`/onboarding#guides`).
It links to four illustrated field guides — [envelopes](envelope.md),
[ledgers](ledger.md), [transactions](ledger.md#transactions), and
[budgets](budget.md) — at `/help/{envelopes,ledgers,transactions,budgets}`.

The first visit is tracked by `users.onboarding_seen_at`. Existing accounts
are marked as already seen during the migration; new accounts are redirected
to the guide once. Invite acceptance still preserves its success notice on the
new user's first onboarding visit.

## The 30-day route

Setup is presented as a four-stage route. Stages are derived on the client
from the progress endpoint (`GET /api/onboarding`, see
`resources/js/lib/onboardingJourney.ts`); the server rules below stay
authoritative.

| Stage | Done when | Next move |
| --- | --- | --- |
| 1. Name your envelopes | 1 revenue + 3 expense envelopes (`can_create_ledger`) | `/envelopes?new=revenue\|expense` |
| 2. Open your ledger | the profile has a ledger (`has_ledger`) or any envelope has a transaction | `/ledger?create=1` |
| 3. The 30-day challenge | 30 days of history and a transaction in every envelope | `/ledger#add-transaction` |
| 4. Draft your first budget | the first budget exists (`first_budget_created`, or any budget on the profile) | `/budget?create=1` |

The first stage not done is *current*; later stages are *locked* (their guides
stay readable). Adding an envelope after the challenge started re-opens it
until that envelope has a transaction, matching the server rule. The route
only ever shows counts and days, never amounts.

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

## Where the route appears

- **Getting started** (`/onboarding`): hero with the next move, the full
  route with live progress on the current stage, the pledge, and the field
  guides list. Members who don't own the current profile see a read-only
  overview (no progress, no pledge).
- **Setup journey bar** under the page header of Envelopes, Ledger and Budget:
  a mini route, the status ("Step 3 of 4 · Day 12 of 30") and, on the page
  where the current stage happens, its live body (envelope slots and starter
  chips on Envelopes, the 30-day strip on Ledger). It can be collapsed
  (remembered per profile in this browser,
  `prospera.onboarding.journey-collapsed.{profileId}`) but not dismissed, and
  disappears once the route is complete. When a teaching empty state already
  explains the step, the bar stays collapsed.
- **Dashboard**: a compact version of the route above the widgets (not a
  widget; it can't be moved or removed), until the first budget exists.
- **Teaching empty states** on Envelopes, Ledger and Budget explain what the
  page needs first and offer one primary action — the Budget page no longer
  shows a disabled create button before the first budget is unlocked.
- A help button beside each page title opens its field guide.

Setup progress is private to the profile owner: the progress query only runs
for profiles the user owns, so members never see the bar, the dashboard route
or owner-only progress.

## Field guides

Each guide (`resources/js/components/help/guides/`) follows one template: a
hero with reading time and, for owners, a live status badge; an "In 30
seconds" summary; two to four short sections, each with one figure; do/avoid
advice; a "Your challenge" block with the owner's live progress and next
action; previous/next links. Example amounts are fixed constants
(`resources/js/lib/helpGuides.ts`), labelled "Example", and formatted in the
profile's budget currency when it has one, else the locale's example currency
(`onboarding.example.currency`). Copy lives in `resources/js/locales/*/help.json`.

## Deep links

| Link | Effect |
| --- | --- |
| `/envelopes?new=revenue\|expense[&name=…]` | Opens the create dialog prefilled (direction, name, next palette colour). |
| `/ledger?create=1` | Opens the create-ledger dialog once setup allows it. |
| `/ledger#add-transaction` | Scrolls to and focuses the transaction form. |
| `/budget?create=1` | Opens the create-budget dialog once creation is allowed. |

The query or hash is removed after use, so a refresh doesn't replay it. These
are navigation aids only; the server still validates every action.

## Milestones and pledge

Three one-time milestone dialogs mark the moments that change what the user
can do: *envelopes ready* (Envelopes page, after the create that reaches the
minimum), *budget unlocked* (Ledger page after a transaction, and on load of
Ledger, Budget or Dashboard if day 30 passed while away), and *first draft*
(Budget page, after the first budget is created). Each is remembered per
profile in this browser (`prospera.onboarding.{envelopes-complete,budget-ready,first-budget}.{profileId}`).

The **pledge** on the getting-started page lets the user commit to up to
three habits. It is saved in this browser only, per user
(`prospera.onboarding.pledge.{userId}`), and echoed on the Ledger journey bar
during the challenge. These are optional aids; server-side setup checks remain
authoritative.
