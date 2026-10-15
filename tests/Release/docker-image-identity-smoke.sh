#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_COMMIT="${KOMA_TEST_SOURCE_COMMIT:-$(git -C "$ROOT" rev-parse HEAD)}"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-image-identity.XXXXXX")"
CONTEXT="$TEMP_ROOT/context"
IMAGE="koma-image-identity-$RANDOM-$$:local"
INVALID_IMAGE="koma-image-identity-invalid-$RANDOM-$$:local"
ERROR_LOG="$TEMP_ROOT/error.log"

cleanup() {
    docker image rm "$IMAGE" "$INVALID_IMAGE" >/dev/null 2>&1 || true
    rm -rf "$TEMP_ROOT"
}
trap cleanup EXIT

if [[ ! "$SOURCE_COMMIT" =~ ^[0-9a-f]{40}$ ]] \
    || [[ "$(git -C "$ROOT" rev-parse --verify "${SOURCE_COMMIT}^{commit}" 2>/dev/null)" != "$SOURCE_COMMIT" ]]; then
    echo "KOMA_TEST_SOURCE_COMMIT must be an available full lowercase 40-character Git commit." >&2
    exit 2
fi

mkdir -p "$CONTEXT"
git -C "$ROOT" archive "$SOURCE_COMMIT" | tar -x -C "$CONTEXT"

RELEASE_VERSION="$(tr -d '\r\n' < "$CONTEXT/VERSION")"
EXPECTED_BUILD_ID="${RELEASE_VERSION}+${SOURCE_COMMIT:0:12}"

docker build \
    --file "$CONTEXT/Dockerfile" \
    --tag "$IMAGE" \
    --build-arg "KOMA_RELEASE_VERSION=$RELEASE_VERSION" \
    --build-arg "KOMA_RELEASE_COMMIT=$SOURCE_COMMIT" \
    --build-arg KOMA_RELEASE_DIRTY=false \
    --build-arg "KOMA_RELEASE_BUILD_ID=$EXPECTED_BUILD_ID" \
    --build-arg KOMA_TRUSTED_BUILD=true \
    "$CONTEXT"

label() {
    docker image inspect --format "{{ index .Config.Labels \"$1\" }}" "$IMAGE"
}

[[ "$(label org.opencontainers.image.version)" == "$RELEASE_VERSION" ]]
[[ "$(label org.opencontainers.image.revision)" == "$SOURCE_COMMIT" ]]
[[ "$(label org.opencontainers.image.licenses)" == MIT ]]
[[ "$(label io.komaengine.build-id)" == "$EXPECTED_BUILD_ID" ]]
[[ "$(label io.komaengine.source-dirty)" == false ]]

metadata="$(docker run --rm --entrypoint sh "$IMAGE" -c 'cat /var/www/html/KOMA-BUILD-METADATA.txt')"

metadata_value() {
    local key="$1"
    local count
    local value

    count="$(grep -c "^${key}=" <<< "$metadata" || true)"
    [[ "$count" == 1 ]] || {
        echo "Image metadata field is missing or duplicated: $key" >&2
        exit 1
    }
    value="$(sed -n "s/^${key}=//p" <<< "$metadata")"
    [[ -n "$value" ]] || {
        echo "Image metadata field is empty: $key" >&2
        exit 1
    }
    printf '%s\n' "$value"
}

[[ "$(metadata_value format)" == koma-build-v1 ]]
[[ "$(metadata_value artifact)" == docker ]]
[[ "$(metadata_value release_version)" == "$RELEASE_VERSION" ]]
[[ "$(metadata_value source_commit)" == "$SOURCE_COMMIT" ]]
[[ "$(metadata_value source_dirty)" == false ]]
[[ "$(metadata_value source_policy)" == exact-commit ]]
[[ "$(metadata_value build_id)" == "$EXPECTED_BUILD_ID" ]]
[[ "$(metadata_value composer_lock_sha256)" == "$(shasum -a 256 "$CONTEXT/composer.lock" | awk '{print $1}')" ]]
[[ "$(metadata_value package_lock_sha256)" == "$(shasum -a 256 "$CONTEXT/package-lock.json" | awk '{print $1}')" ]]

