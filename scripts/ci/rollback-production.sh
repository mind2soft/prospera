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

# Production migrations are forward-only: DB::prohibitDestructiveCommands()
# refuses migrate:rollback/reset there. Only switch to a release that runs on
# the current schema as is — same migration files, none pending — otherwise
# it would read or write data it does not understand (e.g. plaintext amounts
# into encrypted columns, docs/encryption.md). Deploy a fix instead.
migration_names() {
    for _file in "$1"/database/migrations/*.php; do
        if [ -e "$_file" ]; then
            basename "$_file"
        fi
    done
}

if [ "$(migration_names "$CURRENT_TARGET")" != "$(migration_names "$TARGET_RELEASE")" ]; then
    printf 'Refusing to roll back: %s and %s ship different migrations (production migrations are forward-only; deploy a fix instead)\n' \
        "$(basename "$CURRENT_TARGET")" "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

if ! (cd "$TARGET_RELEASE" && php artisan migrate:status --pending=1 >/dev/null 2>&1); then
    printf 'Refusing to roll back: %s has pending migrations\n' "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

safe_symlink "$CURRENT_TARGET" "$PREVIOUS_LINK"
safe_symlink "$TARGET_RELEASE" "$CURRENT_LINK"

if [ -e "$APP_ROOT/shared/storage/framework/down" ]; then
    printf 'Maintenance mode is on: left on and health check skipped — run "php artisan up" once verified\n' >&2
    exit 0
fi

if ! run_healthcheck; then
    safe_symlink "$CURRENT_TARGET" "$CURRENT_LINK"
    printf 'Rollback health check failed; restored previous current release\n' >&2
    exit 1
fi
REMOTE
