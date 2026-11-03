#!/usr/bin/env sh
# Control-flow tests of the remote parts of deploy-production.sh and
# rollback-production.sh against a fake layout, with shimmed php, composer
# curl and sleep. The php shim models the migrations table ($WORK/ran),
# migrations and maintenance mode (file driver, relative to the release's
# storage); curl answers 503 while maintenance mode is on.
#
# After every scenario the invariant is checked: the live release ships
# exactly the migrations the database ran, or it is in maintenance mode.
#
# The local wrappers run end to end through ssh and scp shims: like OpenSSH,
# the ssh shim joins the command words with spaces for the remote shell.
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
[ "$1" = -r ] && { printf '%s' "${REALPATH_TTL-120}"; exit 0; }
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
        case "${MIGRATE_APPLY:-all}" in
            all) pending >> "$WORK/ran" ;;
            first) pending | head -n 1 >> "$WORK/ran" ;;
        esac
        [ "${MIGRATE_EXIT:-0}" = 0 ] || exit "$MIGRATE_EXIT" ;;
    list) [ -e "$rel/.has-verify" ] && echo 'amounts:verify   Check that every encrypted amount decrypts' ;;
    down) mkdir -p "$rel/storage/framework" && : > "$rel/storage/framework/down" ;;
    up) rm -f "$rel/storage/framework/down" ;;
esac
exit 0
EOF
printf '#!/bin/sh\nexit 0\n' > "$WORK/bin/composer"
cat > "$WORK/bin/curl" <<'EOF'
#!/bin/sh
echo "curl $(basename "$(readlink "$WORK/app/current")")" >> "$WORK/log"
[ -e "$WORK/app/shared/storage/framework/down" ] && exit 22
exit "${HEALTH_EXIT:-0}"
EOF
printf '#!/bin/sh\necho "sleep $1" >> "$WORK/log"\n' > "$WORK/bin/sleep"
cat > "$WORK/bin/ssh" <<'EOF'
#!/bin/sh
while [ $# -gt 0 ]; do
    case "$1" in
        -i|-o) shift 2 ;;
        -*) shift ;;
        *) shift; break ;;
    esac
done
echo "ssh $*" >> "$WORK/ssh-log"
exec $SHELL_UNDER_TEST -c "$*"
EOF
cat > "$WORK/bin/scp" <<'EOF'
#!/bin/sh
for _a in "$@"; do _src=$_dst; _dst=$_a; done
echo "scp $_src" >> "$WORK/ssh-log"
cp "$_src" "${_dst#*:}"
EOF
chmod +x "$WORK/bin/php" "$WORK/bin/composer" "$WORK/bin/curl" "$WORK/bin/sleep" "$WORK/bin/ssh" "$WORK/bin/scp"

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
    rm -rf "$APP" "$WORK/pkg" "$WORK/log" "$WORK/no-table" "$WORK/ssh-log" "$WORK/PWNED"
    DEPLOY_ID=new ROLLBACK_ID='' WAIT='' DEPLOY_ROOT=$APP
    rm -f "$WORK/app-alias"
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

# Line number of the first log line matching $1 (0 when absent).
at() {
    grep -n -- "$1" "$WORK/log" | head -n 1 | cut -d: -f1 | grep . || echo 0
}

# Whether log line $1 comes before (lower, non-zero) log line $2.
before() {
    if [ "$1" -gt 0 ] && [ "$1" -lt "$2" ]; then echo yes; else echo no; fi
}

marker() {
    if [ -e "$APP/shared/deploy-maintenance" ]; then echo marker; else echo none; fi
}

previous() {
    if [ -L "$APP/previous" ]; then basename "$(readlink "$APP/previous")"; else echo none; fi
}

record() {
    if [ -f "$APP/shared/schema-migrations" ]; then sort "$APP/shared/schema-migrations" | tr '\n' ' '; else echo none; fi
}

deploy() {
    package
    _rc=0
    env WORK="$WORK" PATH="$WORK/bin:$PATH" "$@" \
        $SHELL_UNDER_TEST "$WORK/deploy-remote.sh" "$DEPLOY_ROOT" "$DEPLOY_ID" "$WORK/release.tgz" "$WORK/env" 3 "http://localhost/up" "$WAIT" \
        > "$WORK/out" 2>&1 || _rc=$?
    printf 'APP_KEY=x\n' > "$WORK/env"
    echo "$_rc"
}

