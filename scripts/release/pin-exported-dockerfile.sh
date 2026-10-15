#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKERFILE="${1:-}"
[[ -f "$DOCKERFILE" && "$(basename "$DOCKERFILE")" == Dockerfile ]] || {
    echo "Usage: $0 /disposable/export/Dockerfile" >&2
    exit 2
}

. "$ROOT/scripts/release/image-lock.env"

TEMP_FILE="$(mktemp "${DOCKERFILE}.pinned.XXXXXX")"
cleanup() {
    rm -f "$TEMP_FILE"
}
trap cleanup EXIT

awk \
    -v node="$KOMA_NODE_IMAGE" \
    -v php="$KOMA_PHP_FPM_IMAGE" \
    -v composer="$KOMA_COMPOSER_IMAGE" '
    $0 == "# syntax=docker/dockerfile:1" { next }
    $0 == "FROM node:22-bookworm-slim AS assets" { print "FROM " node " AS assets"; next }
    $0 == "FROM php:8.4-fpm-bookworm AS runtime" { print "FROM " php " AS runtime"; next }
    $0 == "COPY --from=composer:2 /usr/bin/composer /usr/bin/composer" {
        print "COPY --from=" composer " /usr/bin/composer /usr/bin/composer"
        next
    }
    { print }
    ' "$DOCKERFILE" > "$TEMP_FILE"

grep -Fxq "FROM $KOMA_NODE_IMAGE AS assets" "$TEMP_FILE"
grep -Fxq "FROM $KOMA_PHP_FPM_IMAGE AS runtime" "$TEMP_FILE"
grep -Fxq "COPY --from=$KOMA_COMPOSER_IMAGE /usr/bin/composer /usr/bin/composer" "$TEMP_FILE"
if grep -Eq '^# syntax=|^FROM (node:22-bookworm-slim|php:8\.4-fpm-bookworm)|^COPY --from=composer:2 ' \
    "$TEMP_FILE"; then
    echo 'Exported Dockerfile still contains a mutable trusted build input.' >&2
    exit 1
fi

chmod 0644 "$TEMP_FILE"
mv "$TEMP_FILE" "$DOCKERFILE"
trap - EXIT