docker run --rm --entrypoint sh \
    --env "KOMA_EXPECTED_VERSION=$RELEASE_VERSION" \
    --env "KOMA_EXPECTED_COMMIT=$SOURCE_COMMIT" \
    --env "KOMA_EXPECTED_BUILD_ID=$EXPECTED_BUILD_ID" \
    "$IMAGE" -c \
    'test "$(tr -d "\r\n" < /var/www/html/VERSION)" = "$KOMA_EXPECTED_VERSION" \
      && test "$KOMA_RELEASE_VERSION" = "$KOMA_EXPECTED_VERSION" \
      && test "$KOMA_RELEASE_COMMIT" = "$KOMA_EXPECTED_COMMIT" \
      && test "$KOMA_RELEASE_BUILD_ID" = "$KOMA_EXPECTED_BUILD_ID" \
      && test "$KOMA_RELEASE_DIRTY" = false \
      && grep -Fxq "MIT License" /var/www/html/LICENSE \
      && test -f /var/www/html/SECURITY.md \
      && test -z "$(find /var/www/html/storage/app -type f -print -quit)" \
      && test ! -e /var/www/html/.env \
      && test ! -e /var/www/html/.claude \
      && test ! -e /var/www/html/tests'

image_plugins="$(docker run --rm --entrypoint sh "$IMAGE" -c \
    'for plugin in /var/www/html/plugins/*/; do [ -d "$plugin" ] && basename "$plugin"; done' | sort)"
source_plugins="$(git -C "$ROOT" ls-tree --name-only "$SOURCE_COMMIT" plugins/ | sed 's|^plugins/||' | sort)"
[[ "$image_plugins" == "$source_plugins" ]] || {
    echo "Image plugins differ from the selected Git tree: ${image_plugins//$'\n'/ }" >&2
    exit 1
}

if docker build \
    --file "$CONTEXT/Dockerfile" \
    --tag "$INVALID_IMAGE" \
    --build-arg "KOMA_RELEASE_VERSION=$RELEASE_VERSION" \
    --build-arg KOMA_RELEASE_COMMIT=unavailable \
    --build-arg KOMA_RELEASE_DIRTY=false \
    --build-arg "KOMA_RELEASE_BUILD_ID=$EXPECTED_BUILD_ID" \
    --build-arg KOMA_TRUSTED_BUILD=true \
    "$CONTEXT" > /dev/null 2> "$ERROR_LOG"; then
    echo "Trusted image build accepted a missing source commit." >&2
    exit 1
fi

if docker build \
    --file "$CONTEXT/Dockerfile" \
    --tag "$INVALID_IMAGE" \
    --build-arg "KOMA_RELEASE_VERSION=$RELEASE_VERSION" \
    --build-arg "KOMA_RELEASE_COMMIT=$SOURCE_COMMIT" \
    --build-arg KOMA_RELEASE_DIRTY=true \
    --build-arg "KOMA_RELEASE_BUILD_ID=$EXPECTED_BUILD_ID" \
    --build-arg KOMA_TRUSTED_BUILD=true \
    "$CONTEXT" > /dev/null 2>> "$ERROR_LOG"; then
    echo "Trusted image build accepted a dirty source." >&2
    exit 1
fi

if docker build \
    --file "$CONTEXT/Dockerfile" \
    --tag "$INVALID_IMAGE" \
    --build-arg "KOMA_RELEASE_VERSION=$RELEASE_VERSION" \
    --build-arg "KOMA_RELEASE_COMMIT=$SOURCE_COMMIT" \
    --build-arg KOMA_RELEASE_DIRTY=false \
    --build-arg KOMA_RELEASE_BUILD_ID=mismatched \
    --build-arg KOMA_TRUSTED_BUILD=true \
    "$CONTEXT" > /dev/null 2>> "$ERROR_LOG"; then
    echo "Trusted image build accepted a mismatched build identity." >&2
    exit 1
fi

printf 'Docker image identity smoke passed for %s (%s).\n' "$SOURCE_COMMIT" "$EXPECTED_BUILD_ID"
