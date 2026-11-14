# Shared e2e environment guard, sourced by e2e/serve.sh and e2e/seed.sh.
# Assumes the caller has cd'd to the project root and runs with `set -e`.
#
# The e2e suite must never touch the developer's database or environment:
#   1. .env.e2e may only ever point at database/e2e.sqlite — a stale,
#      hand-edited or ambiguous file (missing target, duplicates, config
#      cache) is refused outright.
#   2. .env.e2e is a derived file ("from .env, once"): when it goes stale
#      (its APP_KEY diverged from .env's — e.g. after an intentional
#      APP_KEY rotation), it is re-derived so the documented invariant
#      (same APP_KEY → identical signed links and Fortify behaviour) holds.
#      Re-deriving is safe: the e2e database is disposable — serve.sh wipes
#      it (migrate:fresh) on every boot.
#
# Values are read exactly like phpdotenv reads them: the LAST active
# occurrence wins, `export ` prefixes are honoured, comments and leading
# whitespace are skipped. Duplicates are refused rather than guessed at.
#
# Kept POSIX-sh compatible (no pipefail reliance, no pipelines at all) so
# it can be sourced from test harnesses running under sh — see
# scripts/ci/tests/e2e-guard.test.sh.

E2E_DATABASE='database/e2e.sqlite'
E2E_ENV_FILE='.env.e2e'

# Prints the value of every active `KEY=…` entry of a file, one per line
# ('' lines included — count them to detect duplicates). Single awk
# command: no pipeline, so the exit status never depends on a match.
_e2e_entries_of() {
    awk -v key="$1" '{
        line = $0
        sub(/^[[:space:]]+/, "", line)
        if (line ~ /^#/) next
        sub(/^export[[:space:]]+/, "", line)
        if (index(line, key "=") == 1) print substr(line, length(key) + 2)
    }' "$2"
}

# The effective value of a key in a file ('' when absent) — phpdotenv
# semantics: the last occurrence wins, so that is the effective one.
_e2e_value_of() {
    _e2e_entries_of "$@" | awk '{ value = $0 } END { print value }'
}

# Refuse a cached config outright: with bootstrap/cache/config.php present
# Laravel never loads the env files at all, so --env=e2e would silently use
# the cached (developer) database configuration.
if [ -e bootstrap/cache/config.php ]; then
    echo "ERROR: bootstrap/cache/config.php exists — a cached config ignores --env=e2e and would run the e2e suite against the developer's configuration." >&2
    echo "Run 'php artisan config:clear' and try again." >&2
    exit 1
fi

if [ ! -f .env ]; then
    echo "ERROR: no .env file in $(pwd) — the e2e environment is derived from it." >&2
    exit 1
fi

if [ -f "$E2E_ENV_FILE" ]; then
    # shellcheck disable=SC2312 — the command substitution is deliberate.
    case "$(_e2e_entries_of DB_DATABASE "$E2E_ENV_FILE" | awk 'END { print NR }')" in
        0)
            echo "ERROR: $E2E_ENV_FILE has no DB_DATABASE line. The e2e environment may only ever use $E2E_DATABASE." >&2
            echo "Delete $E2E_ENV_FILE (it is derived from .env) and try again." >&2
            exit 1
            ;;
        1)
            current_db=$(_e2e_value_of DB_DATABASE "$E2E_ENV_FILE")
            if [ "$current_db" != "$E2E_DATABASE" ]; then
                echo "ERROR: $E2E_ENV_FILE points at '${current_db:-<unset>}'. The e2e environment may only ever use $E2E_DATABASE." >&2
                echo "Delete $E2E_ENV_FILE (it is derived from .env) and try again." >&2
                exit 1
            fi
            ;;
        *)
            echo "ERROR: $E2E_ENV_FILE has duplicate DB_DATABASE lines — Laravel would use the last one, which may not be the e2e database." >&2
            echo "Delete $E2E_ENV_FILE (it is derived from .env) and try again." >&2
            exit 1
            ;;
    esac
fi

_e2e_derive=false
if [ ! -f "$E2E_ENV_FILE" ]; then
    _e2e_derive=true
elif [ "$(_e2e_value_of APP_KEY "$E2E_ENV_FILE")" != "$(_e2e_value_of APP_KEY .env)" ]; then
    echo "NOTICE: $E2E_ENV_FILE is stale (its APP_KEY diverged from .env) — re-deriving it." >&2
    _e2e_derive=true
fi

if [ "$_e2e_derive" = true ]; then
    # Write to a temporary file and rename: a concurrent reader (e.g. an
    # in-flight `npm run e2e:seed`) must never see a partially written file.
    # Every DB_DATABASE mention is stripped from the source — active lines,
    # `export ` and indented variants, and comments — so the appended e2e
    # target is the only one phpdotenv (first occurrence wins) can pick up.
    _e2e_tmp="$E2E_ENV_FILE.tmp.$$"
    if ! sed -e 's/^APP_ENV=.*/APP_ENV=e2e/' \
        -e '/DB_DATABASE=/d' \
        .env > "$_e2e_tmp"; then
        rm -f "$_e2e_tmp"
        echo "ERROR: could not derive $E2E_ENV_FILE from .env." >&2
        exit 1
    fi
    {
        printf '\n# E2E ONLY: dedicated sqlite database for the Playwright suite — never the dev DB.\n'
        printf 'DB_DATABASE=%s\n' "$E2E_DATABASE"
    } >> "$_e2e_tmp"
    mv "$_e2e_tmp" "$E2E_ENV_FILE"
fi
