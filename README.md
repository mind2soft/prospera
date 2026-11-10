# Prospera

Prospera is a personal finance and budgeting app — the electronic version of
the classic paper **envelope system**: place your cash in envelopes, one per
purpose, and stop spending when an envelope is empty. Prospera replaces the
paper with a clean, friendly interface that keeps the same discipline without
the filing cabinet.

> Not aimed at professional accountants. No double-entry bookkeeping, no chart
> of accounts — just money, envelopes, and clarity.

## Features

- **Dashboard** — your financial pulse at a glance
- **Budget** — plan where your money goes before it goes anywhere
- **Ledger** — a running log of expenses and revenues, one line at a time
- **Envelopes** — category envelopes (housing, groceries, fun…) with a name,
  a direction (expense/revenue) and a color. Fully CRUD-able.
- **Shared finances** — financial profiles shared with invited members, with
  per-resource owner/contributor/viewer permissions.
- **Encryption at rest** — money amounts are stored encrypted with a key per
  financial profile (AES-256-GCM), rotated monthly.
- **Contact form** — a public, rate-limited contact page with a per-IP
  cooldown, a submission log and a queued email to the maintainers.
- **Privacy page** — a public, localized privacy policy at `/privacy`
  (sanitized markdown per locale, with English fallback), linked from the
  landing footer, the sign-up/login pages and the user menu.
- **Notification settings** — a master switch plus per-type toggles that
  gate every system email at send time.

Design docs live in [`docs/`](docs/): [budget](docs/budget.md),
[ledger](docs/ledger.md), [envelopes](docs/envelope.md),
[shared finances](docs/shared-finances.md),
[encryption at rest](docs/encryption.md),
[notifications](docs/notifications.md) and
[localization](docs/localization.md) (long-form content such as the
[privacy policy](resources/content/privacy.en.md) is documented there too).

## Tech stack

| Layer    | Technology                                                      |
| -------- | --------------------------------------------------------------- |
| Backend  | Laravel 13 (PHP 8.3+, CI on 8.5), SQLite for local development  |
| Frontend | Vue 3 + Inertia, Tailwind CSS v4                                |
| UI       | shadcn-vue (reka-nova style)                                    |
| Icons    | Iconify with Material Symbols (bundled offline, no CDN calls)   |
| Routing  | Laravel Wayfinder (Laravel routes → typed TypeScript functions) |
| Auth     | Laravel Fortify (email verification, 2FA, passkeys)             |

## Getting started

Requirements: PHP 8.3+ (CI runs 8.5) with `pdo_sqlite`, `gd`, `intl` (see `php -m`),
Composer, Node.js.

```bash
composer setup   # installs dependencies, creates .env, migrates, builds assets
```

### Daily development

A single command starts the whole app — HTTP server, queue worker, log tail
(Pail) and the Vite dev server with HMR:

```bash
composer dev
```

Then visit **http://localhost:8000**.

