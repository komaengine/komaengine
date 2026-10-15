#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-site-backup-mariadb-restore.XXXXXX")"
PROJECT="koma-site-backup-mariadb-restore-$RANDOM-$$"
ENV_FILE="$TEMP_ROOT/koma.docker.env"
BUNDLE="$TEMP_ROOT/operator-secrets.age"
TARGET_CREDENTIALS="$TEMP_ROOT/restore-target.cnf"
TARGET_DB_CONTAINER="koma-site-backup-mariadb-restore-db-$RANDOM-$$"
RESTORED_APP_CONTAINER="koma-site-backup-mariadb-restore-app-$RANDOM-$$"
RESTORED_WORKER_CONTAINER="koma-site-backup-mariadb-restore-worker-$RANDOM-$$"
RESTORED_SCHEDULER_CONTAINER="koma-site-backup-mariadb-restore-scheduler-$RANDOM-$$"
APP_PORT="$((20000 + RANDOM % 10000))"
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
SOURCE_DB_PASSWORD="koma-backup-source-$RANDOM-$$"
SOURCE_DB_ROOT_PASSWORD="koma-backup-source-root-$RANDOM-$$"
TARGET_DB_PASSWORD="koma-backup-target-$RANDOM-$$"
TARGET_DB_ROOT_PASSWORD="koma-backup-target-root-$RANDOM-$$"

cleanup() {
    docker rm -f "$TARGET_DB_CONTAINER" "$RESTORED_APP_CONTAINER" "$RESTORED_WORKER_CONTAINER" "$RESTORED_SCHEDULER_CONTAINER" >/dev/null 2>&1 || true
    docker compose -p "$PROJECT" --env-file "$ENV_FILE" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TEMP_ROOT"
}
trap cleanup EXIT

umask 077
printf '%s\n' \
    'KOMA_APP_NAME=KomaEngine MariaDB Restore Smoke' \
    "KOMA_APP_URL=http://127.0.0.1:$APP_PORT" \
    "KOMA_APP_PORT=$APP_PORT" \
    "KOMA_APP_KEY=$APP_KEY_VALUE" \
    'KOMA_DB_DATABASE=komaengine' \
    'KOMA_DB_USERNAME=koma' \
    "KOMA_DB_PASSWORD=$SOURCE_DB_PASSWORD" \
    "KOMA_DB_ROOT_PASSWORD=$SOURCE_DB_ROOT_PASSWORD" \
    'KOMA_SESSION_SECURE_COOKIE=false' \
    'KOMA_LOG_LEVEL=error' \
    'KOMA_DB_QUEUE_RETRY_AFTER=3660' \
    'KOMA_QUEUE_WORKER_TIMEOUT=3600' > "$ENV_FILE"
openssl rand -out "$BUNDLE" 48

cd "$ROOT"
docker compose -p "$PROJECT" --env-file "$ENV_FILE" up -d --build --wait

app_container="$(docker compose -p "$PROJECT" --env-file "$ENV_FILE" ps -q app)"
docker cp "$ROOT/tests/Release/support/install-database.php" "$app_container:/tmp/install-database.php"
docker cp "$ROOT/tests/Release/support/create-recovery-fixture.php" "$app_container:/tmp/create-recovery-fixture.php"
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app \
    gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/install-database.php >/dev/null
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app \
    gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/create-recovery-fixture.php
docker compose -p "$PROJECT" --env-file "$ENV_FILE" stop worker scheduler >/dev/null

docker cp "$BUNDLE" "$app_container:/tmp/operator-secrets.age"
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T --user root app \
    sh -lc 'install -d -o www-data -g www-data -m 0700 /tmp/site-backups /tmp/site-restores && chown www-data:www-data /tmp/operator-secrets.age'
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app \
    gosu www-data php artisan koma:site-backup:create /tmp/site-backups/mariadb \
    --secrets-bundle=/tmp/operator-secrets.age

docker run -d \
    --name "$TARGET_DB_CONTAINER" \
    --network "${PROJECT}_default" \
    -e MARIADB_DATABASE=restored_koma \
    -e MARIADB_USER=koma \
    -e MARIADB_PASSWORD="$TARGET_DB_PASSWORD" \
    -e MARIADB_ROOT_PASSWORD="$TARGET_DB_ROOT_PASSWORD" \
    "$KOMA_MARIADB_IMAGE" >/dev/null

for attempt in $(seq 1 60); do
    if docker exec "$TARGET_DB_CONTAINER" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 60 ]]; then
        echo 'Temporary MariaDB restore target did not become ready.' >&2
        exit 1
    fi
    sleep 2
done

printf '%s\n' \
    '[client]' \
    "host=$TARGET_DB_CONTAINER" \
    'port=3306' \
    'user=koma' \
    "password=$TARGET_DB_PASSWORD" > "$TARGET_CREDENTIALS"
docker cp "$TARGET_CREDENTIALS" "$app_container:/tmp/restore-target.cnf"
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T --user root app \
    sh -lc 'chown www-data:www-data /tmp/restore-target.cnf && chmod 600 /tmp/restore-target.cnf'
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app \
    gosu www-data php artisan koma:site-backup:restore /tmp/site-backups/mariadb \
    --target-root=/tmp/site-restores/mariadb \
    --database-credentials=/tmp/restore-target.cnf \
    --database-target=restored_koma \
    --force >/dev/null
