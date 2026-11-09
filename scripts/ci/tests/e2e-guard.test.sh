#!/usr/bin/env sh
# Control-flow tests of e2e/guard-e2e-env.sh against a fake project layout.
# The guard enforces the e2e isolation rules: .env.e2e may only point at
# database/e2e.sqlite, and a stale .env.e2e (APP_KEY diverged from .env) is
# re-derived instead of silently running the e2e suite against an old key.
#
# Scenarios:
#   1. missing .env.e2e            → derived, e2e DB target, .env's APP_KEY
#   2. stale .env.e2e (old APP_KEY) → re-derived with .env's APP_KEY
#   3. .env.e2e pointing at the DEV database → refused, file untouched
#   4. up-to-date .env.e2e          → left untouched
#
# Usage: sh scripts/ci/tests/e2e-guard.test.sh [shell]   (default: sh)

set -eu

GUARD=$(CDPATH='' cd -- "$(dirname -- "$0")/../../.." && pwd)/e2e/guard-e2e-env.sh
SHELL_UNDER_TEST=${1:-sh}
WORK=$(mktemp -d)
FAILURES=0

trap 'rm -rf "$WORK"' EXIT

mkdir "$WORK/project"
cd "$WORK/project"

# The guard is sourced (like serve.sh/seed.sh do), under the caller's
# flags — both callers run `set -euo pipefail`, and a refusal must exit.
# The guard path is passed positionally: no quoting traps for exotic paths.
run_guard() {
    "$SHELL_UNDER_TEST" -c 'set -eu; . "$1"' sh "$GUARD"
}

# A .env with an active APP_KEY and the dev DB_DATABASE line commented out,
# exactly like the real template.
cat > .env <<'EOF'
APP_ENV=local
APP_KEY=base64:CURRENTKEYCURRENTKEYCURRENTKEYCURRENTKEY=
DB_CONNECTION=sqlite
# DB_DATABASE=laravel
EOF

expect() {
    name=$1
    shift
    if "$@"; then
        echo "ok - $name"
    else
        echo "NOT ok - $name"
        FAILURES=$((FAILURES + 1))
    fi
}

expect_not() {
    name=$1
    shift
    if "$@"; then
        echo "NOT ok - $name"
        FAILURES=$((FAILURES + 1))
    else
        echo "ok - $name"
    fi
}

# --- 1. missing .env.e2e → derived -----------------------------------------
rm -f .env.e2e
if run_guard; then derived=1; else derived=0; fi
expect "missing file: guard succeeds" test "$derived" -eq 1
expect "missing file: derived with the e2e database" grep -qx 'DB_DATABASE=database/e2e.sqlite' .env.e2e
expect "missing file: derived with the e2e app env" grep -qx 'APP_ENV=e2e' .env.e2e
expect "missing file: derived with .env's APP_KEY" grep -qx 'APP_KEY=base64:CURRENTKEYCURRENTKEYCURRENTKEYCURRENTKEY=' .env.e2e
expect_not "missing file: dev DB_DATABASE line removed" grep -q 'DB_DATABASE=laravel' .env.e2e

# A fully-derived .env.e2e — the expected result of scenarios 1 and 2.
cp .env.e2e .env.e2e.expected

# --- 2. stale .env.e2e (APP_KEY diverged from .env) → re-derived ------------
cat > .env.e2e <<'EOF'
APP_ENV=e2e
APP_KEY=base64:STALEKEYSTALEKEYSTALEKEYSTALEKEYSTALEKEY=
DB_DATABASE=database/e2e.sqlite
EOF
if run_guard 2>notice.log; then derived=1; else derived=0; fi
expect "stale file: guard succeeds" test "$derived" -eq 1
expect "stale file: announces the re-derivation" grep -q 're-deriving' notice.log
expect "stale file: re-derived with .env's APP_KEY" cmp -s .env.e2e .env.e2e.expected

