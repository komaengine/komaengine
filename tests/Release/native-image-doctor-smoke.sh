#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"

IMAGE="${1:-}"
PLATFORM="${2:-}"
case "$PLATFORM" in
    linux/amd64) EXPECTED_IMAGE_ARCH=amd64; EXPECTED_HOST_PATTERN='x86_64|amd64' ;;
    linux/arm64) EXPECTED_IMAGE_ARCH=arm64; EXPECTED_HOST_PATTERN='aarch64|arm64' ;;
    *) echo "Usage: $0 IMAGE linux/amd64|linux/arm64" >&2; exit 2 ;;
esac
[[ -n "$IMAGE" ]] || {
    echo "Usage: $0 IMAGE linux/amd64|linux/arm64" >&2
    exit 2
}

HOST_ARCH="$(uname -m)"
[[ "$HOST_ARCH" =~ ^($EXPECTED_HOST_PATTERN)$ ]] || {
    echo "Host architecture $HOST_ARCH is not native for $PLATFORM." >&2
    exit 1
}

RUN_ID="$(openssl rand -hex 6)"
PREFIX="koma-native-check-$RUN_ID"
NETWORK="$PREFIX-network"
VOLUME="$PREFIX-storage"
DB_CONTAINER="$PREFIX-db"
APP_CONTAINER="$PREFIX-app"
APP_KEY_VALUE="base64:$(openssl rand -base64 32 | tr -d '\n')"
DB_PASSWORD="$(openssl rand -hex 24)"
DB_ROOT_PASSWORD="$(openssl rand -hex 24)"

maintainer_volume_identity() {
    local name
    for name in komaengine_koma-db komaengine_koma-storage; do
        if docker volume inspect "$name" >/dev/null 2>&1; then
            docker volume inspect --format '{{.Name}} {{.CreatedAt}}' "$name"
        else
            printf '%s absent\n' "$name"
        fi
    done | LC_ALL=C sort | shasum -a 256 | awk '{print $1}'
}

MAINTAINER_BEFORE="$(maintainer_volume_identity)"

cleanup() {
    docker rm -f "$APP_CONTAINER" "$DB_CONTAINER" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    docker volume rm "$VOLUME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    docker pull "$IMAGE" >/dev/null
fi
IMAGE_ARCH="$(docker image inspect --format '{{.Architecture}}' "$IMAGE")"
[[ "$IMAGE_ARCH" == "$EXPECTED_IMAGE_ARCH" ]] || {
    echo "Image architecture $IMAGE_ARCH does not match $PLATFORM." >&2
    exit 1
}

docker run --rm --entrypoint sh "$IMAGE" -c \
    '! command -v composer && ! command -v git && ! command -v unzip' || {
    echo 'Runtime image contains a build-only Composer, Git, or unzip executable.' >&2
    exit 1
}

docker network create --label io.komaengine.release-check="$RUN_ID" "$NETWORK" >/dev/null
docker volume create --label io.komaengine.release-check="$RUN_ID" "$VOLUME" >/dev/null
docker run -d \
    --name "$DB_CONTAINER" \
    --network "$NETWORK" \
    --label io.komaengine.release-check="$RUN_ID" \
    --env MARIADB_DATABASE=komaengine \
    --env MARIADB_USER=koma \
    --env "MARIADB_PASSWORD=$DB_PASSWORD" \
    --env "MARIADB_ROOT_PASSWORD=$DB_ROOT_PASSWORD" \
    "$KOMA_MARIADB_IMAGE" >/dev/null

for attempt in $(seq 1 60); do
    if docker exec "$DB_CONTAINER" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 60 ]]; then
        docker logs "$DB_CONTAINER" >&2
        echo 'Pinned MariaDB did not become ready.' >&2
        exit 1
    fi
    sleep 2
done

runtime_environment=(
    --env APP_NAME='KomaEngine Native Check'
    --env APP_ENV=production
    --env APP_DEBUG=false
    --env "APP_KEY=$APP_KEY_VALUE"
    --env APP_URL=http://koma-native.test
    --env LOG_CHANNEL=stderr
    --env LOG_LEVEL=error
    --env DB_CONNECTION=mariadb
    --env "DB_HOST=$DB_CONTAINER"
    --env DB_PORT=3306
    --env DB_DATABASE=komaengine
    --env DB_USERNAME=koma
    --env "DB_PASSWORD=$DB_PASSWORD"
    --env CACHE_STORE=file
    --env SESSION_DRIVER=file
    --env QUEUE_CONNECTION=database
    --env FILESYSTEM_DISK=public
    --env MEDIA_DISK_DRIVER=local
    --env INSTALLER_ENVIRONMENT_MODE=process
    --env INSTALLER_WRITE_ENV=false
    --env INSTALLER_WRITE_ROBOTS=false
)

docker run --rm \
    --network "$NETWORK" \
    --volume "$VOLUME:/var/www/html/storage" \
    "${runtime_environment[@]}" \
    "$IMAGE" migrate >/dev/null

docker run -d \
    --name "$APP_CONTAINER" \
    --network "$NETWORK" \
    --label io.komaengine.release-check="$RUN_ID" \
    --volume "$VOLUME:/var/www/html/storage" \
    "${runtime_environment[@]}" \
    "$IMAGE" web >/dev/null

docker cp "$ROOT/tests/Release/support/install-database.php" \
    "$APP_CONTAINER:/tmp/install-database.php"
docker exec "$APP_CONTAINER" gosu www-data env KOMA_APP_ROOT=/var/www/html \
    php /tmp/install-database.php >/dev/null
docker exec "$APP_CONTAINER" gosu www-data php artisan koma:doctor \
    --mode=operational --json | jq -e '.ready == true' >/dev/null

cleanup
trap - EXIT

[[ "$(maintainer_volume_identity)" == "$MAINTAINER_BEFORE" ]] || {
    echo 'Authoritative maintainer volume identities changed during the native smoke.' >&2
    exit 1
}

printf 'Native %s runtime install and operational doctor smoke passed.\n' "$PLATFORM"