docker compose -p "$PROJECT" --env-file "$ENV_FILE" exec -T app sh -lc '
    test -f /tmp/site-restores/mariadb/storage/app/media/recovery/reader.txt
    test -f /tmp/site-restores/mariadb/storage/app/private/recovery/input.txt
    test -L /tmp/site-restores/mariadb/public/storage
    test "$(readlink /tmp/site-restores/mariadb/public/storage)" = "../storage/app/public"
    test ! -e /tmp/site-restores/mariadb/database/database.sql
    test ! -e /tmp/site-restores/mariadb/restore-target.cnf
'

docker cp "$TARGET_CREDENTIALS" "$TARGET_DB_CONTAINER:/tmp/restore-target.cnf"
docker exec "$TARGET_DB_CONTAINER" sh -lc '
    chmod 600 /tmp/restore-target.cnf
    mariadb --defaults-extra-file=/tmp/restore-target.cnf --database=restored_koma --batch --skip-column-names \
      --execute="SELECT COUNT(*) FROM migrations" | grep -Eq "^[1-9][0-9]*$"
'

docker cp "$app_container:/tmp/site-restores/mariadb" "$TEMP_ROOT/restored"
docker run -d \
    --name "$RESTORED_APP_CONTAINER" \
    --network "${PROJECT}_default" \
    -e APP_ENV=production \
    -e APP_DEBUG=false \
    -e APP_KEY="$APP_KEY_VALUE" \
    -e APP_URL=http://restored.koma.test \
    -e DB_CONNECTION=mariadb \
    -e DB_HOST="$TARGET_DB_CONTAINER" \
    -e DB_PORT=3306 \
    -e DB_DATABASE=restored_koma \
    -e DB_USERNAME=koma \
    -e DB_PASSWORD="$TARGET_DB_PASSWORD" \
    -e CACHE_STORE=array \
    -e SESSION_DRIVER=array \
    -e QUEUE_CONNECTION=sync \
    -e FILESYSTEM_DISK=public \
    -e MEDIA_DISK_DRIVER=local \
    -v "$TEMP_ROOT/restored/storage:/var/www/html/storage" \
    komaengine:local web >/dev/null

for attempt in $(seq 1 30); do
    if docker exec "$RESTORED_APP_CONTAINER" gosu www-data php artisan koma:doctor --mode=operational >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 30 ]]; then
        echo 'Restored MariaDB application did not reach operational doctor readiness.' >&2
        exit 1
    fi
    sleep 1
done

docker exec "$RESTORED_APP_CONTAINER" php -r '
    $context = stream_context_create(["http" => ["ignore_errors" => true, "max_redirects" => 0]]);
    $check = function (string $path, string $status, ?string $needle = null) use ($context): void {
        $body = file_get_contents("http://127.0.0.1".$path, false, $context);
        $actual = $http_response_header[0] ?? "";
        if (! str_contains($actual, $status) || ($needle !== null && ! str_contains((string) $body, $needle))) {
            fwrite(STDERR, "Restored HTTP check failed for {$path}.\n");
            exit(1);
        }
    };
    $check("/", "200", "Recovery Fixture");
    $check("/series/recovery-fixture", "200", "Recovery Fixture");
    $check("/series/recovery-fixture/chapter/1", "200", "Recovered chapter");
    $check("/media/series/recovery-fixture/chapters/1/pages/1", "200", "reader-media");
    $check("/admin", "302");
'

docker run -d \
    --name "$RESTORED_WORKER_CONTAINER" \
    --network "${PROJECT}_default" \
    -e APP_ENV=production -e APP_DEBUG=false -e APP_KEY="$APP_KEY_VALUE" -e APP_URL=http://restored.koma.test \
    -e DB_CONNECTION=mariadb -e DB_HOST="$TARGET_DB_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE=restored_koma -e DB_USERNAME=koma -e DB_PASSWORD="$TARGET_DB_PASSWORD" \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=database -e FILESYSTEM_DISK=public -e MEDIA_DISK_DRIVER=local \
    -e QUEUE_WORKER_TIMEOUT=3600 -e DB_QUEUE_RETRY_AFTER=3660 \
    -v "$TEMP_ROOT/restored/storage:/var/www/html/storage" \
    komaengine:local worker >/dev/null
docker run -d \
    --name "$RESTORED_SCHEDULER_CONTAINER" \
    --network "${PROJECT}_default" \
    -e APP_ENV=production -e APP_DEBUG=false -e APP_KEY="$APP_KEY_VALUE" -e APP_URL=http://restored.koma.test \
    -e DB_CONNECTION=mariadb -e DB_HOST="$TARGET_DB_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE=restored_koma -e DB_USERNAME=koma -e DB_PASSWORD="$TARGET_DB_PASSWORD" \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=database -e FILESYSTEM_DISK=public -e MEDIA_DISK_DRIVER=local \
    -v "$TEMP_ROOT/restored/storage:/var/www/html/storage" \
    komaengine:local scheduler >/dev/null
sleep 3
test "$(docker inspect -f '{{.State.Running}}' "$RESTORED_WORKER_CONTAINER")" = 'true'
test "$(docker inspect -f '{{.State.Running}}' "$RESTORED_SCHEDULER_CONTAINER")" = 'true'

printf 'Temporary Compose MariaDB backup/restore smoke passed.\n'
