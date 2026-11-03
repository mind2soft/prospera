#!/usr/bin/env sh
# Control-flow tests of the remote parts of deploy-production.sh and
# rollback-production.sh against a fake layout, with shimmed php, composer
# and curl. The php shim models the migrations table ($WORK/ran), migrations
# and maintenance mode (file driver, relative to the release's storage).
#
# After every scenario the invariant is checked: the live release ships
# exactly the migrations the database ran, or it is in maintenance mode.
#
# Usage: sh scripts/ci/tests/deploy-scripts.test.sh [shell]   (default: sh)

set -eu

SCRIPTS_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
SHELL_UNDER_TEST=${1:-sh}
WORK=$(mktemp -d)
FAILURES=0

trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/bin"

# Release features: .has-ran (migrations:ran exists), .has-verify
# (amounts:verify exists). $WORK/no-table: no migrations table yet.
cat > "$WORK/bin/php" <<'EOF'
#!/bin/sh
rel=$(pwd -P)
printf '%s %s\n' "$(basename "$rel")" "$*" >> "$WORK/log"
names() { for f in "$rel"/database/migrations/*.php; do [ -e "$f" ] && basename "$f" .php; done; }
pending() { names | while IFS= read -r n; do grep -qxF -- "$n" "$WORK/ran" || echo "$n"; done; }
for f in ${FAIL:-}; do [ "$f" = "$2" ] && exit 1; done
case "$2" in
    migrations:ran)
        [ -e "$rel/.has-ran" ] || exit 1
        [ "${DB_DOWN:-}" = 1 ] && exit 1
        [ -e "$WORK/no-table" ] && exit 2
        cat "$WORK/ran" ;;
    migrate:status)
        [ -e "$WORK/no-table" ] && exit 1
        if [ -n "$(pending)" ]; then
            [ "$3" = "--pending=3" ] && exit 3
        fi ;;
    migrate)
        rm -f "$WORK/no-table"; touch "$WORK/ran"
        [ "${MIGRATE_APPLY:-1}" = 1 ] && pending > "$WORK/pending" && cat "$WORK/pending" >> "$WORK/ran"
        [ "${MIGRATE_EXIT:-0}" = 0 ] || exit "$MIGRATE_EXIT" ;;
    help) [ "$3" = amounts:verify ] && [ ! -e "$rel/.has-verify" ] && exit 1 ;;
    down) mkdir -p "$rel/storage/framework" && : > "$rel/storage/framework/down" ;;
    up) rm -f "$rel/storage/framework/down" ;;
esac
exit 0
EOF
printf '#!/bin/sh\nexit 0\n' > "$WORK/bin/composer"
printf '#!/bin/sh\nexit "${HEALTH_EXIT:-0}"\n' > "$WORK/bin/curl"
chmod +x "$WORK/bin/php" "$WORK/bin/composer" "$WORK/bin/curl"

extract_remote() {
    awk "/<<'REMOTE'/{f=1;next} /^REMOTE\$/{f=0} f" "$1"
}

extract_remote "$SCRIPTS_DIR/deploy-production.sh" > "$WORK/deploy-remote.sh"
extract_remote "$SCRIPTS_DIR/rollback-production.sh" > "$WORK/rollback-remote.sh"

APP=$WORK/app

# release <dir> <features: ran verify> <migrations...>
release() {
    _dir=$1 _features=$2
    shift 2
    mkdir -p "$_dir/database/migrations"
    for _m in "$@"; do : > "$_dir/database/migrations/$_m.php"; done
    case "$_features" in *ran*) : > "$_dir/.has-ran" ;; esac
    case "$_features" in *verify*) : > "$_dir/.has-verify" ;; esac
}

# Live "old" release (pre-feature: no migrations:ran) on a database that ran
# migration a; the package to deploy ships a + b.
setup() {
    rm -rf "$APP" "$WORK/pkg" "$WORK/log" "$WORK/no-table"
    mkdir -p "$APP/shared/storage"
    release "$APP/releases/old" '' a
    ln -s "$APP/shared/storage" "$APP/releases/old/storage"
    ln -s "$APP/releases/old" "$APP/current"
    release "$WORK/pkg" 'ran verify' a b
    printf 'a\n' > "$WORK/ran"
    : > "$WORK/log"
    printf 'APP_KEY=x\n' > "$WORK/env"
}

package() {
    tar -czf "$WORK/release.tgz" -C "$WORK/pkg" .
}

live() {
    if [ -L "$APP/current" ]; then basename "$(readlink "$APP/current")" | sed 's/^legacy-.*/legacy/'; else echo none; fi
}

