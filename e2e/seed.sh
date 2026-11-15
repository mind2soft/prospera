#!/usr/bin/env bash
# Runs an e2e seed script against the ISOLATED e2e environment
# (.env.e2e → database/e2e.sqlite) — never the developer's database.
#
# The isolation rules are enforced by e2e/guard-e2e-env.sh (the e2e
# database must be the only target, stale environments are re-derived).
#
# Usage: npm run e2e:seed -- seed-ledger.php
set -euo pipefail
cd "$(dirname "$0")/.."
source e2e/guard-e2e-env.sh

# Tinker exits 0 even when the seed script throws, so the "seeded …" line
# every seed script ends with is the real success signal: a seed aborted
# mid-way (e.g. a foreign key a previous seed left behind) must fail the
# spec's seeding step loudly, not surface later as missing page content.
output=$(php artisan tinker --env=e2e "e2e/$1" 2>&1) || {
    printf '%s\n' "$output" >&2
    exit 1
}
if ! grep -q '^seeded ' <<<"$output"; then
    printf 'ERROR: the seed script did not complete (tinker exits 0 even on an exception):\n%s\n' "$output" >&2
    exit 1
fi
printf '%s\n' "$output"
