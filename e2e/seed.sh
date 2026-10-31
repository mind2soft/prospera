#!/usr/bin/env bash
# Runs an e2e seed script against the ISOLATED e2e environment
# (.env.e2e → database/e2e.sqlite) — never the developer's database.
#
# Usage: npm run e2e:seed -- seed-ledger.php
set -euo pipefail
cd "$(dirname "$0")/.."
exec php artisan tinker --env=e2e "e2e/$1"
