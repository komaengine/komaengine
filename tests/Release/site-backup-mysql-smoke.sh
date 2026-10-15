#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"

NETWORK="koma-site-backup-mysql-$RANDOM-$$"
MYSQL_CONTAINER="koma-site-backup-mysql-db-$RANDOM-$$"
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
DB_PASSWORD="koma-backup-db-$RANDOM-$$"
DB_ROOT_PASSWORD="koma-backup-root-$RANDOM-$$"

cleanup() {
    docker rm -f "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$NETWORK" >/dev/null
docker run -d \
    --name "$MYSQL_CONTAINER" \
    --network "$NETWORK" \
    -e MYSQL_DATABASE=komaengine \
    -e MYSQL_USER=koma \
    -e MYSQL_PASSWORD="$DB_PASSWORD" \
    -e MYSQL_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
    "$KOMA_MYSQL_IMAGE" >/dev/null

for attempt in $(seq 1 60); do
    if docker exec "$MYSQL_CONTAINER" mysqladmin ping -h localhost -uroot -p"$DB_ROOT_PASSWORD" --silent >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 60 ]]; then
        echo 'Temporary MySQL did not become ready.' >&2
        exit 1
    fi
    sleep 2
done

docker run --rm \
    --network "$NETWORK" \
    --entrypoint sh \
    -e APP_ENV=production \
    -e APP_DEBUG=false \
    -e APP_KEY="$APP_KEY_VALUE" \
    -e APP_URL=http://koma.test \
    -e DB_CONNECTION=mysql \
    -e DB_HOST="$MYSQL_CONTAINER" \
    -e DB_PORT=3306 \
    -e DB_DATABASE=komaengine \
    -e DB_USERNAME=koma \
    -e DB_PASSWORD="$DB_PASSWORD" \
    -e CACHE_STORE=array \
    -e SESSION_DRIVER=array \
    -e QUEUE_CONNECTION=sync \
    -e FILESYSTEM_DISK=public \
    -e MEDIA_DISK_DRIVER=local \
    komaengine:local -c '
        install -d -o www-data -g www-data -m 0700 /tmp/site-backups
        openssl rand -out /tmp/operator-secrets.age 48
        chown www-data:www-data /tmp/operator-secrets.age
        gosu www-data php artisan migrate --force >/dev/null
        gosu www-data php artisan koma:site-backup:create /tmp/site-backups/mysql --secrets-bundle=/tmp/operator-secrets.age
        gosu www-data php artisan koma:site-backup:verify /tmp/site-backups/mysql
        test -f /tmp/site-backups/mysql/database/database.sql
        test ! -e /tmp/site-backups/mysql/.database-client.cnf
        ! find /tmp/site-backups/mysql -name .database-client.cnf -print -quit | grep -q .
        grep -Fq driver /tmp/site-backups/mysql/manifest.json
        grep -Fq mysql /tmp/site-backups/mysql/manifest.json
    '

printf 'Temporary MySQL backup create/verify smoke passed.\n'
