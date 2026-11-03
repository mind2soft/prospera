#!/usr/bin/env sh
# Control-flow tests of the remote parts of deploy-production.sh and
# rollback-production.sh, run against a fake release layout with shimmed
# php/composer/curl binaries (no server, no database).
#
# Usage: sh scripts/ci/tests/deploy-scripts.test.sh [shell]   (default: sh)

set -eu

SCRIPTS_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
SHELL_UNDER_TEST=${1:-sh}
WORK=$(mktemp -d)
FAILURES=0

trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/bin"

# php shim: logs "<release dir> <args>", simulates migrate:status exit codes,
# maintenance mode (file driver) and injected failures.
cat > "$WORK/bin/php" <<'EOF'
#!/bin/sh
printf '%s %s\n' "$(basename "$(pwd -P)")" "$*" >> "$WORK/log"
case "$2" in
    migrate:status) exit "${STATUS_EXIT:-0}" ;;
esac
for _fail in ${FAIL:-}; do
    [ "$_fail" = "$2" ] && exit 1
done
case "$2" in
    down) mkdir -p "$WORK/app/shared/storage/framework" && : > "$WORK/app/shared/storage/framework/down" ;;
    up) rm -f "$WORK/app/shared/storage/framework/down" ;;
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

# Layout: releases/old (live, migrations a.php) and a new release shipping
# a.php + b.php; optional schema record and maintenance mode.
setup() {
    rm -rf "$WORK/app" "$WORK/pkg" "$WORK/log"
    mkdir -p "$WORK/app/releases/old/database/migrations" "$WORK/app/shared/storage" "$WORK/pkg/database/migrations"
    : > "$WORK/app/releases/old/database/migrations/a.php"
    : > "$WORK/pkg/database/migrations/a.php"
    : > "$WORK/pkg/database/migrations/b.php"
    : > "$WORK/log"
    ln -s "$WORK/app/releases/old" "$WORK/app/current"
    tar -czf "$WORK/release.tgz" -C "$WORK/pkg" .
    printf 'APP_KEY=x\n' > "$WORK/env"
}

maintenance() {
    if [ -e "$WORK/app/shared/storage/framework/down" ]; then echo on; else echo off; fi
}

live() {
    if [ -L "$WORK/app/current" ]; then basename "$(readlink "$WORK/app/current")"; else echo none; fi
}

deploy() {
    _rc=0
    env WORK="$WORK" PATH="$WORK/bin:$PATH" "$@" \
        $SHELL_UNDER_TEST "$WORK/deploy-remote.sh" "$WORK/app" new "$WORK/release.tgz" "$WORK/env" 3 "http://localhost/up" \
        > "$WORK/out" 2>&1 || _rc=$?
    echo "$_rc"
}

rollback() {
    _rc=0
    env WORK="$WORK" PATH="$WORK/bin:$PATH" "$@" \
        $SHELL_UNDER_TEST "$WORK/rollback-remote.sh" "$WORK/app" "http://localhost/up" "" \
        > "$WORK/out" 2>&1 || _rc=$?
    echo "$_rc"
}

# expect <name> <actual> <expected>
expect() {
    if [ "$2" = "$3" ]; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s: got [%s], expected [%s]\n' "$1" "$2" "$3"
        sed 's/^/     | /' "$WORK/out"
        FAILURES=$((FAILURES + 1))
    fi
}

ran() {
    if grep -q " $1" "$WORK/log"; then echo yes; else echo no; fi
}

# --- deploy-production.sh -------------------------------------------------

setup
rc=$(deploy STATUS_EXIT=3)
expect 'deploy: pending migrations succeed' "$rc $(live) $(maintenance) $(ran 'down') $(ran 'up')" '0 new off yes yes'
expect 'deploy: records the migrated schema' "$(tr '\n' ' ' < "$WORK/app/shared/schema-migrations")" 'a.php b.php '

