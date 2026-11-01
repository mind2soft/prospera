#!/usr/bin/env sh

set -eu

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
. "$SCRIPT_DIR/common.sh"

test -f public/build/manifest.json || {
    printf 'Missing Vite manifest\n' >&2
    exit 1
}

RELEASE_ID=$(release_id)
WORK_DIR=$(mktemp -d)

cleanup() {
    rm -rf "$WORK_DIR"
}

trap cleanup EXIT HUP INT TERM

rm -rf dist
mkdir -p dist

git archive --format=tar HEAD | tar -xf - -C "$WORK_DIR"
mkdir -p "$WORK_DIR/public"
cp -R public/build "$WORK_DIR/public/build"

tar -czf dist/release.tar.gz -C "$WORK_DIR" .

