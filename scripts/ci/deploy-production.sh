#!/usr/bin/env sh

set -eu

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
. "$SCRIPT_DIR/common.sh"

require_env SSH_USER SSH_HOST SSH_APP_PATH APP_KEY APP_URL DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD

prepare_ssh

RELEASE_ID=$(release_id)

if ! valid_release_id "$RELEASE_ID"; then
    printf 'Refusing to deploy: invalid release id (A-Za-z0-9._- only): %s\n' "$RELEASE_ID" >&2
    exit 1
fi

RELEASE_ARCHIVE_PATH=${RELEASE_ARCHIVE:-dist/release.tar.gz}
REMOTE_ARCHIVE_PATH="/tmp/prospera-$RELEASE_ID.tar.gz"
REMOTE_ENV_PATH="/tmp/prospera-$RELEASE_ID.env"
LOCAL_ENV_PATH='dist/.env.production'
KEEP_RELEASES=${KEEP_RELEASES:-3}
HEALTHCHECK_URL=${DEPLOY_HEALTHCHECK_URL:-$APP_URL/up}
SWITCH_WAIT=${DEPLOY_SWITCH_WAIT:-}

test -f "$RELEASE_ARCHIVE_PATH" || {
    printf 'Missing release archive: %s\n' "$RELEASE_ARCHIVE_PATH" >&2
    exit 1
}

sh "$SCRIPT_DIR/render-production-env.sh" > "$LOCAL_ENV_PATH"

scp_to_remote "$RELEASE_ARCHIVE_PATH" "$SSH_USER@$SSH_HOST:$REMOTE_ARCHIVE_PATH"
scp_to_remote "$LOCAL_ENV_PATH" "$SSH_USER@$SSH_HOST:$REMOTE_ENV_PATH"

ssh_remote_script "$SSH_APP_PATH" "$RELEASE_ID" "$REMOTE_ARCHIVE_PATH" "$REMOTE_ENV_PATH" "$KEEP_RELEASES" "$HEALTHCHECK_URL" "$SWITCH_WAIT" <<'REMOTE'
set -eu

APP_ROOT=$1
RELEASE_ID=$2
ARCHIVE_PATH=$3
ENV_UPLOAD_PATH=$4
KEEP_RELEASES=$5
HEALTHCHECK_URL=${6:-}
SWITCH_WAIT=${7:-}

RELEASES_DIR=$APP_ROOT/releases
SHARED_DIR=$APP_ROOT/shared
CURRENT_LINK=$APP_ROOT/current
PREVIOUS_LINK=$APP_ROOT/previous
RELEASE_DIR=$RELEASES_DIR/$RELEASE_ID
CURRENT_TARGET=''
# Production migrations are forward-only: DB::prohibitDestructiveCommands()
# refuses migrate:rollback/reset there. A release whose migrations ran must
# therefore never be swapped back for the previous release (which would run
# on a schema it does not know — e.g. plaintext writes into encrypted amount
# columns, docs/encryption.md): failures after migrating leave the app in
# maintenance mode for a fix-forward deploy.
#
# Releases are compared with the migrations the database ran
# (`php artisan migrations:ran`): a release that does not ship every one of
# them is never deployed. SCHEMA_RECORD keeps that list as of the last deploy
# for rollbacks; DEPLOY_DOWN marks maintenance turned on by a deploy, which a
# later fully verified deploy may lift (an operator's maintenance is never
# lifted).
SCHEMA_RECORD=$APP_ROOT/shared/schema-migrations
DEPLOY_DOWN=$APP_ROOT/shared/deploy-maintenance
DOWN_FILE=$SHARED_DIR/storage/framework/down
PREVIOUS_TARGET=''
MIGRATED=0
MAINTENANCE=0
WAS_DOWN=0
TOOK_OVER=0
PENDING=0
ENV_PENDING=0

safe_symlink() {
    _target=$1
    _link=$2

    if [ -d "$_link" ] && [ ! -L "$_link" ]; then
        rm -rf "$_link"
    fi

    _tmp="${_link}.deploying.$$"
    ln -sfn "$_target" "$_tmp"
    mv -fT "$_tmp" "$_link" 2>/dev/null || { rm -f "$_link"; mv "$_tmp" "$_link"; }
}

cleanup() {
    rm -f "$ARCHIVE_PATH" "$ENV_UPLOAD_PATH"
}

