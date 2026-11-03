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
# MIGRATED: this deploy ran a migration batch. MAINTENANCE: this deploy put
# the app in maintenance mode. SWITCHED: the new release is live.
MIGRATED=0
MAINTENANCE=0
SWITCHED=0
NEW_MIGRATIONS=''

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

# Migration files shipped by release $1 that release $2 does not have.
new_migrations() {
    for _file in "$1"/database/migrations/*.php; do
        [ -e "$_file" ] || continue
        _name=$(basename "$_file")
        [ -e "$2/database/migrations/$_name" ] || printf '%s\n' "$_name"
    done
}

# Roll back this release's new migrations — and only them: --path limits the
# rollback to these files, so an older batch is never undone even when none
# of them ran.
rollback_new_migrations() {
    _count=0
    _paths=''

    for _name in $NEW_MIGRATIONS; do
        _count=$((_count + 1))
        _paths="$_paths --path=database/migrations/$_name"
    done

    if [ "$_count" -eq 0 ]; then
        return 0
    fi

    # shellcheck disable=SC2086 # migration file names contain no spaces
    (cd "$RELEASE_DIR" && php artisan migrate:rollback --force --step="$_count" $_paths)
}

# Maintenance mode lives in the shared storage, so it covers both releases.
maintenance_down() {
    (cd "$RELEASE_DIR" && php artisan down --retry=15)
    MAINTENANCE=1
}

maintenance_up() {
    if [ "$MAINTENANCE" = 1 ]; then
        (cd "$RELEASE_DIR" && php artisan up) || printf 'Leaving maintenance mode failed\n' >&2
        MAINTENANCE=0
    fi
}

# A failure after migrating but before the switch leaves the previous release
# live: undo this release's new migrations so the schema matches it, then
# leave maintenance mode. A failed rollback keeps the app in
# maintenance — serving either release on a mismatched schema would corrupt
# data (docs/encryption.md).
on_exit() {
    status=$?

    if [ "$status" -ne 0 ] && [ "$SWITCHED" = 0 ] && [ "$MIGRATED" = 1 ] && [ -n "$CURRENT_TARGET" ]; then
        if rollback_new_migrations; then
            MIGRATED=0
        else
            printf 'Migration rollback failed; the app stays in maintenance mode\n' >&2
            MAINTENANCE=0
        fi
    fi

    if [ "$status" -ne 0 ] || [ "$SWITCHED" = 1 ]; then
        maintenance_up
    fi

    cleanup
    exit "$status"
}

rollback_current() {
    if [ -n "$CURRENT_TARGET" ]; then
        cd "$RELEASE_DIR"

        if [ "$MIGRATED" = 1 ]; then
            maintenance_down

            if ! rollback_new_migrations; then
                printf 'Migration rollback failed; keeping the new release in maintenance mode\n' >&2
                MAINTENANCE=0
                return 1
            fi

            MIGRATED=0
        fi

        safe_symlink "$CURRENT_TARGET" "$CURRENT_LINK"
        SWITCHED=0
        maintenance_up
    else
        rm -f "$CURRENT_LINK"
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

    safe_symlink "$legacy_release" "$CURRENT_LINK"
}

trap on_exit EXIT
trap 'exit 1' HUP INT TERM

mkdir -p "$APP_ROOT" "$RELEASES_DIR" "$SHARED_DIR"

migrate_legacy_layout

if [ -L "$CURRENT_LINK" ]; then
    CURRENT_TARGET=$(readlink "$CURRENT_LINK")
fi

rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR"
tar -xzf "$ARCHIVE_PATH" -C "$RELEASE_DIR"

if [ -n "$CURRENT_TARGET" ]; then
    NEW_MIGRATIONS=$(new_migrations "$RELEASE_DIR" "$CURRENT_TARGET")
fi

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
# Migrate first: optimize:clear flushes the database cache store, whose table
# does not exist yet on a fresh database. While migrating, the previous
# release would serve requests against the new schema (e.g. plaintext writes
# into encrypted amount columns): pending migrations run in maintenance mode.
if ! php artisan migrate:status --pending=1 >/dev/null 2>&1; then
    if [ -n "$CURRENT_TARGET" ]; then
        maintenance_down
    fi

    MIGRATED=1
    php artisan migrate --force
fi
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
SWITCHED=1
maintenance_up

if ! run_healthcheck; then
    rollback_current
    printf 'Deployment health check failed; reverted current release\n' >&2
    exit 1
fi

prune_old_releases
REMOTE
