#!/usr/bin/env sh

set -eu

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
. "$SCRIPT_DIR/common.sh"

require_env SSH_USER SSH_HOST SSH_APP_PATH APP_KEY APP_URL DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD

prepare_ssh

RELEASE_ID=$(release_id)
RELEASE_ARCHIVE_PATH=${RELEASE_ARCHIVE:-dist/release.tar.gz}
REMOTE_ARCHIVE_PATH="/tmp/prospera-$RELEASE_ID.tar.gz"
REMOTE_ENV_PATH="/tmp/prospera-$RELEASE_ID.env"
LOCAL_ENV_PATH='dist/.env.production'
KEEP_RELEASES=${KEEP_RELEASES:-3}
HEALTHCHECK_URL=${DEPLOY_HEALTHCHECK_URL:-$APP_URL/up}

test -f "$RELEASE_ARCHIVE_PATH" || {
    printf 'Missing release archive: %s\n' "$RELEASE_ARCHIVE_PATH" >&2
    exit 1
}

sh "$SCRIPT_DIR/render-production-env.sh" > "$LOCAL_ENV_PATH"

scp_to_remote "$RELEASE_ARCHIVE_PATH" "$SSH_USER@$SSH_HOST:$REMOTE_ARCHIVE_PATH"
scp_to_remote "$LOCAL_ENV_PATH" "$SSH_USER@$SSH_HOST:$REMOTE_ENV_PATH"

ssh_remote sh -s -- "$SSH_APP_PATH" "$RELEASE_ID" "$REMOTE_ARCHIVE_PATH" "$REMOTE_ENV_PATH" "$KEEP_RELEASES" "$HEALTHCHECK_URL" <<'REMOTE'
set -eu

APP_ROOT=$1
RELEASE_ID=$2
ARCHIVE_PATH=$3
ENV_UPLOAD_PATH=$4
KEEP_RELEASES=$5
HEALTHCHECK_URL=$6

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
MIGRATED=0
MAINTENANCE=0
WAS_DOWN=0
TOOK_OVER=0
PENDING=0

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
        : > "$DEPLOY_DOWN"
        (cd "$RELEASE_DIR" && php artisan down --retry=15)
        MAINTENANCE=1
    fi
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

    if [ "$status" -ne 0 ] && [ -e "$SHARED_DIR/storage/framework/down" ]; then
        printf 'Deployment failed: the app stays in maintenance mode (deploy a fix; see docs/encryption.md)\n' >&2
    fi

    cleanup
    exit "$status"
}

rollback_current() {
    if [ -z "$CURRENT_TARGET" ]; then
        rm -f "$CURRENT_LINK"
    elif [ "$MIGRATED" = 1 ] || [ "$TOOK_OVER" = 1 ]; then
        # The previous release cannot run on this schema.
        maintenance_down
        printf 'Health check failed: keeping the new release in maintenance mode\n' >&2
    else
        safe_symlink "$CURRENT_TARGET" "$CURRENT_LINK"
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

    # Share the legacy storage so maintenance mode (file driver) covers the
    # legacy release too.
    if [ ! -e "$SHARED_DIR/storage" ] && [ -d "$legacy_release/storage" ] && [ ! -L "$legacy_release/storage" ]; then
        mv "$legacy_release/storage" "$SHARED_DIR/storage"
        ln -s "$SHARED_DIR/storage" "$legacy_release/storage"
    fi

    safe_symlink "$legacy_release" "$CURRENT_LINK"
}

trap on_exit EXIT
trap 'exit 1' HUP INT TERM

mkdir -p "$APP_ROOT" "$RELEASES_DIR" "$SHARED_DIR"

migrate_legacy_layout

if [ -L "$CURRENT_LINK" ]; then
    CURRENT_TARGET=$(readlink "$CURRENT_LINK")
fi

# Maintenance mode already on: a failed deploy's (DEPLOY_DOWN) is taken over
# and lifted once this release is verified; an operator's is never lifted.
if [ -e "$SHARED_DIR/storage/framework/down" ]; then
    if [ -e "$DEPLOY_DOWN" ]; then
        MAINTENANCE=1
        TOOK_OVER=1
    else
        WAS_DOWN=1
    fi
fi

rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR"
tar -xzf "$ARCHIVE_PATH" -C "$RELEASE_DIR"

mv "$ENV_UPLOAD_PATH" "$SHARED_DIR/.env"
chmod 600 "$SHARED_DIR/.env"

if [ ! -d "$SHARED_DIR/storage" ] && [ -d "$RELEASE_DIR/storage" ]; then
    mv "$RELEASE_DIR/storage" "$SHARED_DIR/storage"
fi

mkdir -p "$SHARED_DIR/storage/app" "$SHARED_DIR/storage/framework" "$SHARED_DIR/storage/logs"
mkdir -p "$RELEASE_DIR/bootstrap/cache"

rm -rf "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/.env" "$RELEASE_DIR/.env"

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
    printf 'Maintenance mode was on before this deploy: left on and health check skipped — lift it ("php artisan up") once the live release is verified to match the schema\n' >&2
else
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