rollback() {
    _rc=0
    env WORK="$WORK" PATH="$WORK/bin:$PATH" "$@" \
        $SHELL_UNDER_TEST "$WORK/rollback-remote.sh" "$APP" "http://localhost/up" "$ROLLBACK_ID" "$WAIT" \
        > "$WORK/out" 2>&1 || _rc=$?
    echo "$_rc"
}

# The local wrappers, through the ssh and scp shims.
wrapper_env() {
    env WORK="$WORK" PATH="$WORK/bin:$PATH" HOME="$WORK/home" SHELL_UNDER_TEST="$SHELL_UNDER_TEST" \
        SSH_USER=deploy SSH_HOST=example.test SSH_APP_PATH="$APP" SSH_PRIVATE_KEY=key \
        SSH_KNOWN_HOSTS='example.test ssh-ed25519 AAAA' APP_URL=http://localhost "$@"
}

rollback_wrapper() {
    _rc=0
    wrapper_env "$@" $SHELL_UNDER_TEST "$SCRIPTS_DIR/rollback-production.sh" > "$WORK/out" 2>&1 || _rc=$?
    echo "$_rc"
}

deploy_wrapper() {
    package
    mkdir -p "$WORK/cwd/dist"
    _rc=0
    (cd "$WORK/cwd" && wrapper_env RELEASE_ARCHIVE="$WORK/release.tgz" APP_KEY=x DB_HOST=db DB_PORT=5432 \
        DB_DATABASE=app DB_USERNAME=u DB_PASSWORD=p MAIL_HOST=mail MAIL_PORT=465 MAIL_USERNAME=m MAIL_PASSWORD=mp "$@" \
        $SHELL_UNDER_TEST "$SCRIPTS_DIR/deploy-production.sh") > "$WORK/out" 2>&1 || _rc=$?
    echo "$_rc"
}