maintenance() {
    if [ -e "$APP/current/storage/framework/down" ]; then echo on; else echo off; fi
}

# The live release matches the schema, or is in maintenance mode.
consistent() {
    [ -L "$APP/current" ] || { echo yes; return; }
    _shipped=$(for f in "$APP"/current/database/migrations/*.php; do [ -e "$f" ] && basename "$f" .php; done | sort | tr '\n' ' ')
    _ran=$(sort "$WORK/ran" 2>/dev/null | tr '\n' ' ')
    if [ "$_shipped" = "$_ran" ] || [ "$(maintenance)" = on ]; then echo yes; else echo NO; fi
}

ran() {
    if grep -q -- " $1" "$WORK/log"; then echo yes; else echo no; fi
}

record() {
    if [ -f "$APP/shared/schema-migrations" ]; then sort "$APP/shared/schema-migrations" | tr '\n' ' '; else echo none; fi
}

deploy() {
    package
    _rc=0
    env WORK="$WORK" PATH="$WORK/bin:$PATH" "$@" \
        $SHELL_UNDER_TEST "$WORK/deploy-remote.sh" "$APP" new "$WORK/release.tgz" "$WORK/env" 3 "http://localhost/up" \
        > "$WORK/out" 2>&1 || _rc=$?
    printf 'APP_KEY=x\n' > "$WORK/env"
    echo "$_rc"
}

rollback() {
    _rc=0
    env WORK="$WORK" PATH="$WORK/bin:$PATH" "$@" \
        $SHELL_UNDER_TEST "$WORK/rollback-remote.sh" "$APP" "http://localhost/up" "" \
        > "$WORK/out" 2>&1 || _rc=$?
    echo "$_rc"
}

# expect <name> <actual> <expected>
expect() {
    if [ "$2" = "$3" ]; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s: got [%s], expected [%s]\n' "$1" "$2" "$3"
        sed 's/^/     | /' "$WORK/out" "$WORK/log"
        FAILURES=$((FAILURES + 1))
    fi
}

# --- deploy-production.sh -------------------------------------------------

setup
rc=$(deploy)
expect 'deploy: pending migrations run in maintenance, then go live' \
    "$rc $(live) $(maintenance) $(ran 'down') $(ran 'up') $(record) $(consistent)" '0 new off yes yes a b  yes'

setup
rc=$(deploy FAIL=amounts:verify)
expect 'deploy: a failure after migrating stays in maintenance' \
    "$rc $(live) $(maintenance) $(record) $(consistent)" '1 old on a b  yes'

setup
rc=$(deploy MIGRATE_EXIT=1)
expect 'deploy: a failed migration stays in maintenance' "$rc $(live) $(maintenance) $(consistent)" '1 old on yes'

# migrate applies c, then fails: the record holds what the database ran.
setup
rm -f "$WORK/pkg/database/migrations/b.php"
release "$WORK/pkg" 'ran verify' a c
rc=$(deploy MIGRATE_EXIT=1)
expect 'deploy: the record holds the migrations the database ran' "$(record)" 'a c '

setup
rc=$(deploy HEALTH_EXIT=22)
expect 'deploy: a failed health check after migrating keeps the new release in maintenance' \
    "$rc $(live) $(maintenance) $(consistent)" '1 new on yes'

setup
rm "$WORK/pkg/database/migrations/b.php"
rc=$(deploy HEALTH_EXIT=22)
expect 'deploy: a failed health check without migrations reverts' \
    "$rc $(live) $(maintenance) $(ran 'down') $(consistent)" '1 old off no yes'

setup
rc=$(deploy DB_DOWN=1)
expect 'deploy: an unreadable migrations table is refused before maintenance' \
    "$rc $(live) $(maintenance) $(ran 'migrate --force') $(consistent)" '1 old off no yes'

setup
rm "$APP/current"
: > "$WORK/no-table"
rc=$(deploy)
expect 'deploy: a first install migrates without maintenance' \
    "$rc $(live) $(maintenance) $(ran 'down') $(record) $(consistent)" '0 new off no a b  yes'

setup
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
rc=$(deploy)
expect "deploy: an operator's maintenance mode is left on and untouched" \
    "$rc $(live) $(maintenance) $(ran 'down') $(ran 'up') $(consistent)" '0 new on no no yes'

setup
rc=$(deploy FAIL=amounts:verify)
rc=$(deploy)
expect "deploy: a fix-forward deploy lifts a failed deploy's maintenance" \
    "$rc $(live) $(maintenance) $([ -e "$APP/shared/deploy-maintenance" ] && echo marker || echo none) $(consistent)" '0 new off none yes'

setup
rc=$(deploy FAIL=amounts:verify)
rc=$(deploy HEALTH_EXIT=22)
expect "deploy: a taken-over maintenance survives a failed health check" \
    "$rc $(live) $(maintenance) $(ran 'migrate --force') $(consistent)" '1 new on yes yes'

setup
printf 'a\nb\nc\n' > "$WORK/ran"
rc=$(deploy)
expect 'deploy: a release missing migrations the database ran is refused' \
    "$rc $(live) $(maintenance) $(ran 'migrate --force')" '1 old off no'

setup
rc=$(deploy MIGRATE_EXIT=1 MIGRATE_APPLY=0)
rm "$WORK/pkg/database/migrations/b.php"
rc=$(deploy)
expect 'deploy: a fix dropping an unapplied migration is accepted' "$rc $(live) $(maintenance) $(consistent)" '0 new off yes'

# Legacy layout: the app lives directly in APP_ROOT.
setup_legacy() {
    setup
    rm -rf "$APP/current" "$APP/releases" "$APP/shared"
    release "$APP" '' a
    : > "$APP/artisan"
    mkdir -p "$APP/storage/framework"
}

setup_legacy
rc=$(deploy FAIL=amounts:verify)
expect 'deploy: maintenance mode covers a legacy-layout release' \
    "$rc $(live) $(maintenance) $(consistent)" '1 legacy on yes'

setup_legacy
rc=$(deploy)
expect 'deploy: a legacy layout is migrated to releases' "$rc $(live) $(maintenance) $(consistent)" '0 new off yes'

# --- rollback-production.sh -----------------------------------------------

# current = new (a b, with migrations:ran), previous = old (a).
setup_rollback() {
    setup
    release "$APP/releases/new" 'ran verify' a b
    ln -s "$APP/shared/storage" "$APP/releases/new/storage"
    ln -sfn "$APP/releases/new" "$APP/current"
    ln -sfn "$APP/releases/old" "$APP/previous"
    printf 'a\nb\n' > "$WORK/ran"
}

setup_rollback
rc=$(rollback)
expect 'rollback: a target missing migrations the database ran is refused' "$rc $(live) $(consistent)" '1 new yes'

setup_rollback
rm "$APP/releases/new/database/migrations/b.php"
printf 'a\n' > "$WORK/ran"
rc=$(rollback)
expect 'rollback: a target matching the schema goes live' "$rc $(live) $(ran 'config:cache') $(consistent)" '0 old yes yes'

setup_rollback
printf 'a\nb\nx\n' > "$WORK/ran"
release "$APP/releases/old" '' a b
rc=$(rollback)
expect 'rollback: migrations run outside the workflow are seen' "$rc $(live)" '1 new'

setup_rollback
rm "$APP/releases/new/.has-ran"
printf 'a\nb\n' > "$APP/shared/schema-migrations"
rc=$(rollback)
expect 'rollback: without migrations:ran, the record is used' "$rc $(live) $(consistent)" '1 new yes'

setup_rollback
rm "$APP/releases/new/.has-ran"
release "$APP/releases/old" '' a b
printf 'a\n' > "$WORK/ran"
rc=$(rollback)
expect 'rollback: pending target migrations are refused' "$rc $(live)" '1 new'

setup_rollback
release "$APP/releases/old" verify a b
rc=$(rollback FAIL=amounts:verify)
expect 'rollback: undecryptable amounts are refused' "$rc $(live)" '1 new'

setup_rollback
release "$APP/releases/old" '' a b
rc=$(rollback HEALTH_EXIT=22)
expect 'rollback: a failed health check restores the current release' "$rc $(live) $(consistent)" '1 new yes'

setup_rollback
release "$APP/releases/old" '' a b
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
rc=$(rollback)
expect "rollback: an operator's maintenance mode is left on" "$rc $(live) $(maintenance)" '0 old on'

setup_rollback
release "$APP/releases/old" '' a b
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
: > "$APP/shared/deploy-maintenance"
rc=$(rollback)
expect "rollback: a failed deploy's maintenance is lifted on a matching release" "$rc $(live) $(maintenance) $(consistent)" '0 old off yes'

setup_rollback
release "$APP/releases/old" '' a b
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
: > "$APP/shared/deploy-maintenance"
rc=$(rollback HEALTH_EXIT=22)
expect "rollback: a failed health check restores a failed deploy's maintenance" "$rc $(live) $(maintenance)" '1 new on'

if [ "$FAILURES" -ne 0 ]; then
    printf '%s deploy script test(s) failed\n' "$FAILURES" >&2
    exit 1
fi

printf 'All deploy script tests passed (%s)\n' "$SHELL_UNDER_TEST"
