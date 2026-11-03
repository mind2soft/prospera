#!/usr/bin/env sh

set -eu

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
. "$SCRIPT_DIR/common.sh"

require_env SSH_USER SSH_HOST SSH_APP_PATH

APP_URL_VALUE=${APP_URL:-}

prepare_ssh

HEALTHCHECK_URL=${DEPLOY_HEALTHCHECK_URL:-}

if [ -z "$HEALTHCHECK_URL" ] && [ -n "$APP_URL_VALUE" ]; then
    HEALTHCHECK_URL="$APP_URL_VALUE/up"
fi

ROLLBACK_RELEASE=${ROLLBACK_RELEASE:-}

ssh_remote sh -s -- "$SSH_APP_PATH" "$HEALTHCHECK_URL" "$ROLLBACK_RELEASE" <<'REMOTE'
set -eu

APP_ROOT=$1
HEALTHCHECK_URL=$2
ROLLBACK_RELEASE=$3
CURRENT_LINK=$APP_ROOT/current
PREVIOUS_LINK=$APP_ROOT/previous
RELEASES_DIR=$APP_ROOT/releases

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

if [ ! -L "$CURRENT_LINK" ]; then
    printf 'Current release link is missing\n' >&2
    exit 1
fi

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

CURRENT_TARGET=$(readlink "$CURRENT_LINK")

if [ -n "$ROLLBACK_RELEASE" ]; then
    TARGET_RELEASE=$RELEASES_DIR/$ROLLBACK_RELEASE

    if [ ! -d "$TARGET_RELEASE" ]; then
        printf 'Requested rollback release does not exist: %s\n' "$ROLLBACK_RELEASE" >&2
        exit 1
    fi
elif [ -L "$PREVIOUS_LINK" ]; then
    TARGET_RELEASE=$(readlink "$PREVIOUS_LINK")
else
    printf 'Previous release link is missing\n' >&2
    exit 1
fi

# Only the migrations the target release does not ship are rolled back, in
# maintenance mode and before switching: --path limits the rollback to those
# files, so a release without new migrations never undoes an older batch. If
# the rollback fails, the current release stays live (its schema is intact).
NEW_MIGRATIONS=''
NEW_MIGRATION_COUNT=0

for _file in "$CURRENT_TARGET"/database/migrations/*.php; do
    [ -e "$_file" ] || continue
    _name=$(basename "$_file")

    if [ ! -e "$TARGET_RELEASE/database/migrations/$_name" ]; then
        NEW_MIGRATIONS="$NEW_MIGRATIONS --path=database/migrations/$_name"
        NEW_MIGRATION_COUNT=$((NEW_MIGRATION_COUNT + 1))
    fi
done

cd "$CURRENT_TARGET"

if [ "$NEW_MIGRATION_COUNT" -gt 0 ]; then
    php artisan down --retry=15

    # shellcheck disable=SC2086 # migration file names contain no spaces
    if ! php artisan migrate:rollback --force --step="$NEW_MIGRATION_COUNT" $NEW_MIGRATIONS; then
        php artisan up || true
        printf 'Migration rollback failed; staying on the current release\n' >&2
        exit 1
    fi
fi

safe_symlink "$CURRENT_TARGET" "$PREVIOUS_LINK"
safe_symlink "$TARGET_RELEASE" "$CURRENT_LINK"

if [ "$NEW_MIGRATION_COUNT" -gt 0 ]; then
    php artisan up
fi

if ! run_healthcheck; then
    cd "$CURRENT_TARGET"

    if [ "$NEW_MIGRATION_COUNT" -gt 0 ]; then
        php artisan down --retry=15

        if ! php artisan migrate --force; then
            safe_symlink "$CURRENT_TARGET" "$CURRENT_LINK"
            printf 'Migration re-apply failed; the app stays in maintenance mode\n' >&2
            exit 1
        fi
    fi

    safe_symlink "$CURRENT_TARGET" "$CURRENT_LINK"

    if [ "$NEW_MIGRATION_COUNT" -gt 0 ]; then
        php artisan up
    fi

    printf 'Rollback health check failed; restored previous current release\n' >&2
    exit 1
fi
REMOTE
