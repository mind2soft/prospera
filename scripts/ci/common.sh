#!/usr/bin/env sh

set -eu

SSH_KEY_PATH="$HOME/.ssh/deploy_key"

require_env() {
    missing=''

    for name in "$@"; do
        eval "value=\${$name-}"

        if [ -z "$value" ]; then
            missing="$missing $name"
        fi
    done

    if [ -n "$missing" ]; then
        printf 'Missing required environment variables:%s\n' "$missing" >&2
        exit 1
    fi
}

release_id() {
    if [ -n "${RELEASE_ID:-}" ]; then
        printf '%s\n' "$RELEASE_ID"
        return
    fi

    run_number="${GITHUB_RUN_NUMBER:-local}"
    short_sha=$(printf '%s' "${GITHUB_SHA:-local}" | cut -c1-8)

    printf '%s-%s\n' "$run_number" "$short_sha"
}

# GitHub Actions exposes secrets as values (not files): SSH_PRIVATE_KEY holds
# the private key content and SSH_KNOWN_HOSTS holds known_hosts lines.
prepare_ssh() {
    require_env SSH_PRIVATE_KEY SSH_HOST

    mkdir -p "$HOME/.ssh"
    chmod 700 "$HOME/.ssh"

    if [ -n "${SSH_KNOWN_HOSTS:-}" ]; then
        printf '%s\n' "$SSH_KNOWN_HOSTS" > "$HOME/.ssh/known_hosts"
    else
        ssh-keyscan -H "$SSH_HOST" >> "$HOME/.ssh/known_hosts"
    fi

    chmod 600 "$HOME/.ssh/known_hosts"

    printf '%s\n' "$SSH_PRIVATE_KEY" | tr -d '\r' > "$SSH_KEY_PATH"
    chmod 600 "$SSH_KEY_PATH"
}

ssh_remote() {
    ssh -i "$SSH_KEY_PATH" -o IdentitiesOnly=yes -o BatchMode=yes -o ServerAliveInterval=30 "$SSH_USER@$SSH_HOST" "$@"
}

shell_quote() {
    printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

# Run the script on stdin remotely as "sh -s -- <args>". ssh joins its
# arguments with spaces into one command line that the remote shell parses
# again, so each argument is quoted (empty or special values survive).
ssh_remote_script() {
    _command='sh -s --'

    for _arg in "$@"; do
        _command="$_command $(shell_quote "$_arg")"
    done

    ssh_remote "$_command"
}

# The deploy root: an absolute path of plain names (no spaces, no . or ..).
valid_app_path() {
    case "$1" in
        /*) ;;
        *) return 1 ;;
    esac

    case "$1" in
        *[!A-Za-z0-9._/-]*|*/./*|*/../*|*/.|*/..) return 1 ;;
    esac
}

# A release id is one directory name under releases/ (no path).
valid_release_id() {
    case "$1" in
        ''|.|..|*[!A-Za-z0-9._-]*) return 1 ;;
    esac
}

scp_to_remote() {
    require_env SSH_USER SSH_HOST
    scp -i "$SSH_KEY_PATH" -o IdentitiesOnly=yes -o BatchMode=yes "$@"
}
