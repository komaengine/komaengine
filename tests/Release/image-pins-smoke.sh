#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOCK_FILE="$ROOT/scripts/release/image-lock.env"
RESOLVE=false

if [[ "${1:-}" == '--resolve' ]]; then
    RESOLVE=true
elif [[ $# -ne 0 ]]; then
    echo "Usage: $0 [--resolve]" >&2
    exit 2
fi

. "$LOCK_FILE"

required_variables=(
    KOMA_NODE_IMAGE
    KOMA_PHP_FPM_IMAGE
    KOMA_PHP_83_CLI_IMAGE
    KOMA_PHP_84_CLI_IMAGE
    KOMA_PHP_85_CLI_IMAGE
    KOMA_COMPOSER_IMAGE
    KOMA_MARIADB_IMAGE
    KOMA_MYSQL_IMAGE
    KOMA_ADMINER_IMAGE
    KOMA_PLAYWRIGHT_IMAGE
    KOMA_SYFT_IMAGE
    KOMA_TRIVY_IMAGE
    KOMA_GITLEAKS_IMAGE
    KOMA_ACTIONLINT_IMAGE
)

for variable in "${required_variables[@]}"; do
    reference="${!variable:-}"
    [[ "$reference" =~ ^[^[:space:]@]+:[^[:space:]@]+@sha256:[0-9a-f]{64}$ ]] || {
        echo "Image lock is not a readable version plus SHA-256 digest: $variable" >&2
        exit 1
    }
    [[ "$reference" != *:latest@* ]] || {
        echo "Image lock uses forbidden latest tag: $variable" >&2
        exit 1
    }
done

require_literal() {
    local file="$1"
    local value="$2"

    grep -Fq -- "$value" "$ROOT/$file" || {
        echo "Pinned image lock does not match $file: $value" >&2
        exit 1
    }
}

require_literal Dockerfile "$KOMA_NODE_IMAGE"
require_literal Dockerfile "$KOMA_PHP_FPM_IMAGE"
require_literal Dockerfile "$KOMA_COMPOSER_IMAGE"
require_literal docker-compose.yml "$KOMA_MARIADB_IMAGE"
require_literal docker-compose.yml "$KOMA_ADMINER_IMAGE"
require_literal tests/Release/flatfile-builder.Dockerfile "$KOMA_NODE_IMAGE"
require_literal tests/Release/flatfile-builder.Dockerfile "$KOMA_PHP_84_CLI_IMAGE"
require_literal tests/Release/flatfile-builder.Dockerfile "$KOMA_COMPOSER_IMAGE"
require_literal tests/Release/php-matrix.Dockerfile "$KOMA_PHP_84_CLI_IMAGE"
require_literal tests/Browser/Dockerfile "$KOMA_PLAYWRIGHT_IMAGE"
require_literal tests/Browser/docker-compose.yml "$KOMA_MARIADB_IMAGE"
bash -n "$ROOT/scripts/release/pin-exported-dockerfile.sh"

reject_matches() {
    local pattern="$1"
    local message="$2"
    local status

    # grep is available in the minimal build images and GitHub runners. Only
    # exit 1 means no matches; missing tools or unreadable paths must fail closed.
    if grep -Enr -- "$pattern" \
        "$ROOT/Dockerfile" "$ROOT/docker-compose.yml" \
        "$ROOT/tests/Release" "$ROOT/tests/Browser"; then
        echo "$message" >&2
        exit 1
    else
        status=$?
        if [[ "$status" -ne 1 ]]; then
            echo "Image reference scan failed (grep exit $status)." >&2
            exit "$status"
        fi
    fi
}

reject_matches \
    '(^|[[:space:]])(FROM|image:)[[:space:]]+[^#[:space:]]*:latest([[:space:]]|$)' \
    'A trusted build or runtime path uses a latest image tag.'
reject_matches \
    '(^|[[:space:]])(node:22-bookworm-slim|php:8\.[345]-(fpm|cli)-bookworm|composer:2([[:space:]]|$)|mariadb:11([[:space:]]|$)|mysql:8\.4([[:space:]]|$)|adminer:latest)' \
    'A trusted build or runtime path still uses a mutable legacy image reference.'

if [[ "$RESOLVE" == true ]]; then
    command -v docker >/dev/null 2>&1 || {
        echo 'Docker is required to resolve image pins.' >&2
        exit 2
    }

    for variable in "${required_variables[@]}"; do
        reference="${!variable}"
        echo "Resolving $variable"
        docker buildx imagetools inspect "$reference" >/dev/null
    done
fi

printf 'Immutable image pin smoke passed%s.\n' "$([[ "$RESOLVE" == true ]] && printf ' with registry resolution')"