> **Emails (verification, password reset):** no mail server is required in
> development — `MAIL_MAILER=log` writes emails to `storage/logs/laravel.log`.
> For a real inbox experience, run [Mailpit](https://mailpit.axllent.org/) on
> port 1025 and switch to `MAIL_MAILER=smtp`.

### Quality checks

```bash
composer ci:check        # everything CI runs: biome check, vue-tsc, Pint, PHPStan, tests
composer test            # Pint (check) + PHPStan + PHPUnit
php artisan test         # PHPUnit test suite only
composer lint            # fix PHP formatting (Pint)
npm run check            # check frontend formatting + lint (Biome)
npm run check:fix        # fix frontend formatting + lint issues
npm run types:check      # vue-tsc
npm run e2e              # Playwright end-to-end tests
npm run build            # production build
```

### Fixing formatting issues

Formatting covers two distinct tools, depending on the language:

- **PHP** is formatted with [Pint](https://laravel.com/docs/pint):
  `composer lint` fixes it, `composer lint:check` only verifies it.
- **JS/Vue/CSS** (`resources/js/**`, `resources/css/**`) is formatted and
  linted by [Biome](https://biomejs.dev) (configured in `biome.jsonc`):
  `npm run check` only verifies it — `composer lint` does **not** fix it. Run
  `npm run check:fix` to apply the fixes automatically.

If `composer ci:check` reports `Formatting issues found` in `.vue` files
(which `composer lint` cannot solve), run `npm run check:fix` and commit the
result. Both checks run as part of `composer ci:check` and `composer test`, so
fix formatting before opening a PR.

### Pre-commit formatting hook

Commits automatically format the staged PHP and frontend files: Pint formats
`*.php`, Biome formats `*.{css,json,jsonc,js,ts,vue}`, and the fixed files are
included in the commit. The hook runs on staged files only and never blocks a
commit — lint issues are caught by `composer ci:check`.

The hook is installed by `composer setup` (`vp hooks enable`). In an existing
clone, enable it once:

```bash
npx vp hooks enable
```

The dispatcher lives in `.vite-hooks/_` (generated, gitignored); its
configuration is the `staged` block in `vite.config.ts` and the committed
`.vite-hooks/pre-commit`. Skip it for a single commit with
`VP_GIT_HOOKS=0 git commit`.

### Scheduled tasks

Expired invites are pruned daily (`php artisan invites:prune`), the
contact-form submission log is pruned daily (`php artisan contact:prune`,
retention configurable via `CONTACT_LOG_RETENTION_DAYS`), amount encryption
keys are rotated monthly (`php artisan amounts:rotate-keys --all`),
retired keys pruned daily (`php artisan amounts:prune-keys`) and a daily
`php artisan amounts:verify` re-checks that every amount still decrypts. In
production, run the scheduler (`php artisan schedule:run` every minute via
cron).

### Encryption at rest

Money amounts are encrypted with per-profile keys wrapped by `APP_KEY` — see
[docs/encryption.md](docs/encryption.md). **Back up `APP_KEY` separately from
the database: without it, no amount can be read.** Rotate it with
`APP_PREVIOUS_KEYS` and `php artisan amounts:rewrap-keys`, never with a bare
`key:generate`; `php artisan amounts:verify` checks that every amount
decrypts.

Beware: `composer setup` runs `php artisan key:generate` unconditionally. Run
it against an existing database only after backing the current `APP_KEY` up
and restoring it (or adding it to `APP_PREVIOUS_KEYS`) — a rotated key with
no previous key left makes every amount undecryptable, permanently. A rotated
key is caught early: production refuses HTTP requests at boot with an
explicit, actionable error once the amount keys no longer unwrap, and the
scheduled daily `amounts:verify` reports it in the logs even when no request
touches amounts.

## Releases & deployment

Production is **only ever deployed from a `v*` tag** — merging to `main` never
deploys on its own:

```bash
git tag -a v1.2.3 -m "Release v1.2.3"
git push origin v1.2.3
```

Pushing a tag runs the `deploy` workflow: the full test suite (including the
Playwright e2e suite) runs first, then the app is packaged and deployed as
release `v1.2.3` to [prospera.mind2soft.com](https://prospera.mind2soft.com).
Failing tests abort the release.

Required production environment variables:

- `MAIL_CONTACT_ADDRESS` — recipient of the contact-form submissions; the app
  **fails fast at boot** without it (issue #17).
- `GITHUB_REPO_URL` — public repository shown on the landing page (defaults
  to the canonical repo; self-hosted deployments can point it elsewhere).
- `CONTACT_LOG_RETENTION_DAYS` — retention of the contact-submission log
  (default 90 days).

- **Merges to `main`** run the full test suite (unit, static analysis and e2e)
  to catch regressions early — but never touch production.
- **Rollback:** manually run the `deploy` workflow with `action: rollback`
  (optionally naming a release ID — a directory name under `releases/`, such
  as a tag; empty = the previous release). Production
  migrations are forward-only: a rollback only switches to a release with the
  same migrations, and a deploy failing during or after its migrations stays
  in maintenance mode until a fix is deployed — see
  [docs/encryption.md](docs/encryption.md#deployments).

## The envelope system, briefly

The paper method works like this: when you get paid, you divide the cash into
envelopes labelled with your spending categories. Spending draws from the
right envelope; an empty envelope means "stop" — not "put it on the credit
card".

Prospera digitises this: envelopes hold your categories, the ledger feeds them,
and your budget shows which envelopes still have room. Start with
**Envelopes → create your first envelope**, then log transactions in
**Ledger**.

## License

Prospera — Copyright © 2026 Yanick Rochon.

Prospera is dual-licensed:

- **[GNU Affero General Public License v3.0 (AGPL-3.0)](https://www.gnu.org/licenses/agpl-3.0.html)** —
  you may use, modify and redistribute Prospera under the terms of the AGPL-3.0.
  If you run a modified version — even as a network service, without
  distributing it — you must make its complete source available under the
  same license.
- **Commercial license** — if the terms of the AGPL-3.0 do not fit your use
  case (e.g. you wish to distribute a modified or unmodified version without
  being bound by its copyleft terms), contact the copyright holder for a
  commercial license.

Unless you have obtained a separate commercial license, your use of Prospera
is governed by the AGPL-3.0 as published in [LICENSE](LICENSE).
