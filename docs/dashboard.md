# Dashboard

The dashboard is the app's "financial pulse at a glance": a configurable
widget grid, per financial profile, privacy-aware. Amount aggregates are
computed **server-side** and envelope-permission masking is respected — raw
amounts never reach a client that is not allowed to see them.

## Concepts

- **Widget** — one tile on the dashboard: a type (`envelope_spending`,
  `income_expenses`, `radial`), a view type, a column width, a period and an
  envelope selection.
- **Dashboard** — the ordered list of a user's widgets **for the currently
  selected financial profile**. Every profile member manages their own
  dashboard per profile; there is no shared dashboard.
- **Selection** — the envelopes a widget aggregates: envelope rows and
  flat, single-level groups (e.g. a "Combine income" group summing Paycheck +
  Passive income).

## Data schema

```typescript
// Stored columns (dashboard_widgets table):
type DashboardWidget = {
    id: number;                    // PK
    user_id: number;               // FK users.id — each member owns their widgets (not part of the API payload)
    financial_profile_id: number;  // FK financial_profiles.id — config is per profile (not part of the API payload)
    type: 'envelope_spending' | 'income_expenses' | 'radial';
    title: string | null;          // null → localized default title
    view_type: string;             // widget-specific (below); validated against the type
    column_width: 1 | 2 | 3 | 4 | 5;
    period: { type: 'days' | 'months'; range: PeriodRange };
    selection: SelectionItem[];    // ordered; see below
    order: number;                 // drag & drop ordering (column: sort_order)
    created_at: string;            // not part of the API payload
    updated_at: string;            // not part of the API payload
};

// API payload per widget (serializeWidget): id, type, title, view_type,
// column_width, period, selection, order.

type SelectionItem =
    | { kind: 'envelope'; envelope_id: number }
    | { kind: 'group'; name: string; envelope_ids: number[] };
```

Configuration only — **no money amounts are stored** in
`dashboard_widgets`. Amounts live (encrypted) in `transactions` and
`budget_envelopes` and are computed at request time.

## Grid & layout

- Responsive columns: **1 column at `xs`, 5 columns at `xl`** (measured on
  the grid container: `≥1100px` → 5, `≥1024px` → 4, `≥768px` → 3,
  `≥640px` → 2, below → 1). Widgets flow top-to-bottom (vertical packing)
  and follow the layout direction (`ltr`/`rtl`) of the active locale —
  CSS grid placement is direction-aware.
- Every widget has a **column width setting (1–5)**. A widget never spans
  more than the viewport's column count: `span min(column_width, columns)`.
- **Drag & drop** through the drag handle (`drag_indicator`); the widget
  moves together with its settings. The order is an ordered list
  (`sort_order`), saved by the same autosave as every other setting.
  Dragging always has alternatives (WCAG 2.5.7): the focused handle moves
  its widget with the arrow keys (Home/End: first/last; the new position
  is announced), and the settings sheet offers **Move earlier / Move
  later**.
  Implementation note: `vue-grid-layout` (and its Vue 3 port) and GridStack
  were evaluated; both impose fixed row heights, which conflicts with the
  intrinsic-height requirement below — the grid is therefore a native CSS
  grid with HTML5 drag & drop (no grid dependency).
- Heights are intrinsic per widget type (auto-height content rows; long
  lists scroll **inside** the widget instead of stretching the grid row).

## Widget catalog

| Type                 | View types                  | Purpose                                        |
| -------------------- | --------------------------- | ---------------------------------------------- |
| `envelope_spending`  | `single`                    | Spent totals per envelope with budget progress |
| `income_expenses`    | `stacked_bars`, `line`      | Revenues vs expenses over the period (trends)  |
| `radial`             | `pie`, `donut`              | Ratios of the selected envelopes (`v = \|a\|`) |

Charts are rendered with [Unovis](https://unovis.dev/) (`@unovis/vue`), the
charting layer shadcn-vue wraps. Charts render client-side only (SSR
renders the "no data yet" state).

### Common widget settings

Every widget exposes, in the settings **right sidebar** (same convention as
the Share drawer):

- **title** (free text; empty = default localized title)
- **view type** — per the catalog above
- **column width** (1–5)
- **period type** — days / months
- **period range** (canonical enumeration):
  - days: **Today** (1), past 2 days, past 3 days, **past week (7)**,
    past 2 weeks (14)
  - months: **Current month** (1), past 2, 3, 6, **12** months
- **envelope selection** (below)
- *(future)* forecast modifier — the config schema deliberately keeps room
  for additional fields; forecasting itself is a future feature.

### Period semantics

- "Today" is the **requester's calendar day**: the widget data request
  carries the browser's IANA time zone as `timezone` (unknown zones →
  `422`); without it the application time zone (UTC) applies. Transaction
  dates are calendar dates, compared as such.
- `days`, range *n* — the past *n* days **including today**
  (`[today − (n−1), today]`).
- `months`, range *n* — the current month back *n* months inclusive,
  month-to-date (`[first day of (current month − (n−1)), today]`).
- History series are bucketed **per day** for `days` periods and **per
  calendar month** for `months` periods.

### Selection semantics

- Envelopes and groups may be mixed freely; **a group cannot contain
  another group** (flat, single level).
- Within one widget's selection, each envelope may appear **at most once**
  (in an envelope row *or* inside a group) — no double counting; the picker
  only offers not-yet-used envelopes.
