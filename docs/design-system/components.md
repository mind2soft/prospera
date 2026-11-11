# Components and patterns

Use this guide to compose Prospera screens from the existing Vue primitives.
The M3 component model is a reference for clear roles and predictable
behavior, not a requirement to use Material Web tags or copy its visual
styling literally.

## Component source and selection

Start with the app's existing wrappers under `resources/js/components/ui/`:

| Need | Use |
| --- | --- |
| Action | `ui/button` `Button` with a supported variant and size. |
| Form control | `ui/input`, `ui/select`, `ui/checkbox`, `ui/switch`, and `ui/label`; use Reka-based wrappers. |
| Grouped surface | `ui/card` when actual content grouping helps. |
| Important interruption | `ui/dialog` for focused tasks and confirmations. |
| Side task/settings | `ui/sheet` for a supporting panel that should preserve page context. |
| Context action | `ui/dropdown-menu` for a compact set of actions. |
| Short status | `ui/badge` or `ui/alert` according to whether the message is inline status or a meaningful callout. |
| Loading | `ui/skeleton` for known content shape; `ui/spinner` only for a small, indeterminate operation. |
| Notifications | Existing `vue-sonner` integration and `flashToast` conventions. |

Before adding a primitive, search `resources/js/components/ui/` and
`resources/js/components/` for an existing equivalent. Extend or compose the
local wrapper rather than adding a new UI library. Don't edit generated
vendor code or make app-specific exceptions in unrelated generated components.

## Page structure

- Use the established app/auth layouts and navigation. Keep a single clear
  page title, then the page's main task, then supporting information.
- Align page titles, selectors, and primary actions consistently with nearby
  screens. On narrow screens, let actions wrap or move into an intentional
  menu; do not squeeze labels until they become unreadable.
- Keep content grouped by user purpose. Not every group needs a card, border,
  background tint, or shadow.
- Set sensible content width for reading and task density. A ledger can be
  denser than an onboarding message; do not force both into the same layout.
- Use page breadcrumbs and localized titles through the established Inertia
  layout pattern.

## Actions and buttons

- A filled/primary button is for the most important action in a local context.
  Avoid multiple competing primary buttons in one form, dialog, or toolbar.
- Use outline/secondary for a consequential alternative; ghost/text for
  low-emphasis contextual actions; destructive only for a destructive action.
- Button labels describe the result (“Save changes”, “Add envelope”, “Delete
  transaction”), not generic mechanics (“Submit”, “OK”) when a specific label
  fits. Keep the action name consistent through its confirmation and success
  feedback.
- Provide visible pending/disabled feedback during async operations and prevent
  duplicate submissions. Do not represent an error as a success toast.
- Icon-only actions need an accessible name, a clear hit target, and a tooltip
  where it helps discovery. Keep adjacent actions distinct and avoid relying on
  color alone.

## Forms

- Give every input a persistent visible label. A placeholder is an example or
  format hint, never the only label.
- Keep related fields together and in the order users expect to complete them.
  Explain unusual financial terminology in concise helper text.
- Put validation feedback next to the relevant control, identify the problem
  in plain language, and explain how to fix it. Associate the message with the
  field for assistive technology and move focus to an appropriate error
  summary when a multi-field submit fails.
- Preserve user-entered values after recoverable errors. Show currency,
  precision, date range, and signed amount meaning at the point of entry when
  relevant.
- Do not make auto-save look like a manual Save flow. When the established
  product behavior is debounced auto-save, expose a clear saving/saved/error
  status and retain the product's conflict/error handling.

## Dialogs, sheets, and menus

- Use a dialog for a focused decision that blocks the current task; use a sheet
  for settings or supporting content users may need to compare with the page.
- Every dialog has a concise title, contextual description when needed, a
  predictable close/cancel action, and a visible primary action. Initial focus,
  focus containment, Escape behavior, and focus restoration must work.
- Confirm destructive actions when they cannot be easily undone. State what
  will be removed or changed and name the destructive button explicitly.
- Menus contain actions or navigation choices, not a dense form. Use semantic
  menu components and keyboard behavior instead of hand-built popovers.
- Keep popovers and floating content inside the viewport; test at zoom and
  narrow widths.

## Financial data, tables, and charts

- Use `Currency.vue` and the locale utilities for money. Align repeated values
  to the inline end and use tabular numerals. Keep currency and sign semantics
  unambiguous; do not silently change stored direction to fit a visual.
- Keep row labels, dates, amounts, and actions in a consistent order. Use
  semantic table markup for tabular data and explicit column headers. On small
  screens, choose a deliberate compact row/card presentation or a labeled
  scroll region rather than clipping important columns.
- Show the period/filter context near the data it controls. Empty data should
  say what is empty and offer the next useful action when the user can create
  data.
- For charts, provide a textual title and labels/legend or a data equivalent.
  Tooltips must be keyboard-accessible where interactive. Keep chart palettes
  consistent and do not encode revenue/expense, good/bad, or permission using
  color alone.
- **Masked data is not zero.** Follow server-provided masking flags and show
  the established masked representation. Never infer a value from layout,
  chart geometry, accessibility text, placeholder length, tooltip, or a
  success/error message. A group containing restricted values must respect the
  product spec's whole-group masking behavior.

## Content states

For each data-driven view, design the states the feature actually supports:

- **Loading:** skeletons match the expected structure and do not resemble
  actual financial values. Use a live status where the transition needs to be
  announced.
- **Empty:** distinguish “not created yet” from “no results for these filters”;
  offer a specific next step when possible.
- **Error:** name what failed and give a recovery action. Keep retry local to
  the failed content where possible; don't replace usable data with a blank
  page.
- **Pending/success:** communicate asynchronous save, invitation, or mutation
  state. Preserve the same action vocabulary as the control.
- **Disabled/read-only:** say why an action is unavailable when that is not
  self-evident; do not disguise lack of permission as missing data.
- **Masked:** show only values and metadata the current permission allows.

Don't add decorative status color or motion to a state that already has a
clear text and control treatment.

## Localization and direction

Use i18n messages for all user-visible strings. Format numbers, dates, and
currencies for the active locale with the project's `useLocale()`/
`Currency.vue` conventions. Use CSS logical properties and direction-aware
alignment (`inline-start`/`inline-end`) rather than hard-coded left/right
assumptions. Keep DOM order in the same meaningful reading and keyboard order;
do not use CSS `order` to visually rearrange content in a way that conflicts
with that sequence. Test longer translated strings, plural forms,
bidirectional numbers, and RTL layout when applicable.

## Validation before merge

Follow [accessibility.md](accessibility.md) and the checklist in the
[design-system overview](README.md). For visual changes, inspect at least one
narrow and one wide viewport, plus light and dark themes if the component
supports both. Reuse the existing Playwright E2E patterns under `e2e/` for
important user-visible flows; don't introduce a component-test framework that
isn't already in the project without an explicit reason.
