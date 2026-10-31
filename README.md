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
- **Enveloppes** — category envelopes (housing, groceries, fun…) with a name,
  a direction (expense/revenue) and a color. Fully CRUD-able.
- **Shared finances** — financial profiles shared with invited members, with
  per-resource owner/contributor/viewer permissions.

Design docs live in [`docs/`](docs/): [budget](docs/budget.md),
[ledger](docs/ledger.md), [enveloppes](docs/enveloppe.md) and
[shared finances](docs/shared-finances.md).

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
composer ci:check        # everything CI runs: vp check, vue-tsc, Pint, PHPStan, tests
composer test            # Pint (check) + PHPStan + PHPUnit
php artisan test         # PHPUnit test suite only
composer lint            # fix PHP formatting (Pint)
npm run types:check      # vue-tsc
npm run e2e              # Playwright end-to-end tests
npm run build            # production build
```

### Scheduled tasks

Expired invites are pruned daily (`php artisan invites:prune`). In production,
run the scheduler (`php artisan schedule:run` every minute via cron).

## The envelope system, briefly

The paper method works like this: when you get paid, you divide the cash into
envelopes labelled with your spending categories. Spending draws from the
right envelope; an empty envelope means "stop" — not "put it on the credit
card".

Prospera digitises this: envelopes hold your categories, the ledger feeds them,
and your budget shows which envelopes still have room. Start with
**Enveloppes → create your first envelope**, then log transactions in
**Ledger**.