- Every selected envelope must be visible to the requester (owned, shared,
  or metadata-visible via profile membership) — validated server-side.
- **Deleted envelopes are pruned from selections server-side** on read (and
  groups shrink accordingly); groups that end up empty are dropped — no
  orphan rows.

### Widget semantics

- **Envelope spending** — per selected envelope/group, the sum of recorded
  transactions over the period, plus budget progress: the denominator is
  the **sum of the allocations on the published budgets covering the
  period's months** (envelope allocations summed across those months). If
  no published budget covers the period (or the envelope is not allocated
  on it), the progress bar is empty and the spent total still shows.
  Overspend renders the bar over-full with a destructive color; revenue
  rows render as income progress (bar fills from the opposite side).
- **Income & expenses history** — a time-series of revenues (positive) and
  expenses (negative) over the period, per the period's bucketing.
- **Radial** — absolute totals per selection item (`v = |a|`); the legend
  lists every selected row with its percentage share.

## Data, privacy & masking

- All aggregates are **computed server-side** by the widget data endpoint:
  transactions are hydrated (decrypting amounts) and summed **in PHP** —
  money is never aggregated, filtered or sorted in SQL
  ([docs/encryption.md](encryption.md)).
- Aggregates are scoped to the **selected profile** (the transactions of
  the profile's ledgers — open and closed — within the period window).
- **Masking rule**: any aggregate that includes an envelope the requester
  cannot amount-see (envelope permission below `viewer`) is masked —
  `amount: null` + `masked: true`, with the envelope metadata (name,
  color, direction) still present. Grouped sums never leak member amounts
  by omission: a group containing a masked member is masked as a whole, and
  the income/expenses series is masked entirely when any selected envelope
  is masked.

## API (auth + CSRF; TanStack Query everywhere)

| Method   | Path                                                 | Description                                                                                                     |
| -------- | ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `GET`    | `/api/dashboard/{profileId}/widgets`                 | Ordered widgets for (user, profile) — `404` for non-members; selections pruned of deleted envelopes             |
| `PUT`    | `/api/dashboard/{profileId}/widgets`                 | Full layout save (ordered list; each member manages **their own** dashboard for that profile; validates types, ranges, widths, one-occurrence-per-envelope selection, flat groups). Widgets carrying their id are updated **in place** (ids stay stable, so in-flight widget-data requests keep resolving); entries without an id are created; ids missing from the payload are removed |
| `DELETE` | `/api/dashboard/{profileId}/widgets/{widget}`        | Remove one widget (own only; `404` otherwise)                                                                    |
| `GET`    | `/api/dashboard/{profileId}/widgets/{widget}/data`   | Server-computed aggregate payload for the widget (masked per permissions), with `period`/`selection` applied server-side; optional `timezone` query param (IANA) for "today" |

The widget data endpoint returns, per type:

```typescript
type EnvelopeSpendingData = {
    type: 'envelope_spending';
    rows: {
        key: string; kind: 'envelope' | 'group';
        envelope_id?: number; name: string; color?: string;
        direction?: 'expense' | 'revenue';
        masked: boolean;
        spent: number | null;      // signed sum of recorded transactions
        allocated: number | null;  // null = no covering budget allocation
    }[];
};

type IncomeExpensesData = {
    type: 'income_expenses';
    masked: boolean;               // true → every bucket's amounts are null
    series: { bucket: string; masked: boolean; revenue: number | null; expenses: number | null }[];
};

type RadialData = {
    type: 'radial';
    rows: { key: string; kind: 'envelope' | 'group'; envelope_id?: number; name: string; color?: string; masked: boolean; value: number | null }[];
};
```

## Persistence & refresh behavior

- Saved with **debounced autosave** (~600 ms) on every change (project
  convention: no save buttons); the layout is sent in full and the server
  assigns ids and order. Saves are serialized (one in flight, at most one
  queued per profile) by `composables/useLayoutAutosave.ts`; leaving the
  page — another page, a reload or closing the tab — sends a pending edit
  right away (`useFlushOnLeave`, `keepalive` requests), and a failed save
  shows an error toast. Removing widgets is destructive → confirmation.
- Widget data auto-refreshes **every 15 minutes** via vue Query
  `refetchInterval`, **paused when the tab is hidden**
  (`document.visibilityState`) so background tabs don't burn requests.
- Data mutations on other pages (transactions, budgets, envelope shares)
  invalidate the dashboard queries (`invalidateDashboardData`), so widgets
  refetch on the next mount or poll.
- Empty dashboard: a friendly empty state ("Make it yours" + Add widget
  CTA) — **no seeded widgets**. Loading: skeletons per widget; errors:
  inline retry; localized formatting (dates/numbers per the active locale,
  RTL-aware) via `useLocale()`.

## Adding a widget type (future)

1. Add the type to `DashboardWidget::TYPES` and its view types to
   `DashboardWidget::VIEW_TYPES`.
2. Extend the canonical period enumeration only if genuinely needed
   (`DashboardWidget::PERIOD_RANGES`).
3. Implement the aggregate branch in
   [`DashboardAggregator`](../app/Services/Dashboard/DashboardAggregator.php)
   (masking included) and the frontend widget component + catalog entry +
   i18n keys (`dashboard.json`).