setup
rc=$(deploy STATUS_EXIT=3 FAIL=amounts:verify)
expect 'deploy: failure after migrating stays in maintenance on the old release' "$rc $(live) $(maintenance)" '1 old on'

setup
rc=$(deploy STATUS_EXIT=3 FAIL=migrate)
expect 'deploy: failed migration stays in maintenance' "$rc $(live) $(maintenance)" '1 old on'

setup
rc=$(deploy STATUS_EXIT=3 HEALTH_EXIT=22)
expect 'deploy: failed health check after migrating keeps the new release in maintenance' "$rc $(live) $(maintenance)" '1 new on'

setup
rc=$(deploy STATUS_EXIT=0 HEALTH_EXIT=22)
expect 'deploy: failed health check without migrations reverts' "$rc $(live) $(maintenance) $(ran 'down')" '1 old off no'

setup
rc=$(deploy STATUS_EXIT=1)
expect 'deploy: unreadable migration status is refused before maintenance' "$rc $(live) $(maintenance) $(ran 'migrate --force')" '1 old off no'

setup
rm "$WORK/app/current"
rc=$(deploy STATUS_EXIT=1)
expect 'deploy: first install migrates without maintenance' "$rc $(live) $(maintenance) $(ran 'migrate --force') $(ran 'down')" '0 new off yes no'

setup
mkdir -p "$WORK/app/shared/storage/framework" && : > "$WORK/app/shared/storage/framework/down"
rc=$(deploy STATUS_EXIT=3)
expect 'deploy: pre-existing maintenance is left on and untouched' "$rc $(live) $(maintenance) $(ran 'down') $(ran 'up')" '0 new on no no'

setup
printf 'a.php\nb.php\nc.php\n' > "$WORK/app/shared/schema-migrations"
rc=$(deploy STATUS_EXIT=0)
expect 'deploy: a release missing applied migrations is refused' "$rc $(live) $(maintenance) $(ran 'migrate --force')" '1 old off no'

# --- rollback-production.sh -----------------------------------------------

# Layout: current = new (a.php), previous = old (a.php).
setup_rollback() {
    setup
    mkdir -p "$WORK/app/releases/new/database/migrations"
    : > "$WORK/app/releases/new/database/migrations/a.php"
    ln -sfn "$WORK/app/releases/new" "$WORK/app/current"
    ln -sfn "$WORK/app/releases/old" "$WORK/app/previous"
}

setup_rollback
printf 'a.php\n' > "$WORK/app/shared/schema-migrations"
rc=$(rollback)
expect 'rollback: same schema switches' "$rc $(live) $(ran 'config:cache')" '0 old yes'

setup_rollback
printf 'a.php\nb.php\n' > "$WORK/app/shared/schema-migrations"
rc=$(rollback)
expect 'rollback: database ahead of the target is refused' "$rc $(live)" '1 new'

setup_rollback
: > "$WORK/app/releases/new/database/migrations/b.php"
rc=$(rollback)
expect 'rollback: without a record, differing migrations are refused' "$rc $(live)" '1 new'

setup_rollback
rc=$(rollback STATUS_EXIT=3)
expect 'rollback: pending target migrations are refused' "$rc $(live)" '1 new'

setup_rollback
rc=$(rollback FAIL=amounts:verify)
expect 'rollback: undecryptable amounts are refused' "$rc $(live)" '1 new'

setup_rollback
rc=$(rollback HEALTH_EXIT=22)
expect 'rollback: failed health check restores the current release' "$rc $(live)" '1 new'

setup_rollback
mkdir -p "$WORK/app/shared/storage/framework" && : > "$WORK/app/shared/storage/framework/down"
rc=$(rollback)
expect 'rollback: maintenance mode is left on' "$rc $(live) $(maintenance)" '0 old on'

if [ "$FAILURES" -ne 0 ]; then
    printf '%s deploy script test(s) failed\n' "$FAILURES" >&2
    exit 1
fi

printf 'All deploy script tests passed (%s)\n' "$SHELL_UNDER_TEST"
