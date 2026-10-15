#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-site-backup-mariadb.XXXXXX")"
PROJECT="koma-site-backup-mariadb-$RANDOM-$$"
ENV_FILE="$TEMP_ROOT/koma.docker.env"
BUNDLE="$TEMP_ROOT/operator-secrets.age"
APP_PORT="$((20000 + RANDOM % 10000))"
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
DB_PASSWORD="koma-backup-db-$RANDOM-$$"
DB_ROOT_PASSWORD="koma-backup-root-$RANDOM-$$"

cleanup() {
    docker compose -p "$PROJECT" --env-file "$ENV_FILE" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TEMP_ROOT"
}
trap cleanup EXIT

umask 077
printf '%s\n' \
    'KOMA_APP_NAME=KomaEngine Backup Smoke' \
    "KOMA_APP_URL=http://127.0.0.1:$APP_PORT" \
    "KOMA_APP_PORT=$APP_PORT" \
    "KOMA_APP_KEY=$APP_KEY_VALUE" \
    'KOMA_DB_DATABASE=komaengine' \
    'KOMA_DB_USERNAME=koma' \
    "KOMA_DB_PASSWORD=$DB_PASSWORD" \
    "KOMA_DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD" \
    'KOMA_SESSION_SECURE_COOKIE=false' \
    'KOMA_LOG_LEVEL=error' \
    'KOMA_DB_QUEUE_RETRY_AFTER=3660' \
    'KOMA_QUEUE_WORKER_TIMEOUT=3600' > "$ENV_FILE"
openssl rand -out "$BUNDLE" 48

cd "$ROOT"
docker compose -p "$PROJECT" --env-file "$ENV_FILE" up -d --build --wait

app_container="$(docker compose -p "$PROJECT" --env-file "$ENV_FILE" ps -q app)"
docker cp "$ROOT/tests/Release/support/install-database.php" "$app_container:/tmp/install-database.php"
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app \
    gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/install-database.php >/dev/null
docker compose -p "$PROJECT" --env-file "$ENV_FILE" stop worker scheduler >/dev/null

docker cp "$BUNDLE" "$app_container:/tmp/operator-secrets.age"
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T --user root app \
    install -d -o www-data -g www-data -m 0700 /tmp/site-backups
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T --user root app \
    chown www-data:www-data /tmp/operator-secrets.age

docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app \
    gosu www-data php artisan koma:site-backup:create /tmp/site-backups/mariadb \
    --secrets-bundle=/tmp/operator-secrets.age
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app \
    gosu www-data php artisan koma:site-backup:verify /tmp/site-backups/mariadb
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app sh -lc '
    test -f /tmp/site-backups/mariadb/database/database.sql
    test ! -e /tmp/site-backups/mariadb/.database-client.cnf
    ! find /tmp/site-backups/mariadb -name .database-client.cnf -print -quit | grep -q .
    grep -Fq driver /tmp/site-backups/mariadb/manifest.json
    grep -Fq mariadb /tmp/site-backups/mariadb/manifest.json
'

docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app \
    gosu www-data php artisan koma:doctor --mode=operational >/dev/null

printf 'Temporary Compose MariaDB backup create/verify smoke passed.\n'
