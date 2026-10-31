#!/usr/bin/env bash
# Bootstraps and boots the app for the Playwright e2e suite, against the
# ISOLATED e2e environment (.env.e2e → database/e2e.sqlite) — never the
# developer's database used by `php artisan serve`.
#
# Refuses to start if port 8000 is already taken (e.g. by a dev
# `php artisan serve`): the suite needs the port for its own server, and
# attaching to a dev-booted server would run the suite against the dev DB.
# Stop the dev server first, then run `npm run e2e`.
set -euo pipefail
cd "$(dirname "$0")/.."

# Derive .env.e2e from .env once (same APP_KEY/APP_URL → identical signed
# links and Fortify behaviour), with a dedicated sqlite database.
if [ ! -f .env.e2e ]; then
    sed -e 's/^APP_ENV=.*/APP_ENV=e2e/' \
        -e '/^# DB_DATABASE=/d' \
        -e '/^DB_DATABASE=/d' \
        .env > .env.e2e
    printf '\n# E2E ONLY: dedicated sqlite database for the Playwright suite — never the dev DB.\nDB_DATABASE=database/e2e.sqlite\n' >> .env.e2e
fi

# fsockopen succeeds (exit 1) when the port is already taken.
if ! php -r 'exit((int) (bool) @fsockopen("127.0.0.1", 8000, $errno, $errstr, 0.2));'; then
    echo "ERROR: port 8000 is already in use (running 'php artisan serve'?)" >&2
    echo "The e2e suite serves its own isolated environment (.env.e2e) on port 8000." >&2
    echo "Stop the dev server, then run 'npm run e2e' again." >&2
    exit 1
fi

php artisan migrate:fresh --env=e2e --force
exec php artisan serve --env=e2e --no-reload
