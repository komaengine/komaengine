#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERSION="${1:-}"
. "$ROOT/scripts/release/image-lock.env"

case "$VERSION" in
    8.3) PHP_IMAGE="$KOMA_PHP_83_CLI_IMAGE" ;;
    8.4) PHP_IMAGE="$KOMA_PHP_84_CLI_IMAGE" ;;
    8.5) PHP_IMAGE="$KOMA_PHP_85_CLI_IMAGE" ;;
    *)
        echo "Usage: $0 8.3|8.4|8.5" >&2
        exit 2
        ;;
esac

TAG="komaengine-php${VERSION/./}:release-smoke"
LOG="$(mktemp "${TMPDIR:-/tmp}/koma-php-$VERSION.XXXXXX")"
trap 'rm -f "$LOG"' EXIT

docker build \
    --file "$ROOT/tests/Release/php-matrix.Dockerfile" \
    --build-arg "PHP_IMAGE=$PHP_IMAGE" \
    --tag "$TAG" \
    "$ROOT/tests/Release"

docker run --rm \
    --user "$(id -u):$(id -g)" \
    --env HOME=/tmp/koma-php-home \
    --volume "$ROOT:/app" \
    --workdir /app \
    "$TAG" \
    php -d memory_limit=512M artisan test 2>&1 | tee "$LOG"

if grep -Eiq '(^|[^[:alpha:]])(deprecated|deprecation|warning)([^[:alpha:]]|$)' "$LOG"; then
    echo "PHP $VERSION emitted an unexpected warning or deprecation." >&2
    exit 1
fi

printf 'PHP %s application suite passed without warnings or deprecations.\n' "$VERSION"