# Migration names (file names without .php) shipped by release $1.
migration_names() {
    for _file in "$1"/database/migrations/*.php; do
        if [ -e "$_file" ]; then
            basename "$_file" .php
        fi
    done
}

# Lines of file $1 that file $2 does not contain.
lines_missing_from() {
    while IFS= read -r _line; do
        if [ -n "$_line" ] && ! grep -qxF -- "$_line" "$2"; then
            printf '%s\n' "$_line"
        fi
    done < "$1"

    return 0
}

# Record the migrations the database ran; falls back to the release's (a
# superset, since a deploy never runs on a database ahead of its release).
record_schema() {
    if ! (cd "$RELEASE_DIR" && php artisan migrations:ran > "$SCHEMA_RECORD.tmp" 2>/dev/null); then
        migration_names "$RELEASE_DIR" > "$SCHEMA_RECORD.tmp"
    fi

    mv -f "$SCHEMA_RECORD.tmp" "$SCHEMA_RECORD"
}

# Maintenance mode lives in the shared storage (file driver), so it covers
# every release sharing it. An operator's maintenance mode is left untouched.
maintenance_down() {
    if [ "$MAINTENANCE" = 0 ] && [ "$WAS_DOWN" = 0 ]; then
        (cd "$RELEASE_DIR" && php artisan down --retry=15)
        : > "$DEPLOY_DOWN"
        MAINTENANCE=1
    fi
}

# Whether the maintenance mode on is a deploy's: its marker is written right
# after "php artisan down", and any later down (an operator's, after lifting
# it by hand) rewrites the down file, making it newer than the marker.
deploy_maintenance() {
    [ -e "$DOWN_FILE" ] && [ -e "$DEPLOY_DOWN" ] || return 1
    _newer=$(find "$DOWN_FILE" -newer "$DEPLOY_DOWN") || return 1
    [ -z "$_newer" ]
}

# PHP caches resolved paths (realpath_cache_ttl, per worker process): a
# worker may keep resolving "current" to the release it replaced for that
# long. Wait it out before lifting maintenance mode or probing the health.
settle_switch() {
    if [ -z "$SWITCH_WAIT" ]; then
        SWITCH_WAIT=$(php -r 'echo (int) ini_get("realpath_cache_ttl");' 2>/dev/null || true)
        case "$SWITCH_WAIT" in
            ''|*[!0-9]*) SWITCH_WAIT=120 ;;
        esac
        SWITCH_WAIT=$((SWITCH_WAIT + 5))
    fi

    if [ "$SWITCH_WAIT" != 0 ]; then
        printf 'Waiting %ss for PHP workers to see the new release\n' "$SWITCH_WAIT" >&2
        sleep "$SWITCH_WAIT"
    fi
}

restore_previous() {
    if [ -n "$PREVIOUS_TARGET" ]; then
        safe_symlink "$PREVIOUS_TARGET" "$PREVIOUS_LINK"
    else
        rm -f "$PREVIOUS_LINK"
    fi
}

# Whether release $1 ships exactly the migrations the database ran.
matches_schema() {
    migration_names "$1" > "$RELEASE_DIR/.migrations-other"

    [ -z "$(lines_missing_from "$RELEASE_DIR/.migrations-ran" "$RELEASE_DIR/.migrations-other")" ] \
        && [ -z "$(lines_missing_from "$RELEASE_DIR/.migrations-other" "$RELEASE_DIR/.migrations-ran")" ]
}

maintenance_up() {
    (cd "$RELEASE_DIR" && php artisan up)
    rm -f "$DEPLOY_DOWN"
    MAINTENANCE=0
}

on_exit() {
    status=$?

    if [ "$status" -ne 0 ] && [ "$MIGRATED" = 1 ]; then
        record_schema || true
    fi

    if [ "$status" -ne 0 ] && [ -e "$DOWN_FILE" ]; then
        printf 'Deployment failed: the app stays in maintenance mode (deploy a fix; see docs/encryption.md)\n' >&2
    fi

    # A refused release never received the uploaded .env: link it to the
    # shared one like any release (its link to the upload would dangle).
    if [ "$ENV_PENDING" = 1 ]; then
        ln -sfn "$SHARED_DIR/.env" "$RELEASE_DIR/.env" || true
    fi

    cleanup
    exit "$status"
}

rollback_current() {
    if [ -z "$CURRENT_TARGET" ]; then
        rm -f "$CURRENT_LINK"
    elif [ "$MIGRATED" = 1 ] || [ "$TOOK_OVER" = 1 ] || ! matches_schema "$CURRENT_TARGET"; then
        # The previous release cannot run on this schema.
        maintenance_down
        printf 'Health check failed: keeping the new release in maintenance mode\n' >&2
    else
        safe_symlink "$CURRENT_TARGET" "$CURRENT_LINK"
        restore_previous
        printf 'Deployment health check failed; reverted current release\n' >&2
    fi
}

run_healthcheck() {
    if [ -z "$HEALTHCHECK_URL" ]; then
        return 0
    fi

    # errexit is ignored inside `if ! run_healthcheck`: return the probe's
    # own status.
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$HEALTHCHECK_URL" >/dev/null
        return
    fi

    if command -v wget >/dev/null 2>&1; then
        wget -q -O /dev/null "$HEALTHCHECK_URL"
        return
    fi

    printf 'Health check requested but curl or wget is unavailable on the target host\n' >&2
    return 1
}

prune_old_releases() {
    count=0

    for path in $(ls -1dt "$RELEASES_DIR"/* 2>/dev/null || true); do
        count=$((count + 1))

        if [ "$count" -le "$KEEP_RELEASES" ]; then
            continue
        fi

        if [ -L "$CURRENT_LINK" ] && [ "$path" = "$(readlink "$CURRENT_LINK")" ]; then
            continue
        fi

        if [ -L "$PREVIOUS_LINK" ] && [ "$path" = "$(readlink "$PREVIOUS_LINK")" ]; then
            continue
        fi

        rm -rf "$path"
    done
}

migrate_legacy_layout() {
    if [ -L "$CURRENT_LINK" ]; then
        return 0
    fi

    if [ ! -f "$APP_ROOT/artisan" ]; then
        return 0
    fi

    legacy_release=$RELEASES_DIR/legacy-$(date +%Y%m%d%H%M%S)
    mkdir -p "$legacy_release"

    for item in "$APP_ROOT"/* "$APP_ROOT"/.[!.]* "$APP_ROOT"/..?*; do
        [ -e "$item" ] || continue

        base=$(basename "$item")

        case "$base" in
            .|..|current|previous|releases|shared)
                continue
                ;;
        esac

        mv "$item" "$legacy_release/"
    done

    if [ -d "$APP_ROOT/public" ] || [ -L "$APP_ROOT/public" ]; then
        mv "$APP_ROOT/public" "$legacy_release/public"
    fi

    # Share the legacy storage and .env like any release: maintenance mode
    # (file driver) then covers the legacy release, and a rollback to it
    # rebuilds its configuration from the current .env.
    if [ ! -e "$SHARED_DIR/storage" ] && [ -d "$legacy_release/storage" ] && [ ! -L "$legacy_release/storage" ]; then
        mv "$legacy_release/storage" "$SHARED_DIR/storage"
        ln -s "$SHARED_DIR/storage" "$legacy_release/storage"
    fi

    if [ ! -e "$SHARED_DIR/.env" ] && [ -f "$legacy_release/.env" ] && [ ! -L "$legacy_release/.env" ]; then
        mv "$legacy_release/.env" "$SHARED_DIR/.env"
        ln -s "$SHARED_DIR/.env" "$legacy_release/.env"
    fi

    safe_symlink "$legacy_release" "$CURRENT_LINK"
}

trap on_exit EXIT
trap 'exit 1' HUP INT TERM

case "$SWITCH_WAIT" in
    *[!0-9]*)
        printf 'DEPLOY_SWITCH_WAIT must be a number of seconds: %s\n' "$SWITCH_WAIT" >&2
        exit 1
        ;;
esac

# A release id is one directory name under releases/ (no path).
case "$RELEASE_ID" in
    ''|.|..|*[!A-Za-z0-9._-]*)
        printf 'Refusing to deploy: invalid release id: %s\n' "$RELEASE_ID" >&2
        exit 1
        ;;
esac

mkdir -p "$APP_ROOT" "$RELEASES_DIR" "$SHARED_DIR"

migrate_legacy_layout

if [ -L "$CURRENT_LINK" ]; then
    CURRENT_TARGET=$(readlink "$CURRENT_LINK")
fi

if [ -L "$PREVIOUS_LINK" ]; then
    PREVIOUS_TARGET=$(readlink "$PREVIOUS_LINK")
fi

# Never rebuild the live or the previous release in place: their code would
# change under them (or the rollback target would be lost) before any check.
if [ -d "$RELEASE_DIR" ]; then
    _release=$(cd "$RELEASE_DIR" && pwd -P)

    for _keep in "$CURRENT_TARGET" "$PREVIOUS_TARGET"; do
        if [ -n "$_keep" ] && [ "$_release" = "$(cd "$_keep" 2>/dev/null && pwd -P)" ]; then
            printf 'Refusing to deploy: release %s is the live or the previous release; deploy under a new release id\n' "$RELEASE_ID" >&2
            exit 1
        fi
    done
fi

# Maintenance mode already on: a failed deploy's (DEPLOY_DOWN) is taken over
# and lifted once this release is verified; an operator's is never lifted. A
# marker not matching the maintenance mode on is stale (lifted by hand).
if deploy_maintenance; then
    MAINTENANCE=1
    TOOK_OVER=1
else
    rm -f "$DEPLOY_DOWN"

    if [ -e "$DOWN_FILE" ]; then
        WAS_DOWN=1
    fi
fi

rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR"
tar -xzf "$ARCHIVE_PATH" -C "$RELEASE_DIR"

chmod 600 "$ENV_UPLOAD_PATH"

if [ ! -d "$SHARED_DIR/storage" ] && [ -d "$RELEASE_DIR/storage" ]; then
    mv "$RELEASE_DIR/storage" "$SHARED_DIR/storage"
fi

mkdir -p "$SHARED_DIR/storage/app" "$SHARED_DIR/storage/framework" "$SHARED_DIR/storage/logs"
mkdir -p "$RELEASE_DIR/bootstrap/cache"

rm -rf "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
# The uploaded .env replaces the shared one (used by every release) only
# once this release passed its refusal checks.
ln -sfn "$ENV_UPLOAD_PATH" "$RELEASE_DIR/.env"
ENV_PENDING=1

chmod -R ug+rwX "$SHARED_DIR/storage" "$RELEASE_DIR/bootstrap/cache" || true

cd "$RELEASE_DIR"

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Compare the release with the migrations the database ran: refuse a release
# that does not ship all of them (the database is ahead of it).
# migrations:ran exits 2 without a migrations table (first install).
migration_names "$RELEASE_DIR" > "$RELEASE_DIR/.migrations-shipped"

if php artisan migrations:ran > "$RELEASE_DIR/.migrations-ran" 2>/dev/null; then
    _unknown=$(lines_missing_from "$RELEASE_DIR/.migrations-ran" "$RELEASE_DIR/.migrations-shipped")

    if [ -n "$_unknown" ]; then
        printf 'Refusing to deploy: the database ran migrations this release does not ship:\n%s\n' "$_unknown" >&2
        exit 1
    fi

    if [ -n "$(lines_missing_from "$RELEASE_DIR/.migrations-shipped" "$RELEASE_DIR/.migrations-ran")" ]; then
        PENDING=1
    fi
else
    _status=$?

    if [ "$_status" -eq 2 ] && [ -z "$CURRENT_TARGET" ]; then
        PENDING=1
    else
        printf 'Refusing to deploy: cannot read the migrations the database ran (exit %s)\n' "$_status" >&2
        exit 1
    fi
fi

# Maintenance mode only protects a live release sharing the storage.
if [ "$PENDING" = 1 ] && [ -n "$CURRENT_TARGET" ] \
    && [ "$(cd "$CURRENT_TARGET/storage" 2>/dev/null && pwd -P)" != "$(cd "$SHARED_DIR/storage" && pwd -P)" ]; then
    printf 'Refusing to migrate: the live release does not use the shared storage, maintenance mode would not cover it\n' >&2
    exit 1
fi

mv "$ENV_UPLOAD_PATH" "$SHARED_DIR/.env"
ln -sfn "$SHARED_DIR/.env" "$RELEASE_DIR/.env"
ENV_PENDING=0

# Migrate first: optimize:clear flushes the database cache store, whose table
# does not exist yet on a fresh database. Pending migrations run in
# maintenance mode so the previous release stops serving requests before the
# schema changes under it.
if [ "$PENDING" = 1 ]; then
    if [ -n "$CURRENT_TARGET" ]; then
        maintenance_down
    fi

    MIGRATED=1
    php artisan migrate --force
fi

record_schema
# Encryption at rest (docs/encryption.md): every stored amount must decrypt
# before the release goes live.
php artisan amounts:verify
php artisan optimize:clear
rm -rf public/storage
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

find "$RELEASE_DIR" -type d -exec chmod 755 {} +

if [ -n "$CURRENT_TARGET" ]; then
    safe_symlink "$CURRENT_TARGET" "$PREVIOUS_LINK"
fi

safe_symlink "$RELEASE_DIR" "$CURRENT_LINK"

if [ "$WAS_DOWN" = 1 ]; then
    printf 'Maintenance mode was on before this deploy: left on and health check skipped — lift it ("php artisan up") once the live release is verified to match the schema, and PHP workers had time to see it (realpath_cache_ttl, see docs/encryption.md)\n' >&2
else
    settle_switch

    if [ "$MAINTENANCE" = 1 ]; then
        maintenance_up
    fi

    if ! run_healthcheck; then
        rollback_current
        exit 1
    fi
fi

prune_old_releases
REMOTE