pwned() {
    if [ -e "$WORK/PWNED" ]; then echo PWNED; else echo safe; fi
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
rc=$(deploy MIGRATE_EXIT=1 MIGRATE_APPLY=none)
rm "$WORK/pkg/database/migrations/b.php"
rc=$(deploy)
expect 'deploy: a fix dropping an unapplied migration is accepted' "$rc $(live) $(maintenance) $(consistent)" '0 new off yes'

# migrate applies b only, then fails: the record holds the database's truth,
# not the release's files.
setup
release "$WORK/pkg" 'ran verify' a b c
rc=$(deploy MIGRATE_EXIT=1 MIGRATE_APPLY=first)
expect 'deploy: a partial migration is recorded as run' "$(record)" 'a b '

setup
: > "$WORK/no-table"
rc=$(deploy)
expect 'deploy: a missing migrations table with a live release is refused' \
    "$rc $(live) $(maintenance) $(ran 'migrate --force')" '1 old off no'

setup
rm "$APP/releases/old/storage"
mkdir -p "$APP/releases/old/storage"
rc=$(deploy)
expect 'deploy: a live release outside the shared storage blocks migrations' \
    "$rc $(live) $(ran 'down') $(ran 'migrate --force')" '1 old no no'

setup
release "$APP/releases/new" '' a
ln -s "$APP/shared/storage" "$APP/releases/new/storage"
ln -sfn "$APP/releases/new" "$APP/current"
rc=$(deploy)
expect 'deploy: the live release is never rebuilt in place' \
    "$rc $(live) $(ls "$APP/releases/new/database/migrations" | tr '\n' ' ')$(ran 'migrations:ran')" '1 new a.php no'

setup
release "$APP/releases/new" '' a
ln -sfn "$APP/releases/new" "$APP/previous"
rc=$(deploy)
expect 'deploy: the previous release is never rebuilt in place' \
    "$rc $(live) $(ls "$APP/releases/new/database/migrations" | tr '\n' ' ')" '1 old a.php '

# A failed deploy's marker left behind after a manual "php artisan up", then
# an operator's maintenance mode.
setup
printf 'a\nb\n' > "$WORK/ran"
: > "$APP/shared/deploy-maintenance"
rc=$(deploy)
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
mv "$APP/releases/new" "$APP/releases/new2" && ln -sfn "$APP/releases/new2" "$APP/current"
rm -f "$APP/previous"
rc=$(deploy)
expect "deploy: a stale marker never lifts an operator's maintenance" "$rc $(maintenance)" '0 on'

setup
printf 'a\nb\n' > "$WORK/ran"
rc=$(deploy HEALTH_EXIT=22)
expect 'deploy: a failed health check never reverts to a mismatched release' \
    "$rc $(live) $(maintenance) $(consistent)" '1 new on yes'

# The previous deploy left its (schema-matching but unhealthy) release in
# maintenance: a failing fix keeps the maintenance instead of reverting.
setup
rc=$(deploy HEALTH_EXIT=22)
mv "$APP/releases/new" "$APP/releases/new2" && ln -sfn "$APP/releases/new2" "$APP/current"
rm -f "$APP/previous"
rc=$(deploy HEALTH_EXIT=22)
expect "deploy: a failed deploy's maintenance is kept when the fix fails too" "$rc $(live) $(maintenance)" '1 new on'

# --- PHP's realpath cache: wait before lifting maintenance or probing ------

setup
rc=$(deploy)
expect 'deploy: waits realpath_cache_ttl + 5s, then lifts maintenance, then probes the new release' \
    "$rc $(grep -c '^sleep 125$' "$WORK/log") $(before "$(at '^sleep')" "$(at ' up$')") $(before "$(at ' up$')" "$(at '^curl new')")" '0 1 yes yes'

setup
rm "$WORK/pkg/database/migrations/b.php"
rc=$(deploy REALPATH_TTL=30)
expect 'deploy: without migrations it still waits before the health check' \
    "$rc $(grep -c '^sleep 35$' "$WORK/log") $(before "$(at '^sleep')" "$(at '^curl new')")" '0 1 yes'

setup
rc=$(deploy REALPATH_TTL=99999999999999999999)
expect 'deploy: an oversized realpath_cache_ttl is capped' "$rc $(grep -c '^sleep 10004$' "$WORK/log")" '0 1'

setup_rollback_ttl() {
    setup
    release "$APP/releases/new" 'ran verify' a b
    ln -s "$APP/shared/storage" "$APP/releases/new/storage"
    ln -sfn "$APP/releases/new" "$APP/current"
    ln -sfn "$APP/releases/old" "$APP/previous"
    release "$APP/releases/old" '' a b
    printf 'a\nb\n' > "$WORK/ran"
}

setup_rollback_ttl
rc=$(rollback REALPATH_TTL=99999999999999999999)
expect 'rollback: an oversized realpath_cache_ttl is capped' "$rc $(live) $(grep -c '^sleep 10004$' "$WORK/log")" '0 old 1'

setup
rc=$(deploy REALPATH_TTL=abc)
expect 'deploy: an unreadable realpath_cache_ttl waits the PHP default' "$rc $(grep -c '^sleep 125$' "$WORK/log")" '0 1'

setup
WAIT=7
rc=$(deploy)
expect 'deploy: DEPLOY_SWITCH_WAIT overrides the wait' "$rc $(grep -c '^sleep' "$WORK/log") $(grep -c '^sleep 7$' "$WORK/log")" '0 1 1'

setup
WAIT=0
rc=$(deploy)
expect 'deploy: DEPLOY_SWITCH_WAIT=0 disables the wait' "$rc $(grep -c '^sleep' "$WORK/log")" '0 0'

setup
WAIT=1x
rc=$(deploy)
expect 'deploy: an invalid DEPLOY_SWITCH_WAIT is refused before anything' \
    "$rc $(live) $(ran 'down') $([ -d "$APP/releases/new" ] && echo built || echo none)" '1 old no none'

setup
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
rc=$(deploy)
expect "deploy: an operator's maintenance mode skips the wait and the health check" \
    "$rc $(grep -c '^sleep' "$WORK/log") $(grep -c '^curl' "$WORK/log")" '0 0 0'

# --- maintenance marker ----------------------------------------------------

setup
rc=$(deploy FAIL=down)
expect 'deploy: a failing "down" leaves no marker and migrates nothing' \
    "$rc $(live) $(maintenance) $(marker) $(ran 'migrate --force')" '1 old off none no'

setup
rc=$(deploy FAIL=amounts:verify)
expect 'deploy: the marker is written after "down"' \
    "$(marker) $([ -z "$(find "$APP/shared/storage/framework/down" -newer "$APP/shared/deploy-maintenance")" ] && echo after || echo before)" 'marker after'

# Lifted by hand, then an operator's "down": the down file is newer than the
# marker.
setup
printf 'a\nb\n' > "$WORK/ran"
mkdir -p "$APP/shared/storage/framework"
: > "$APP/shared/deploy-maintenance"
touch -t 202001010000 "$APP/shared/deploy-maintenance"
: > "$APP/shared/storage/framework/down"
rc=$(deploy)
expect "deploy: a marker older than the maintenance mode never lifts it" \
    "$rc $(live) $(maintenance) $(ran ' up') $(marker)" '0 new on no none'

setup
printf 'a\nb\n' > "$WORK/ran"
mkdir -p "$APP/shared/storage/framework"
: > "$APP/shared/storage/framework/down"
touch -t 202001010000 "$APP/shared/storage/framework/down"
: > "$APP/shared/deploy-maintenance"
rc=$(deploy)
expect "deploy: a marker matching the maintenance mode is taken over and lifted" \
    "$rc $(live) $(maintenance) $(marker)" '0 new off none'

# --- release ids -----------------------------------------------------------

for _id in ../releases/old new/../old . .. ''; do
    setup
    DEPLOY_ID=$_id
    rc=$(deploy)
    expect "deploy: an invalid release id [$_id] is refused" \
        "$rc $(live) $(ls "$APP/releases/old/database/migrations" | tr '\n' ' ')$(ls "$APP/releases" | tr '\n' ' ')" '1 old a.php old '
done

setup
ln -s old "$APP/releases/alias"
DEPLOY_ID=alias
rc=$(deploy)
expect 'deploy: a release id aliasing the live release is refused' \
    "$rc $(live) $(ls "$APP/releases/old/database/migrations" | tr '\n' ' ')" '1 old a.php '

# --- previous link and .env ------------------------------------------------

setup
release "$APP/releases/prev0" '' a
ln -s "$APP/releases/prev0" "$APP/previous"
rm "$WORK/pkg/database/migrations/b.php"
rc=$(deploy HEALTH_EXIT=22)
expect 'deploy: a reverted deploy restores the previous link' "$rc $(live) $(previous)" '1 old prev0'

setup
rm "$WORK/pkg/database/migrations/b.php"
rc=$(deploy HEALTH_EXIT=22)
expect 'deploy: a reverted deploy without a previous link leaves none' "$rc $(live) $(previous)" '1 old none'

setup
rc=$(deploy)
expect 'deploy: a successful deploy points previous at the replaced release' "$rc $(live) $(previous)" '0 new old'

setup
printf 'APP_KEY=old\n' > "$APP/shared/.env"
rc=$(deploy DB_DOWN=1)
expect 'deploy: a refused release leaves the shared .env and links its own to it' \
    "$rc $(cat "$APP/shared/.env") $(readlink "$APP/releases/new/.env" | sed "s|^$APP/||")" '1 APP_KEY=old shared/.env'

setup
printf 'APP_KEY=old\n' > "$APP/shared/.env"
rc=$(deploy)
expect 'deploy: a deploy installs the uploaded .env as the shared one' \
    "$rc $(cat "$APP/shared/.env") $(readlink "$APP/releases/new/.env" | sed "s|^$APP/||")" '0 APP_KEY=x shared/.env'

# Pruning keeps the KEEP_RELEASES newest, plus the current and previous.
setup
touch -t 201901010000 "$APP/releases/old"
_y=2020
for _r in r1 r2 r3 r4; do
    release "$APP/releases/$_r" '' a
    touch -t "${_y}01010000" "$APP/releases/$_r"
    _y=$((_y + 1))
done
mkdir -p "$WORK/keep" && : > "$WORK/keep/file"
rc=$(deploy)
expect 'deploy: pruning keeps the newest releases, the current and the previous' \
    "$rc $(ls "$APP/releases" | tr '\n' ' ')$(ls "$WORK/keep")" '0 new old r3 r4 file'

# The live release's link was written with another spelling of the path
# (e.g. by an earlier deploy): it is still kept, as the previous release.
setup
rm "$APP/current"
ln -s "$WORK//app/releases/./old" "$APP/current"
touch -t 201901010000 "$APP/releases/old"
_y=2020
for _r in r1 r2 r3 r4; do
    release "$APP/releases/$_r" '' a
    touch -t "${_y}01010000" "$APP/releases/$_r"
    _y=$((_y + 1))
done
rc=$(deploy)
expect 'deploy: pruning compares releases resolved, whatever the link spelling' \
    "$rc $(ls "$APP/releases" | tr '\n' ' ')$(previous)" '0 new old r3 r4 old'

# The deploy root is reached through a symlink (e.g. /home -> /usr/home).
setup
ln -s "$APP" "$WORK/app-alias"
DEPLOY_ROOT=$WORK/app-alias
touch -t 201901010000 "$APP/releases/old"
_y=2020
for _r in r1 r2 r3 r4; do
    release "$APP/releases/$_r" '' a
    touch -t "${_y}01010000" "$APP/releases/$_r"
    _y=$((_y + 1))
done
rc=$(deploy)
expect 'deploy: pruning keeps the previous release through a symlinked deploy root' \
    "$rc $(ls "$APP/releases" | tr '\n' ' ')$(previous)" '0 new old r3 r4 old'

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

setup_legacy
printf 'APP_KEY=legacy\n' > "$WORK/legacy-env"
ln -s "$WORK/legacy-env" "$APP/.env"
rc=$(deploy)
_legacy=$(ls -d "$APP"/releases/legacy-*)
expect 'deploy: a legacy .env symlink is kept as is, not moved' \
    "$rc $(readlink "$_legacy/.env" | sed "s|^$WORK/||") $(cat "$WORK/legacy-env") $(cat "$APP/shared/.env")" '0 legacy-env APP_KEY=legacy APP_KEY=x'

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

setup_rollback
rm "$APP/releases/new/.has-ran"
: > "$APP/releases/old/.has-ran"
printf 'a\n' > "$WORK/ran"
rc=$(rollback)
expect "rollback: the target's migrations:ran is used when the live release lacks it" "$rc $(live) $(consistent)" '0 old yes'

setup_rollback
rm "$APP/releases/new/.has-ran"
printf 'a\n' > "$APP/shared/schema-migrations"
printf 'a\n' > "$WORK/ran"
rc=$(rollback)
expect 'rollback: the record is used when no release has migrations:ran' "$rc $(live) $(consistent)" '0 old yes'

setup_rollback
rm "$APP/releases/new/.has-ran"
release "$APP/releases/old" '' a b
rc=$(rollback)
expect 'rollback: without any knowledge of the schema it refuses' "$rc $(live)" '1 new'

# A stale record (a b) while the database ran a only: the target ships a b,
# matching the record, but has a pending migration.
setup_rollback
rm "$APP/releases/new/.has-ran"
release "$APP/releases/old" '' a b
printf 'a\nb\n' > "$APP/shared/schema-migrations"
printf 'a\n' > "$WORK/ran"
rc=$(rollback)
expect 'rollback: pending target migrations are refused despite the record' "$rc $(live)" '1 new'

setup_rollback
release "$APP/releases/old" verify a b
rc=$(rollback FAIL=list)
expect 'rollback: a target that cannot list its commands is refused' "$rc $(live)" '1 new'

setup_rollback
release "$APP/releases/old" '' a b
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
: > "$APP/shared/deploy-maintenance"
rc=$(rollback)
expect 'rollback: lifting a failed deploy'"'"'s maintenance clears its marker' \
    "$rc $([ -e "$APP/shared/deploy-maintenance" ] && echo marker || echo none)" '0 none'

setup_rollback
release "$APP/releases/old" '' a b
: > "$APP/shared/deploy-maintenance"
rc=$(rollback)
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
ln -sfn "$APP/releases/new" "$APP/current"
ln -sfn "$APP/releases/old" "$APP/previous"
rc=$(rollback)
expect "rollback: a stale marker never lifts an operator's maintenance" "$rc $(live) $(maintenance)" '0 old on'

setup_rollback
ROLLBACK_ID=new
rc=$(rollback)
expect 'rollback: the live release is refused as target' "$rc $(live) $(previous) $(grep -c 'is the live release' "$WORK/out")" '1 new old 1'

setup_rollback
ln -s new "$APP/releases/alias"
ROLLBACK_ID=alias
rc=$(rollback)
expect 'rollback: an alias of the live release is refused as target' "$rc $(live) $(previous)" '1 new old'

for _id in ../releases/old new/../old . ..; do
    setup_rollback
    release "$APP/releases/old" '' a b
    ROLLBACK_ID=$_id
    rc=$(rollback)
    expect "rollback: an invalid release id [$_id] is refused" "$rc $(live)" '1 new'
done

setup_rollback
release "$APP/releases/old" '' a b c
rc=$(rollback)
expect 'rollback: a target shipping migrations the database did not run is refused' \
    "$rc $(live) $(grep -c 'does not ship exactly' "$WORK/out")" '1 new 1'

setup_rollback
rc=$(rollback)
expect 'rollback: a target missing a migration the database ran is refused' \
    "$rc $(live) $(grep -c 'does not ship exactly' "$WORK/out")" '1 new 1'

setup_rollback
release "$APP/releases/old" '' a b
rc=$(rollback HEALTH_EXIT=22)
expect 'rollback: a failed health check restores the previous link' "$rc $(live) $(previous)" '1 new old'

setup_rollback
release "$APP/releases/old" '' a b
rc=$(rollback)
expect 'rollback: waits, then probes the target' \
    "$rc $(previous) $(grep -c '^sleep 125$' "$WORK/log") $(before "$(at '^sleep')" "$(at '^curl old')")" '0 new 1 yes'

setup_rollback
release "$APP/releases/old" '' a b
mkdir -p "$APP/shared/storage/framework" && : > "$APP/shared/storage/framework/down"
: > "$APP/shared/deploy-maintenance"
WAIT=9
rc=$(rollback)
expect "rollback: waits before lifting a failed deploy's maintenance, then probes" \
    "$rc $(grep -c '^sleep 9$' "$WORK/log") $(before "$(at '^sleep')" "$(at ' up$')") $(before "$(at ' up$')" "$(at '^curl old')")" '0 1 yes yes'

setup_rollback
release "$APP/releases/old" '' a b
WAIT=x
rc=$(rollback)
expect 'rollback: an invalid DEPLOY_SWITCH_WAIT is refused' "$rc $(live)" '1 new'

setup_rollback
release "$APP/releases/old" '' a b
mkdir -p "$APP/shared/storage/framework"
: > "$APP/shared/deploy-maintenance"
touch -t 202001010000 "$APP/shared/deploy-maintenance"
: > "$APP/shared/storage/framework/down"
rc=$(rollback)
expect "rollback: a marker older than the maintenance mode never lifts it" \
    "$rc $(live) $(maintenance) $(ran ' up') $(marker) $(grep -c '^sleep' "$WORK/log")" '0 old on no none 0'

# --- local wrappers (ssh joins its arguments for the remote shell) --------

setup_rollback
release "$APP/releases/old" '' a b
rc=$(rollback_wrapper ROLLBACK_RELEASE='' DEPLOY_SWITCH_WAIT=3)
expect 'rollback wrapper: an empty release id rolls back to the previous release' \
    "$rc $(live) $(previous) $(grep -c '^sleep 3$' "$WORK/log")" '0 old new 1'

setup_rollback
release "$APP/releases/old" '' a b
rc=$(rollback_wrapper ROLLBACK_RELEASE=old)
expect 'rollback wrapper: a named release, default wait' "$rc $(live) $(grep -c '^sleep 125$' "$WORK/log")" '0 old 1'

setup_rollback
release "$APP/releases/old" '' a b
rc=$(rollback_wrapper ROLLBACK_RELEASE='old;touch${IFS}'"$WORK/PWNED"';')
expect 'rollback wrapper: an invalid release id is refused before ssh' \
    "$rc $(live) $(pwned) $([ -e "$WORK/ssh-log" ] && echo ssh || echo no-ssh)" '1 new safe no-ssh'

for _payload in ';touch${IFS}'"$WORK/PWNED"';' "';touch \${IFS}$WORK/PWNED;'"; do
    setup_rollback
    release "$APP/releases/old" '' a b
    rc=$(rollback_wrapper SSH_APP_PATH="$APP$_payload")
    expect "rollback wrapper: arguments are never parsed by the remote shell [$_payload]" "$rc $(live) $(pwned)" '1 new safe'
done

for _path in "$WORK/app with space" app "$WORK/x/../app" "$WORK/./app" "$APP/.." "$APP/" "/$APP" "$WORK//app" /; do
    setup_rollback
    release "$APP/releases/old" '' a b
    rc=$(rollback_wrapper SSH_APP_PATH="$_path")
    expect "rollback wrapper: an invalid app path [$_path] is refused before ssh" \
        "$rc $(live) $([ -e "$WORK/ssh-log" ] && echo ssh || echo no-ssh)" '1 new no-ssh'
done

setup_rollback
release "$APP/releases/old" '' a b
_rc=0
for _arg in "$WORK/app with space|0" "app|0" "$APP/|0" "$WORK//app|0" "$APP|10000" "$APP|x"; do
    _rc=0
    (cd "$WORK" && env WORK="$WORK" PATH="$WORK/bin:$PATH" $SHELL_UNDER_TEST "$WORK/rollback-remote.sh" "${_arg%|*}" "" "" "${_arg#*|}") \
        > "$WORK/out" 2>&1 || _rc=$?
    expect "rollback: an invalid app path or wait [$_arg] is refused on the host too" \
        "$_rc $(live) $(grep -c 'invalid app path\|must be a number' "$WORK/out")" '1 new 1'
done

setup
DEPLOY_E2E_ID=e2e-$$
rc=$(deploy_wrapper RELEASE_ID="$DEPLOY_E2E_ID" DEPLOY_SWITCH_WAIT=2)
expect 'deploy wrapper: deploys through ssh and scp' \
    "$rc $(live) $(maintenance) $(grep -c '^sleep 2$' "$WORK/log") $(grep -c '^APP_KEY=x$' "$APP/shared/.env") $([ -e "/tmp/prospera-$DEPLOY_E2E_ID.env" ] && echo left || echo cleaned)" \
    "0 $DEPLOY_E2E_ID off 1 1 cleaned"

for _id in 'v1;touch${IFS}'"$WORK/PWNED"';' ../releases/old . ..; do
    setup
    rc=$(deploy_wrapper RELEASE_ID="$_id")
    expect "deploy wrapper: an invalid release id [$_id] is refused before scp and ssh" \
        "$rc $(live) $(pwned) $([ -e "$WORK/ssh-log" ] && echo ssh || echo no-ssh)" '1 old safe no-ssh'
done

for _path in "$WORK/app with space" app "$WORK/x/../app" "$APP/" "$WORK//app"; do
    setup
    rc=$(deploy_wrapper RELEASE_ID=e2e-$$ SSH_APP_PATH="$_path")
    expect "deploy wrapper: an invalid app path [$_path] is refused before scp and ssh" \
        "$rc $(live) $([ -e "$WORK/ssh-log" ] && echo ssh || echo no-ssh)" '1 old no-ssh'
done

for _keep in x '' -1 '3 4' 99999 99999999999999999999; do
    setup
    rc=$(deploy_wrapper RELEASE_ID=e2e-$$ KEEP_RELEASES="$_keep")
    expect "deploy wrapper: an invalid KEEP_RELEASES [$_keep] is refused before scp and ssh" \
        "$rc $(live) $([ -e "$WORK/ssh-log" ] && echo ssh || echo no-ssh)" "$([ -z "$_keep" ] && echo "0 e2e-$$ ssh" || echo '1 old no-ssh')"
done

for _arg in "$WORK/app with space|3|0" "$APP/|3|0" "$WORK//app|3|0" "$APP|x|0" "$APP|99999999999999999999|0" "$APP|3|10000" "app|3|0"; do
    setup
    package
    cp "$WORK/release.tgz" "$WORK/upload.tgz"
    _rest=${_arg#*|}
    _rc=0
    (cd "$WORK" && env WORK="$WORK" PATH="$WORK/bin:$PATH" $SHELL_UNDER_TEST "$WORK/deploy-remote.sh" "${_arg%%|*}" new "$WORK/upload.tgz" "$WORK/env" "${_rest%|*}" "" "${_rest#*|}") \
        > "$WORK/out" 2>&1 || _rc=$?
    expect "deploy: an invalid app path, KEEP_RELEASES or wait [$_arg] is refused on the host, uploads removed" \
        "$_rc $(live) $([ -d "$APP/releases/new" ] && echo built || echo none) $([ -e "$WORK/upload.tgz" ] || [ -e "$WORK/env" ] && echo left || echo removed) $(grep -c 'invalid app path\|must be a number' "$WORK/out")" '1 old none removed 1'
    printf 'APP_KEY=x\n' > "$WORK/env"
done

for _wait in 10000 99999999999999999999 1x; do
    setup
    rc=$(deploy_wrapper RELEASE_ID=e2e-$$ DEPLOY_SWITCH_WAIT="$_wait")
    expect "deploy wrapper: an invalid DEPLOY_SWITCH_WAIT [$_wait] is refused before scp and ssh" \
        "$rc $(live) $([ -e "$WORK/ssh-log" ] && echo ssh || echo no-ssh)" '1 old no-ssh'

    setup_rollback
    release "$APP/releases/old" '' a b
    rc=$(rollback_wrapper DEPLOY_SWITCH_WAIT="$_wait")
    expect "rollback wrapper: an invalid DEPLOY_SWITCH_WAIT [$_wait] is refused before ssh" \
        "$rc $(live) $([ -e "$WORK/ssh-log" ] && echo ssh || echo no-ssh)" '1 new no-ssh'
done

setup
rc=$(deploy_wrapper RELEASE_ID=e2e-$$ DEPLOY_SWITCH_WAIT=9999)
expect 'deploy wrapper: a 4-digit DEPLOY_SWITCH_WAIT is accepted' "$rc $(grep -c '^sleep 9999$' "$WORK/log")" '0 1'

setup
mkdir -p "$WORK/cwd/dist"
printf 'old\n' > "$WORK/cwd/dist/.env.production"
chmod 644 "$WORK/cwd/dist/.env.production"
rc=$(deploy_wrapper RELEASE_ID=e2e-$$)
expect 'deploy wrapper: the rendered .env is owner-only' "$rc $(ls -l "$WORK/cwd/dist/.env.production" | cut -c1-10)" '0 -rw-------'

setup_rollback
release "$APP/releases/old" '' a b
rc=$(rollback_wrapper ROLLBACK_RELEASE=../releases/old)
expect 'rollback wrapper: a path as release id is refused before ssh' \
    "$rc $(live) $([ -e "$WORK/ssh-log" ] && echo ssh || echo no-ssh)" '1 new no-ssh'

for _payload in ';touch${IFS}'"$WORK/PWNED"';' "';touch \${IFS}$WORK/PWNED;'"; do
    setup
    rc=$(deploy_wrapper RELEASE_ID=e2e-$$ DEPLOY_HEALTHCHECK_URL="http://localhost/up$_payload")
    expect "deploy wrapper: arguments are never parsed by the remote shell [$_payload]" "$rc $(live) $(pwned)" "0 e2e-$$ safe"
done

if [ "$FAILURES" -ne 0 ]; then
    printf '%s deploy script test(s) failed\n' "$FAILURES" >&2
    exit 1
fi

printf 'All deploy script tests passed (%s)\n' "$SHELL_UNDER_TEST"
