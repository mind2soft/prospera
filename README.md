# Prospera

Prospera is a personal finance and budgeting app — the electronic version of
the classic paper **envelope system**: place your cash in envelopes, one per
purpose, and stop spending when an envelope is empty. Prospera replaces the
paper with a clean, friendly interface that keeps the same discipline without
the filing cabinet.

> Not aimed at professional accountants. No double-entry bookkeeping, no chart
> of accounts — just money, envelopes, and clarity.

## Features

- **Dashboard** — your financial pulse at a glance: a configurable widget
  grid (envelope spending, income & expenses history, radial ratios),
  computed server-side and masked per envelope permissions
  ([docs/dashboard.md](docs/dashboard.md))
- **Budget** — plan where your money goes before it goes anywhere
- **Ledger** — a running log of expenses and revenues, one line at a time
- **Envelopes** — category envelopes (housing, groceries, fun…) with a name,
  a direction (expense/revenue) and a color. Fully CRUD-able.
- **Scheduled transactions** — recurring envelope schedules, managed by the
  envelope owner from a drawer on the Envelopes page, materialized as
  read-only, badged entries in open ledgers for months covered by a
  published budget, with optional email reminders before each occurrence
  ([docs/scheduled-transactions.md](docs/scheduled-transactions.md)).
- **Getting started** — a first-login guide, live setup progress, and concise
  help for envelopes, ledgers, transactions and budgets
  ([docs/onboarding.md](docs/onboarding.md))
- **Shared finances** — financial profiles shared with invited members, with
  per-resource owner/contributor/viewer permissions.
- **Encryption at rest** — money amounts are stored encrypted with a key per
  financial profile (AES-256-GCM), rotated monthly; internal schedule storage
  uses independent envelope-scoped keys.
- **Contact form** — a public, rate-limited contact page with a per-IP
  cooldown, a submission log and a queued email to the maintainers.
- **Privacy page** — a public, localized privacy policy at `/privacy`
  (sanitized markdown per locale, with English fallback), linked from the
  landing footer, the sign-up/login pages and the user menu.
- **Notification settings** — a master switch plus per-type toggles that
  gate every system email at send time: payment reminders, ledger expired,
  contributor transactions, envelope limit exceeded and profile invitations
  ([docs/notifications.md](docs/notifications.md)).

Design docs live in [`docs/`](docs/): [getting started](docs/onboarding.md),
[dashboard](docs/dashboard.md),
[budget](docs/budget.md),
[ledger](docs/ledger.md), [envelopes](docs/envelope.md),
[scheduled transactions](docs/scheduled-transactions.md),
[shared finances](docs/shared-finances.md),
[encryption at rest](docs/encryption.md),
[financial write locking](docs/financial-locking.md),
[notifications](docs/notifications.md),
[undoable deletion](docs/undoable-deletion.md) and
[localization](docs/localization.md) (long-form content such as the
[privacy policy](resources/content/privacy.en.md) is documented there too).

## Tech stack

| Layer    | Technology                                                      |
| -------- | --------------------------------------------------------------- |
| Backend  | Laravel 13 (PHP 8.3+; CI on 8.5, release checks on 8.4), SQLite for local development, PostgreSQL 10.23 in production |
| Frontend | Vue 3 + Inertia, Tailwind CSS v4                                |
| UI       | shadcn-vue (reka-nova style)                                    |
| Icons    | Iconify with Material Symbols (bundled offline, no CDN calls)   |
| Fonts    | Geist and Neuton, self-hosted via Fontsource (no CDN calls)     |
| Routing  | Laravel Wayfinder (Laravel routes → typed TypeScript functions) |
| Auth     | Laravel Fortify (email verification, 2FA, passkeys)             |

## Getting started

