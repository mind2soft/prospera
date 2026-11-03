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
# refuses migrate:rollback/reset there. Only switch to a release shipping
# exactly the migrations the database ran — read live with
# `php artisan migrations:ran` (from the current or the target release),
# else from shared/schema-migrations (recorded by the last deploy), else the
# live release's — otherwise it would read or write data it does not
# understand (e.g. plaintext amounts into encrypted columns,
# docs/encryption.md). Deploy a fix instead.
SCHEMA_RECORD=$APP_ROOT/shared/schema-migrations
DEPLOY_DOWN=$APP_ROOT/shared/deploy-maintenance
DOWN_FILE=$APP_ROOT/shared/storage/framework/down
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

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

if (cd "$CURRENT_TARGET" && php artisan migrations:ran > "$TMP_DIR/ran" 2>/dev/null) \
    || (cd "$TARGET_RELEASE" && php artisan migrations:ran > "$TMP_DIR/ran" 2>/dev/null); then
    :
elif [ -f "$SCHEMA_RECORD" ]; then
    cp "$SCHEMA_RECORD" "$TMP_DIR/ran"
else
    migration_names "$CURRENT_TARGET" > "$TMP_DIR/ran"
fi

migration_names "$TARGET_RELEASE" > "$TMP_DIR/shipped"

if [ -n "$(lines_missing_from "$TMP_DIR/ran" "$TMP_DIR/shipped")" ] || [ -n "$(lines_missing_from "$TMP_DIR/shipped" "$TMP_DIR/ran")" ]; then
    printf 'Refusing to roll back: %s does not ship exactly the migrations the database ran (production migrations are forward-only; deploy a fix instead)\n' \
        "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

if ! (cd "$TARGET_RELEASE" && php artisan migrate:status --pending=3 >/dev/null 2>&1); then
    printf 'Refusing to roll back: %s has pending migrations or the migration status is unreadable\n' "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

# The target's cached configuration predates the current .env (e.g. a
# rotated APP_KEY, which unwraps the amount keys): rebuild it, then check
# that every amount decrypts with it.
cd "$TARGET_RELEASE"

if ! php artisan config:cache; then
    printf 'Refusing to roll back: caching the configuration of %s failed\n' "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

if php artisan help amounts:verify >/dev/null 2>&1 && ! php artisan amounts:verify; then
    printf 'Refusing to roll back: amounts do not all decrypt with %s\n' "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

safe_symlink "$CURRENT_TARGET" "$PREVIOUS_LINK"
safe_symlink "$TARGET_RELEASE" "$CURRENT_LINK"

# A deploy's maintenance mode (failed deploy) is lifted now that the live
# release matches the schema; an operator's is left on.
LIFTED=0

if [ -e "$DOWN_FILE" ]; then
    if [ ! -e "$DEPLOY_DOWN" ]; then
        printf 'Maintenance mode is on: left on and health check skipped — lift it ("php artisan up") once the live release is verified to match the schema\n' >&2
        exit 0
    fi

    php artisan up
    rm -f "$DEPLOY_DOWN"
    LIFTED=1
fi

if ! run_healthcheck; then
    if [ "$LIFTED" = 1 ]; then
        : > "$DEPLOY_DOWN"
        php artisan down --retry=15
    fi

    safe_symlink "$CURRENT_TARGET" "$CURRENT_LINK"
    printf 'Rollback health check failed; restored previous current release\n' >&2
    exit 1
fi
REMOTE
