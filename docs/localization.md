# Localization

The interface is localized with [Vue i18n](https://vue-i18n.intlify.dev/).
This note explains how the pieces fit together and how to add a new locale.

## How the locale is resolved

The active locale is resolved on every page load (in
[`resources/js/app.ts`](../resources/js/app.ts), via
[`initializeLocale()`](../resources/js/composables/useLocale.ts)), in this
order:

1. **The user's preferred locale** — stored on the `users.locale` column,
   shared to the frontend through the authenticated user in
   [`HandleInertiaRequests`](../app/Http/Middleware/HandleInertiaRequests.php).
2. **The browser locales** (`navigator.languages`) — first exact match
   (e.g. `en-GB`), then primary subtag match (e.g. `en-GB` → `en`).
3. **The fallback locale** — `en`.

A `null` `users.locale` therefore means "follow the browser". The preference
is only stored once the user explicitly picks a language in
**Settings → Account → Preferred language** (see
[`ProfileController`](../app/Http/Controllers/Settings/ProfileController.php)).
The list of selectable locales is validated against
`config/app.php` → `available_locales`.

## Where translations live

```
resources/js/locales/
├── index.ts        # merges the per-domain files into a single bundle
└── en/
    ├── common.json     # shared actions/fields
    ├── auth.json       # authentication pages
    ├── welcome.json    # landing page
    ├── layout.json     # app shell (sidebar, header, user menu, dashboard)
    ├── budget.json     # budget page + dialogs
    ├── envelopes.json  # envelopes page + share drawer
    ├── ledger.json     # ledger page + calendar/tables
    ├── settings.json   # settings pages, security, two-factor, passkeys
    ├── share.json      # generic share drawer
    ├── privacy.json    # privacy page chrome (long-form copy lives in resources/content/)
    └── contact.json    # contact page, landing CTA, GitHub tooltip
```

Every file owns a single top-level namespace named after the domain, so the
objects merge without collisions. Only English (`en`) ships today; adding
more locales is tracked in separate issues.

## Conventions

- In `<script setup>`, use `useI18n()` → `const { t } = useI18n()`; in
  templates, `$t('domain.key')`.
- Interpolate with named arguments: `$t('key', { name })` and `{name}` in the
  message. Pluralize with pipes: `t('key', count)` and
  `"no items | one item | {n} items"`.
- `<Head :title="$t('...')" />` for document titles, and i18n **keys** as
  breadcrumb titles in `defineOptions({ layout: { breadcrumbs: [...] } })` —
  [`Breadcrumbs.vue`](../resources/js/components/Breadcrumbs.vue) translates
  them at render time (`$te` guard).
- Dates and numbers must be formatted with the **active locale**, not
  `undefined` or a hard-coded locale tag — inject it with
  `useLocale()` from `@/composables/useLocale`:
  `date.toLocaleDateString(locale.value, { ... })`.
- Never change rendered English text while extracting a string: e2e specs
  assert on the visible text.
- Registering a new locale requires: a sibling folder under
  `resources/js/locales/` with the same namespaces, an entry in
  `messages` in [`resources/js/locales/index.ts`](../resources/js/locales/index.ts),
  and an entry in `config/app.php` → `available_locales`.

## Long-form markdown content

Long-form copy (privacy policy, future legal/about pages) does **not** live
in the i18n JSON files: those hold short UI strings, while multi-page
documents are painful to review, diff and translate in JSON. Such documents
are **markdown files, one per locale**, loaded separately and rendered on the
client.

```
resources/content/
└── privacy.en.md    # English is the source of truth
└── privacy.fr.md    # optional translations
```

- **File naming** — `<name>.<locale>.md` (e.g. `privacy.fr.md`); the
  `<name>` is consumed by
  [`useLocalizedDocument()`](../resources/js/composables/useLocalizedDocument.ts),
  which loads the file for the active locale and **falls back to the English
  file** when no translation exists (same rule as the JSON bundles).
- **Front matter** — each file starts with a small front-matter block
  (`---` delimited) carrying the document's publication date:

  ```markdown
  ---
  last-updated: 2026-11-09
  ---
  ```

  The page shows it as a localized "Last updated" date (formatted in UTC, so
  the displayed date matches the published one in every timezone). The front
  matter is stripped before rendering.
- **Sanitization is mandatory** — the markdown is rendered client-side with
  a sanitized renderer ([`marked`](https://marked.js.org/) +
  [`DOMPurify`](https://github.com/cure53/DOMPurify) in
  [`Privacy.vue`](../resources/js/pages/Privacy.vue)); user-visible HTML must
  be inert. Any new long-form page must go through the same pattern — never
  render raw markdown as HTML.
- The files are bundled as **lazy chunks** (`import.meta.glob`, non-eager),
  so the app bundle carries none of the copy until the page needs it.
- The document body contains **no `<h1>` heading** — the page component owns
  the (localized) `<h1>` title, so the title stays localized even when the
  content falls back to English. Start document headings at `##`.
