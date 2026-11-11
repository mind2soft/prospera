# Localization

Prospera ships in **English (`en`)** and **French (`fr`)**. The interface is
localized with [Vue i18n](https://vue-i18n.intlify.dev/); everything the
server emits — validation errors, toasts, error messages and emails — is
localized with Laravel's translator (`lang/`). This note explains how the
pieces fit together and how to add a new locale.

## How the locale is resolved

The server and the interface resolve the same locale, in this order:

1. **The user's preferred locale** — the `users.locale` column, set once the
   user explicitly picks a language in **Settings → Account → Preferred
   language** (see
   [`ProfileController`](../app/Http/Controllers/Settings/ProfileController.php);
   the choice applies to its own confirmation toast already).
2. **The browser's languages** — the `Accept-Language` header on the server
   (first match among the available locales, `fr-CA` → `fr`), and
   `navigator.languages` on the client.
3. **The default locale** — `config('app.locale')`, `en`.

A `null` `users.locale` therefore means "follow the browser". The list of
selectable locales is validated against `config/app.php` →
`available_locales`.

- **Server** — the [`SetLocale`](../app/Http/Middleware/SetLocale.php)
  middleware (web group) calls `App::setLocale()` on every request, so
  `__()` and Laravel's own messages use the resolved locale. The result is
  shared with the frontend as the `locale` Inertia prop
  ([`HandleInertiaRequests`](../app/Http/Middleware/HandleInertiaRequests.php)),
  next to the authenticated user and its `locale`.
- **Client** — [`initializeLocale()`](../resources/js/composables/useLocale.ts)
  (called from [`resources/js/app.ts`](../resources/js/app.ts)) picks the
  user's locale, else the server's `locale` prop, else `navigator.languages`
  (exact match, then primary subtag), else `en` — so the interface and the
  server's messages agree.
- **Emails** — `User` implements `HasLocalePreference`: mails and
  notifications to a user (account verification, password reset) are
  rendered in their `users.locale`, queued ones included; without a
  preference they use the sending request's locale. Invitation emails use
  the recipient's `users.locale` (fallback `en`), see
  [Notifications](notifications.md). The contact-form email goes to the
  maintainers and stays in English.

## Where translations live

### Interface (Vue i18n)

```
resources/js/locales/
├── index.ts        # merges the per-domain files into a single bundle
├── fr/             # same files and keys as en/
└── en/
    ├── common.json     # shared actions/fields
    ├── auth.json       # authentication pages
    ├── welcome.json    # landing page
    ├── layout.json     # app shell (sidebar, header, user menu, dashboard)
    ├── budget.json     # budget page + dialogs
    ├── dashboard.json  # dashboard page: catalog, widget settings, states
    ├── envelopes.json  # envelopes page + share drawer
    ├── ledger.json     # ledger page + calendar/tables
    ├── settings.json   # settings pages, security, two-factor, passkeys
    ├── share.json      # generic share drawer
    ├── privacy.json    # privacy page chrome (long-form copy lives in resources/content/)
    └── contact.json    # contact page, landing CTA, GitHub tooltip
```

Every file owns a single top-level namespace named after the domain, so the
objects merge without collisions.

### Server (Laravel)

```
lang/
├── fr.json          # French for every __('English sentence') string
├── en/
│   ├── auth.php         # Laravel/Fortify authentication messages
│   ├── mail.php         # email copy (keyed, see docs/notifications.md)
│   ├── pagination.php
│   ├── passwords.php    # password reset broker
│   └── validation.php   # Laravel's validation messages
└── fr/              # same files and keys as en/
```

- App strings are written in English at the call site —
  `__('Envelope created.')` — and translated in `lang/{locale}.json`, keyed
  by the English sentence. English needs no JSON file: the key is the text.
  Laravel's own English strings (verification and password-reset emails,
  the mail layout, Fortify) are translated there too.
- Pass parameters as named placeholders (`__('… :max …', ['max' => 60])`)
  and keep whole sentences: never build a message from translated
  fragments (French grammar differs — "ce budget", "cette enveloppe").
- `lang/fr/validation.php` also names the API fields in French
  (`attributes`: `start_date` → "date de début"); English keeps Laravel's
  default ("start date").
- [`LangParityTest`](../tests/Unit/LangParityTest.php) fails when a locale
  misses a key of `lang/en/*.php`, when a literal `__('…')` string of `app/`
  has no `lang/{locale}.json` entry, or when placeholders differ.

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
- Registering a new locale requires:
  - a sibling folder under `resources/js/locales/` with the same
    namespaces, and an entry in `messages` in
    [`resources/js/locales/index.ts`](../resources/js/locales/index.ts);
  - `lang/<locale>/` with the same files and keys as `lang/en/` (including
    `mail.php`), and `lang/<locale>.json` translating every app string —
    `php artisan test --filter=LangParityTest` lists what is missing;
  - optionally `resources/content/<name>.<locale>.md` translations of the
    long-form pages;
  - an entry in `config/app.php` → `available_locales`.

## Long-form markdown content

Long-form copy (privacy policy, future legal/about pages) does **not** live
in the i18n JSON files: those hold short UI strings, while multi-page
documents are painful to review, diff and translate in JSON. Such documents
are **markdown files, one per locale**, loaded separately and rendered on the
client.

```
resources/content/
├── privacy.en.md    # English is the source of truth
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
