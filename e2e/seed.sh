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
exec php artisan tinker --env=e2e "e2e/$1"