Requirements: PHP 8.3+ (CI runs 8.5) with `pdo_sqlite`, `gd`, `intl` (see `php -m`),
Composer, Node.js. The release checks additionally run the backend suite on
PostgreSQL 10.23 (the production major) with PHP 8.4 — see
[Testing against PostgreSQL](#testing-against-postgresql).

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

To log in with a realistic dataset, seed a test household for
`test@domain.com` (password `testtest123`, email pre-verified) — the user and
their default profile are created if missing, and the seeder is idempotent
(rerunning wipes that user's envelopes and the profile's budgets and ledgers
before reseeding a 2026 dual-income household: 23 envelopes, 12 published
monthly budgets, one ledger and 348 transactions):

```bash
php artisan db:seed --class=TestHouseholdSeeder
```

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
npm run test             # JS unit tests (Vitest) — no browser or dev server
npm run e2e              # Playwright end-to-end tests
npm run build            # production build
```

### Testing against PostgreSQL

The default suite runs on in-memory SQLite (fast, no setup). The CI's
release-gating `postgres` job additionally runs the backend suite on
**PostgreSQL 10.23 — the production major — with PHP 8.4**: SQLite cannot
exercise PostgreSQL row/shared locks, varchar length enforcement or identical
DDL and transaction behavior, and the two-connection barrier tests
(`Postgres*Test.php`, issues #55, #80 and #81) only run when PostgreSQL is
reachable.

Locally, point the suite at an explicitly named **disposable** database on any
PostgreSQL 10.x instance — never at development or production data (the
environment overrides `phpunit.xml`'s SQLite defaults):

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=prospera_test DB_USERNAME=prospera DB_PASSWORD=secret DB_URL= \
php artisan test
```

The barrier tests create their own database (default
`prospera_barrier_testing`) on the same server through the standard `PG*`
variables, and skip cleanly when PostgreSQL is unavailable:

```bash
PGHOST=127.0.0.1 PGPORT=5432 PGUSER=prospera PGPASSWORD=secret \
PGDATABASE=prospera_barrier_testing php artisan test
```

`scripts/ci/assert-test-runtime.php` asserts the driver, server major and PHP
minor the way the CI job does (`EXPECTED_DB_DRIVER`, `EXPECTED_DB_SERVER`,
`EXPECTED_PHP`) and prints them without credentials, so a green run can never
silently be the wrong stack.

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
retired keys pruned daily (`php artisan amounts:prune-keys`), a daily
`php artisan amounts:verify` re-checks that every amount still decrypts,
deleted transactions are purged every minute once their 3-minute undo window
has passed (`php artisan transactions:purge-deleted` — see
[docs/undoable-deletion.md](docs/undoable-deletion.md)), and
unverified accounts left untouched that hold no data are deleted daily
(`php artisan users:prune-unverified`, retention configurable via
`UNVERIFIED_ACCOUNT_RETENTION_DAYS`, default 30 days — see
[docs/shared-finances.md](docs/shared-finances.md)), and an expired open
ledger is reported to its owner and the profile members once, when the
daily run first detects it (`php artisan ledgers:notify-expired` — see
[docs/notifications.md](docs/notifications.md)), and scheduled transactions
are reconciled daily in every open ledger as an idempotent catch-up for lost
jobs or downtime (`php artisan schedules:sync`), and payment reminders are
queued daily, once per schedule occurrence and recipient, skipping
occurrences already past (`php artisan schedules:remind` — see
[docs/scheduled-transactions.md](docs/scheduled-transactions.md)), and the
financial retention prune deletes ledgers 7 years after their end date,
published budgets 7 years past their effective month and budget drafts
7 years after their creation, exactly as the privacy policy promises
(`php artisan retention:prune` — see [docs/retention.md](docs/retention.md)).
Schedule, budget and ledger changes also queue reconciliation jobs, which the
same queue worker runs.

Emails (notifications, profile invitations, contact messages) are queued on
the `database` queue. In production there is no long-lived worker (shared
hosting): the scheduler runs
`php artisan queue:work --stop-when-empty --max-time=50` every minute, in the
background and without overlapping, and failed jobs are pruned after 30 days
(`queue:prune-failed --hours=720`). Mails retry 5 times with a backoff, then
land in `failed_jobs` and the log (`php artisan queue:failed`,
`php artisan queue:retry all`).

**In production, the scheduler is mandatory** — without it no email is ever
sent and no key rotates. Install this crontab entry for the deploy user once
(it follows the `current` release link, so it survives deploys):

```cron
* * * * * cd /path/to/app/current && php artisan schedule:run >> /dev/null 2>&1
```

Deploys and rollbacks run `php artisan queue:restart` after switching
releases, so a long-lived worker — if a host runs one instead, e.g. under
systemd or supervisor with `php artisan queue:work --tries=3 --max-time=3600`
— never keeps serving the replaced release's code. Remove the scheduled
`queue:work` from `routes/console.php` on such a host.

### Encryption at rest

Money amounts are encrypted with per-profile keys wrapped by `APP_KEY` — see
[docs/encryption.md](docs/encryption.md). **Back up `APP_KEY` separately from
the database: without it, no amount can be read.** Rotate it with
`APP_PREVIOUS_KEYS` and `php artisan amounts:rewrap-keys`, never with a bare
`key:generate`; `php artisan amounts:verify` checks that every amount
decrypts. Contact messages, the queued mail payloads and the 2FA secrets are
encrypted with `APP_KEY` directly: `php artisan contact:reencrypt` migrates
the retained contact messages during a rotation, and `php artisan
encryption:verify` gates the removal of `APP_PREVIOUS_KEYS` over all of them.

Beware: `php artisan key:generate` replaces the key in place — run it against
an existing database only after backing the current `APP_KEY` up and restoring
it (or adding it to `APP_PREVIOUS_KEYS`) — a rotated key with no previous key
left makes every amount undecryptable, permanently. `composer setup` itself is
safe to rerun: it generates an `APP_KEY` only when none is configured and the
database is confirmed fresh (`app:ensure-key`); on an existing migrated
database, or an uninspectable one, it refuses and aborts before migrating. A
rotated key is caught early: production refuses HTTP requests at boot with an
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

Pushing a tag runs the `deploy` workflow: the full test suite — including the
PostgreSQL 10.23 / PHP 8.4 release checks and the Playwright e2e suite — runs
first, then the app is packaged and deployed as
release `v1.2.3` to [prospera.mind2soft.com](https://prospera.mind2soft.com).
Failing tests abort the release.

Required production environment variables:

- `MAIL_CONTACT_ADDRESS` — recipient of the contact-form submissions; the app
  **fails fast at boot** without it (issue #17). Set it as a variable of the
  GitHub `Production` environment: the deploy renders it into the `.env` and
  refuses to deploy when it is missing or not a single email address.
- `GITHUB_REPO_URL` — public repository shown on the landing page (defaults
  to the canonical repo; self-hosted deployments can point it elsewhere).
- `CONTACT_LOG_RETENTION_DAYS` — retention of the contact-submission log
  (default 90 days).
- `UNVERIFIED_ACCOUNT_RETENTION_DAYS` — retention of never-verified accounts
  that hold no data (default 30 days).
- `FINANCIAL_RETENTION_YEARS` — financial data retention: ledgers, published
  budgets and budget drafts are deleted by the daily `retention:prune` after
  this many years past their end date, effective month or creation
  (default 7 — the published privacy-policy promise).

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
