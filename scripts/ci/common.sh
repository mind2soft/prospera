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
    ssh -i "$SSH_KEY_PATH" -o IdentitiesOnly=yes -o BatchMode=yes "$SSH_USER@$SSH_HOST" "$@"
}

scp_to_remote() {
    require_env SSH_USER SSH_HOST
    scp -i "$SSH_KEY_PATH" -o IdentitiesOnly=yes -o BatchMode=yes "$@"
}
