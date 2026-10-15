#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"
CONTAINER="koma-mysql-smoke-$RANDOM-$$"
PASSWORD="koma-mysql-pass-$RANDOM"
ROOT_PASSWORD="koma-mysql-root-$RANDOM"
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d \
    --name "$CONTAINER" \
    -e MYSQL_DATABASE=komaengine \
    -e MYSQL_USER=koma \
    -e MYSQL_PASSWORD="$PASSWORD" \
    -e MYSQL_ROOT_PASSWORD="$ROOT_PASSWORD" \
    -p 127.0.0.1::3306 \
    "$KOMA_MYSQL_IMAGE" >/dev/null

port="$(docker port "$CONTAINER" 3306/tcp | awk -F: 'NR == 1 {print $NF}')"

for attempt in $(seq 1 60); do
    if docker exec "$CONTAINER" mysqladmin ping -h localhost -uroot -p"$ROOT_PASSWORD" --silent >/dev/null 2>&1; then
        break
    fi

    if [[ "$attempt" -eq 60 ]]; then
        docker logs "$CONTAINER" >&2
        echo "Oracle MySQL did not become ready." >&2
        exit 1
    fi

    sleep 2
done

run_php() {
    APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY="$APP_KEY_VALUE" \
    APP_URL=http://koma.test \
    DB_CONNECTION=mysql \
    DB_HOST=127.0.0.1 \
    DB_PORT="$port" \
    DB_DATABASE=komaengine \
    DB_USERNAME=koma \
    DB_PASSWORD="$PASSWORD" \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    INSTALLER_ENVIRONMENT_MODE=process \
    INSTALLER_WRITE_ENV=false \
    INSTALLER_WRITE_ROBOTS=false \
    MEDIA_DISK_DRIVER=local \
    php "$1"
}

cd "$ROOT"
run_php "$ROOT/tests/Release/support/install-database.php"
run_php "$ROOT/tests/Release/support/verify-database.php"

printf 'Oracle MySQL installer process/restart smoke passed.\n'
