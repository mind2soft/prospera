#!/usr/bin/env bash
# Bootstraps and boots the app for the Playwright e2e suite, against the
# ISOLATED e2e environment (.env.e2e → database/e2e.sqlite) — never the
# developer's database used by `php artisan serve`.
#
# The isolation rules are enforced by e2e/guard-e2e-env.sh: .env.e2e may
# only ever point at database/e2e.sqlite (a stale or hand-edited file is
# refused), and a stale .env.e2e (APP_KEY diverged from .env) is re-derived.
#
# Refuses to start if port 8000 is already taken (e.g. by a dev
# `php artisan serve`): the suite needs the port for its own server, and
# attaching to a dev-booted server would run the suite against the dev DB.
# Stop the dev server first, then run `npm run e2e`.
set -euo pipefail
cd "$(dirname "$0")/.."

source e2e/guard-e2e-env.sh

# fsockopen succeeds (exit 1) when the port is already taken.
if ! php -r 'exit((int) (bool) @fsockopen("127.0.0.1", 8000, $errno, $errstr, 0.2));'; then
    echo "ERROR: port 8000 is already in use (running 'php artisan serve'?)" >&2
    echo "The e2e suite serves its own isolated environment (.env.e2e) on port 8000." >&2
    echo "Stop the dev server, then run 'npm run e2e' again." >&2
    exit 1
fi

# Rebuild the frontend assets: `php artisan serve` has no Vite dev server
# and serves whatever is in `public/build` — a stale build would run the
# suite against outdated code (see issue #27).
npm run build

# Wipe the database file first: `migrate:fresh` only drops tables — a
# corrupt sqlite file (e.g. left over from a killed server mid-write)
# would fail the boot entirely. The e2e database is disposable.
rm -f database/e2e.sqlite database/e2e.sqlite-journal

php artisan migrate:fresh --env=e2e --force
exec php artisan serve --env=e2e --no-reload