# --- 3. .env.e2e pointing at the DEV database → refused ---------------------
cp .env.e2e.expected .env.e2e.good
cat > .env.e2e <<'EOF'
APP_ENV=e2e
APP_KEY=base64:CURRENTKEYCURRENTKEYCURRENTKEYCURRENTKEY=
DB_DATABASE=database/database.sqlite
EOF
cp .env.e2e .env.e2e.evil
if run_guard 2>refused.log; then refused=0; else refused=1; fi
expect "dev database target: guard refuses" test "$refused" -eq 1
expect "dev database target: explains why" grep -q 'only ever use database/e2e.sqlite' refused.log
expect "dev database target: file left untouched" cmp -s .env.e2e .env.e2e.evil

# --- 4. up-to-date .env.e2e → untouched -------------------------------------
cp .env.e2e.good .env.e2e
cp .env.e2e .env.e2e.before
if run_guard 2>silent.log; then ok=1; else ok=0; fi
expect "up-to-date file: guard succeeds" test "$ok" -eq 1
expect "up-to-date file: silent" test ! -s silent.log
expect "up-to-date file: left untouched" cmp -s .env.e2e .env.e2e.before

# --- missing .env with no APP_KEY → derived anyway (empty key is .env's) ----
printf 'APP_ENV=local\n' > .env
rm -f .env.e2e
if run_guard; then derived=1; else derived=0; fi
expect "no APP_KEY in .env: guard succeeds" test "$derived" -eq 1
expect "no APP_KEY in .env: e2e database target" grep -qx 'DB_DATABASE=database/e2e.sqlite' .env.e2e

# --- 5. duplicate DB_DATABASE lines → refused (Laravel: first one wins) -----
cp .env.e2e.good .env.e2e
cat >> .env.e2e <<'EOF'
export DB_DATABASE=database/database.sqlite
EOF
cp .env.e2e .env.e2e.dup
if run_guard 2>dup.log; then refused=0; else refused=1; fi
expect "duplicate DB_DATABASE: guard refuses" test "$refused" -eq 1
expect "duplicate DB_DATABASE: explains why" grep -q 'duplicate DB_DATABASE' dup.log
expect "duplicate DB_DATABASE: file left untouched" cmp -s .env.e2e .env.e2e.dup

# --- 6. empty .env.e2e → refused --------------------------------------------
: > .env.e2e
if run_guard 2>empty.log; then refused=0; else refused=1; fi
expect "empty file: guard refuses" test "$refused" -eq 1
expect "empty file: explains why" grep -q 'no DB_DATABASE line' empty.log
expect "empty file: file left untouched" test ! -s .env.e2e

# --- 7. missing .env → refused ----------------------------------------------
mv .env .env.hidden
rm -f .env.e2e
if run_guard 2>noenv.log; then refused=0; else refused=1; fi
expect "missing .env: guard refuses" test "$refused" -eq 1
expect "missing .env: explains why" grep -q 'no .env file' noenv.log
expect "missing .env: no .env.e2e created" test ! -e .env.e2e
mv .env.hidden .env

# --- 8. export/indented DB_DATABASE variants in .env are stripped -----------
cat > .env <<'EOF'
APP_ENV=local
APP_KEY=base64:CURRENTKEYCURRENTKEYCURRENTKEYCURRENTKEY=
DB_CONNECTION=sqlite
# DB_DATABASE=laravel
  DB_DATABASE=database/database.sqlite
export DB_DATABASE=database/database.sqlite
EOF
rm -f .env.e2e
if run_guard; then derived=1; else derived=0; fi
expect "dotenv variants: guard succeeds" test "$derived" -eq 1
expect "dotenv variants: only the e2e target remains" test "$(grep -c 'DB_DATABASE=' .env.e2e)" -eq 1
expect "dotenv variants: the e2e target is the dev-free one" grep -qx 'DB_DATABASE=database/e2e.sqlite' .env.e2e

if [ "$FAILURES" -gt 0 ]; then
    echo "1..1"
    echo "not ok - $FAILURES scenario(s) failed"
    exit 1
fi
echo "all guard scenarios passed"
