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
SWITCH_WAIT=${DEPLOY_SWITCH_WAIT:-}

if ! valid_app_path "$SSH_APP_PATH"; then
    printf 'Refusing to roll back: invalid SSH_APP_PATH (absolute, A-Za-z0-9._/- only): %s\n' "$SSH_APP_PATH" >&2
    exit 1
fi

case "$SWITCH_WAIT" in
    *[!0-9]*|?????*)
        printf 'Refusing to roll back: DEPLOY_SWITCH_WAIT must be a number of seconds (0-9999): %s\n' "$SWITCH_WAIT" >&2
        exit 1
        ;;
esac

if [ -n "$ROLLBACK_RELEASE" ] && ! valid_release_id "$ROLLBACK_RELEASE"; then
    printf 'Refusing to roll back: invalid release id (A-Za-z0-9._- only): %s\n' "$ROLLBACK_RELEASE" >&2
    exit 1
fi

ssh_remote_script "$SSH_APP_PATH" "$HEALTHCHECK_URL" "$ROLLBACK_RELEASE" "$SWITCH_WAIT" <<'REMOTE'
set -eu

APP_ROOT=$1
HEALTHCHECK_URL=${2:-}
ROLLBACK_RELEASE=${3:-}
SWITCH_WAIT=${4:-}

# The deploy root: a canonical absolute path of plain names (no spaces,
# no . or .., no empty or trailing /), so release paths compare as strings.
_app_path_ok=0

case "$APP_ROOT" in
    *[!A-Za-z0-9._/-]*|*//*|*/|*/./*|*/../*|*/.|*/..) ;;
    /*) _app_path_ok=1 ;;
esac

if [ "$_app_path_ok" = 0 ]; then
    printf 'Refusing: invalid app path (absolute, A-Za-z0-9._/- only): %s\n' "$APP_ROOT" >&2
    exit 1
fi
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

case "$SWITCH_WAIT" in
    *[!0-9]*|?????*)
        printf 'DEPLOY_SWITCH_WAIT must be a number of seconds (0-9999): %s\n' "$SWITCH_WAIT" >&2
        exit 1
        ;;
esac

if [ ! -L "$CURRENT_LINK" ]; then
    printf 'Current release link is missing\n' >&2
    exit 1
fi

# Resolved absolute target of link $1, whatever its spelling (a relative
# target is relative to the link, not to the working directory); its raw
# target if it cannot be entered.
link_target() {
    (cd "$1" 2>/dev/null && pwd -P) || readlink "$1"
}

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

CURRENT_TARGET=$(link_target "$CURRENT_LINK")
PREVIOUS_TARGET=''

if [ -L "$PREVIOUS_LINK" ]; then
    PREVIOUS_TARGET=$(link_target "$PREVIOUS_LINK")
fi

if [ -n "$ROLLBACK_RELEASE" ]; then
    case "$ROLLBACK_RELEASE" in
        .|..|*[!A-Za-z0-9._-]*)
            printf 'Refusing to roll back: invalid release id: %s\n' "$ROLLBACK_RELEASE" >&2
            exit 1
            ;;
    esac

    TARGET_RELEASE=$RELEASES_DIR/$ROLLBACK_RELEASE

    if [ ! -d "$TARGET_RELEASE" ]; then
        printf 'Requested rollback release does not exist: %s\n' "$ROLLBACK_RELEASE" >&2
        exit 1
    fi
elif [ -n "$PREVIOUS_TARGET" ]; then
    TARGET_RELEASE=$PREVIOUS_TARGET
else
    printf 'Previous release link is missing\n' >&2
    exit 1
fi

if [ "$(cd "$TARGET_RELEASE" 2>/dev/null && pwd -P)" = "$(cd "$CURRENT_TARGET" 2>/dev/null && pwd -P)" ]; then
    printf 'Refusing to roll back: %s is the live release\n' "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

# Production migrations are forward-only: DB::prohibitDestructiveCommands()
# refuses migrate:rollback/reset there. Only switch to a release shipping
# exactly the migrations the database ran — read live with
# `php artisan migrations:ran` (from the current or the target release),
# else from shared/schema-migrations (recorded by the last deploy); without
# either it refuses — otherwise the target could read or write data it does
# not understand (e.g. plaintext amounts into encrypted columns,
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
    printf 'Refusing to roll back: cannot tell which migrations the database ran\n' >&2
    exit 1
fi

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
            ?????*) SWITCH_WAIT=9999 ;;
        esac
        SWITCH_WAIT=$((SWITCH_WAIT + 5))
    fi

    if [ "$SWITCH_WAIT" != 0 ]; then
        printf 'Waiting %ss for PHP workers to see the new release\n' "$SWITCH_WAIT" >&2
        sleep "$SWITCH_WAIT"
    fi
}

# Long-lived queue workers keep the code of the release they started from:
# ask them to exit after their current job, so they come back on the live
# one. Best effort: the release switch is already done.
restart_queue() {
    if ! (cd "$CURRENT_LINK" && php artisan queue:restart); then
        printf 'Warning: "php artisan queue:restart" failed; restart the queue workers by hand\n' >&2
    fi
}

restore_links() {
    safe_symlink "$CURRENT_TARGET" "$CURRENT_LINK"

    if [ -n "$PREVIOUS_TARGET" ]; then
        safe_symlink "$PREVIOUS_TARGET" "$PREVIOUS_LINK"
    else
        rm -f "$PREVIOUS_LINK"
    fi

    restart_queue
}

# A marker not matching the maintenance mode on is stale (lifted by hand).
if ! deploy_maintenance; then
    rm -f "$DEPLOY_DOWN"
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

# Owner-only like the .env: it holds APP_KEY and the service passwords.
if ! (umask 077 && php artisan config:cache) || ! chmod 600 bootstrap/cache/config.php; then
    printf 'Refusing to roll back: caching the configuration of %s failed\n' "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

if ! php artisan list --raw > "$TMP_DIR/commands"; then
    printf 'Refusing to roll back: %s cannot list its commands\n' "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

if grep -q '^amounts:verify[[:space:]]' "$TMP_DIR/commands" && ! php artisan amounts:verify; then
    printf 'Refusing to roll back: amounts do not all decrypt with %s\n' "$(basename "$TARGET_RELEASE")" >&2
    exit 1
fi

safe_symlink "$CURRENT_TARGET" "$PREVIOUS_LINK"
safe_symlink "$TARGET_RELEASE" "$CURRENT_LINK"
restart_queue

# A deploy's maintenance mode (failed deploy) is lifted now that the live
# release matches the schema; an operator's is left on.
LIFTED=0

if [ -e "$DOWN_FILE" ]; then
    if ! deploy_maintenance; then
        printf 'Maintenance mode is on: left on and health check skipped — lift it ("php artisan up") once the live release is verified to match the schema, and PHP workers had time to see it (realpath_cache_ttl, see docs/encryption.md)\n' >&2
        exit 0
    fi

    settle_switch
    php artisan up
    rm -f "$DEPLOY_DOWN"
    LIFTED=1
else
    settle_switch
fi

if ! run_healthcheck; then
    if [ "$LIFTED" = 1 ]; then
        php artisan down --retry=15
        : > "$DEPLOY_DOWN"
    fi

    restore_links
    printf 'Rollback health check failed; restored previous current release\n' >&2
    exit 1
fi
REMOTE
