#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"

NETWORK="koma-site-backup-mysql-restore-$RANDOM-$$"
SOURCE_DB_CONTAINER="koma-site-backup-mysql-restore-source-$RANDOM-$$"
TARGET_DB_CONTAINER="koma-site-backup-mysql-restore-target-$RANDOM-$$"
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
SOURCE_DB_PASSWORD="koma-backup-source-$RANDOM-$$"
SOURCE_DB_ROOT_PASSWORD="koma-backup-source-root-$RANDOM-$$"
TARGET_DB_PASSWORD="koma-backup-target-$RANDOM-$$"
TARGET_DB_ROOT_PASSWORD="koma-backup-target-root-$RANDOM-$$"

cleanup() {
    docker rm -f "$SOURCE_DB_CONTAINER" "$TARGET_DB_CONTAINER" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$NETWORK" >/dev/null
docker run -d \
    --name "$SOURCE_DB_CONTAINER" \
    --network "$NETWORK" \
    -e MYSQL_DATABASE=komaengine \
    -e MYSQL_USER=koma \
    -e MYSQL_PASSWORD="$SOURCE_DB_PASSWORD" \
    -e MYSQL_ROOT_PASSWORD="$SOURCE_DB_ROOT_PASSWORD" \
    "$KOMA_MYSQL_IMAGE" >/dev/null
docker run -d \
    --name "$TARGET_DB_CONTAINER" \
    --network "$NETWORK" \
    -e MYSQL_DATABASE=restored_koma \
    -e MYSQL_USER=koma \
    -e MYSQL_PASSWORD="$TARGET_DB_PASSWORD" \
    -e MYSQL_ROOT_PASSWORD="$TARGET_DB_ROOT_PASSWORD" \
    "$KOMA_MYSQL_IMAGE" >/dev/null

for attempt in $(seq 1 60); do
    if docker exec "$SOURCE_DB_CONTAINER" mysqladmin ping -h 127.0.0.1 -uroot -p"$SOURCE_DB_ROOT_PASSWORD" --silent >/dev/null 2>&1 \
        && docker exec "$TARGET_DB_CONTAINER" mysqladmin ping -h 127.0.0.1 -uroot -p"$TARGET_DB_ROOT_PASSWORD" --silent >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 60 ]]; then
        echo 'Temporary MySQL backup or restore target did not become ready.' >&2
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
    -e DB_HOST="$SOURCE_DB_CONTAINER" \
    -e DB_PORT=3306 \
    -e DB_DATABASE=komaengine \
    -e DB_USERNAME=koma \
    -e DB_PASSWORD="$SOURCE_DB_PASSWORD" \
    -e TARGET_DB_HOST="$TARGET_DB_CONTAINER" \
    -e TARGET_DB_PASSWORD="$TARGET_DB_PASSWORD" \
    -e CACHE_STORE=array \
    -e SESSION_DRIVER=array \
    -e QUEUE_CONNECTION=sync \
    -e FILESYSTEM_DISK=public \
    -e MEDIA_DISK_DRIVER=local \
    komaengine:local -c '
        install -d -o www-data -g www-data -m 0700 /tmp/site-backups /tmp/site-restores
        openssl rand -out /tmp/operator-secrets.age 48
        chown www-data:www-data /tmp/operator-secrets.age
        gosu www-data php artisan migrate --force >/dev/null
        gosu www-data php artisan koma:site-backup:create /tmp/site-backups/mysql --secrets-bundle=/tmp/operator-secrets.age >/dev/null
        printf "Created temporary MySQL backup.\n"
        umask 077
        printf "%s\n" "[client]" "host=$TARGET_DB_HOST" "port=3306" "user=koma" "password=$TARGET_DB_PASSWORD" > /tmp/restore-target.cnf
        chown www-data:www-data /tmp/restore-target.cnf
        gosu www-data php artisan koma:site-backup:restore /tmp/site-backups/mysql \
          --target-root=/tmp/site-restores/mysql \
          --database-credentials=/tmp/restore-target.cnf \
          --database-target=restored_koma \
          --force >/dev/null
        printf "Restored temporary MySQL backup.\n"
        test -f /tmp/site-restores/mysql/release/composer.lock
        test -L /tmp/site-restores/mysql/public/storage
        test ! -e /tmp/site-restores/mysql/database/database.sql
        test ! -e /tmp/site-restores/mysql/restore-target.cnf
        for attempt in $(seq 1 30); do
          migrations=$(gosu www-data php -r '\''$pdo = new PDO("mysql:host=".getenv("TARGET_DB_HOST").";port=3306;dbname=restored_koma", "koma", getenv("TARGET_DB_PASSWORD")); echo $pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();'\'' 2>/dev/null || true)
          if printf "%s" "$migrations" | grep -Eq "^[1-9][0-9]*$"; then
            printf "Restored MySQL migration rows: %s\n" "$migrations"
            exit 0
          fi
          if [ -n "$migrations" ]; then
            printf "Unexpected restored MySQL migration count: %s\n" "$migrations" >&2
            exit 1
          fi
          sleep 1
        done
        exit 1
    '

printf 'Temporary MySQL backup/restore smoke passed.\n'
