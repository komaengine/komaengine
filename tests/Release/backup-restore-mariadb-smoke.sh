#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-backup-restore-mariadb.XXXXXX")"
SOURCE_PROJECT="koma-recovery-source-$RANDOM-$$"
TARGET_PROJECT="koma-recovery-target-$RANDOM-$$"
SOURCE_ENV="$TEMP_ROOT/source.env"
TARGET_ENV="$TEMP_ROOT/target.env"
TARGET_COMPOSE="$TEMP_ROOT/recovered-compose.yml"
SOURCE_STATE="$TEMP_ROOT/source-state.json"
TARGET_STATE="$TEMP_ROOT/target-state.json"
TARGET_CREDENTIALS="$TEMP_ROOT/target-client.cnf"
RESTORED_ROOT="$TEMP_ROOT/recovered-site"
BACKUP_PATH='/tmp/koma-site-backups/mariadb'
RESTORE_PATH='/tmp/koma-site-restores/mariadb'
SOURCE_PORT="$((22000 + RANDOM % 1500))"
TARGET_PORT="$((24000 + RANDOM % 1500))"
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
SOURCE_DB_PASSWORD="$(openssl rand -hex 24)"
SOURCE_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
TARGET_DB_PASSWORD="$(openssl rand -hex 24)"
TARGET_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
SOURCE_APP=''
TARGET_APP=''
TARGET_DB=''
CLEANED=false
STEP='initialization'

source_compose() {
    docker compose --project-name "$SOURCE_PROJECT" --env-file "$SOURCE_ENV" -f "$ROOT/docker-compose.yml" "$@"
}

target_compose() {
    docker compose --project-name "$TARGET_PROJECT" --env-file "$TARGET_ENV" -f "$TARGET_COMPOSE" "$@"
}

cleanup() {
    source_compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    target_compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -r -- "$TEMP_ROOT" >/dev/null 2>&1 || true
}

on_exit() {
    status=$?
    cleanup

    if [[ "$status" -ne 0 ]]; then
        printf 'Disposable Compose MariaDB backup/restore recovery smoke failed during %s.\n' "$STEP" >&2
    fi

    exit "$status"
}
trap on_exit EXIT

require_no_project_resources() {
    local project="$1"

    test -z "$(docker ps -aq --filter "label=com.docker.compose.project=$project")"
    test -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")"
    test -z "$(docker network ls -q --filter "label=com.docker.compose.project=$project")"
}

copy_support() {
    local container="$1"
    local support="$2"

    docker cp "$ROOT/tests/Release/support/$support" "$container:/tmp/$support"
}

printf '%s\n' \
    "KOMA_APP_KEY=$APP_KEY_VALUE" \
    "KOMA_APP_URL=http://127.0.0.1:$SOURCE_PORT" \
    "KOMA_APP_PORT=$SOURCE_PORT" \
    'KOMA_SESSION_SECURE_COOKIE=false' \
    'KOMA_DB_DATABASE=komaengine' \
    'KOMA_DB_USERNAME=koma' \
    "KOMA_DB_PASSWORD=$SOURCE_DB_PASSWORD" \
    "KOMA_DB_ROOT_PASSWORD=$SOURCE_DB_ROOT_PASSWORD" \
    > "$SOURCE_ENV"
chmod 600 "$SOURCE_ENV"

printf '%s\n' \
    "KOMA_APP_KEY=$APP_KEY_VALUE" \
    "KOMA_APP_URL=http://127.0.0.1:$TARGET_PORT" \
    "KOMA_APP_PORT=$TARGET_PORT" \
    'KOMA_SESSION_SECURE_COOKIE=false' \
    'KOMA_DB_DATABASE=recovered_koma' \
    'KOMA_DB_USERNAME=recovered_koma' \
    "KOMA_DB_PASSWORD=$TARGET_DB_PASSWORD" \
    "KOMA_DB_ROOT_PASSWORD=$TARGET_DB_ROOT_PASSWORD" \
    > "$TARGET_ENV"
chmod 600 "$TARGET_ENV"

printf '%s\n' \
    'services:' \
    '  app:' \
    '    image: komaengine:local' \
    '    command: ["web"]' \
    '    restart: "no"' \
    '    environment: &runtime_environment' \
    '      APP_NAME: KomaEngine' \
    '      APP_ENV: production' \
    '      APP_KEY: ${KOMA_APP_KEY:?Set KOMA_APP_KEY}' \
    '      APP_DEBUG: "false"' \
    '      APP_URL: ${KOMA_APP_URL:?Set KOMA_APP_URL}' \
    '      LOG_CHANNEL: stderr' \
    '      LOG_LEVEL: info' \
    '      DB_CONNECTION: mariadb' \
    '      DB_HOST: db' \
    '      DB_PORT: 3306' \
    '      DB_DATABASE: ${KOMA_DB_DATABASE:?Set KOMA_DB_DATABASE}' \
    '      DB_USERNAME: ${KOMA_DB_USERNAME:?Set KOMA_DB_USERNAME}' \
    '      DB_PASSWORD: ${KOMA_DB_PASSWORD:?Set KOMA_DB_PASSWORD}' \
    '      SESSION_DRIVER: file' \
    '      SESSION_SECURE_COOKIE: ${KOMA_SESSION_SECURE_COOKIE:-false}' \
    '      CACHE_STORE: file' \
    '      QUEUE_CONNECTION: database' \
    '      DB_QUEUE_RETRY_AFTER: 3660' \
    '      QUEUE_WORKER_TIMEOUT: 3600' \
    '      FILESYSTEM_DISK: public' \
    '      MEDIA_DISK_DRIVER: local' \
    '      INSTALLER_ENVIRONMENT_MODE: process' \
    '      INSTALLER_WRITE_ROBOTS: "false"' \
    '      MAIL_MAILER: log' \
    '    volumes:' \
    '      - koma-storage:/var/www/html/storage' \
    '    depends_on:' \
    '      db:' \
    '        condition: service_healthy' \
    '    ports:' \
    '      - "${KOMA_APP_PORT:?Set KOMA_APP_PORT}:80"' \
    '    healthcheck:' \
    '      test: ["CMD", "gosu", "www-data", "php", "artisan", "koma:doctor", "--mode=bootstrap", "--json"]' \
    '      interval: 10s' \
    '      timeout: 8s' \
    '      retries: 12' \
    '      start_period: 20s' \
    '  worker:' \
    '    image: komaengine:local' \
    '    command: ["worker"]' \
    '    restart: "no"' \
    '    environment: *runtime_environment' \
    '    volumes:' \
    '      - koma-storage:/var/www/html/storage' \
    '    depends_on:' \
    '      db:' \
    '        condition: service_healthy' \
    '    stop_grace_period: 61m' \
    '  scheduler:' \
    '    image: komaengine:local' \
    '    command: ["scheduler"]' \
    '    restart: "no"' \
    '    environment: *runtime_environment' \
    '    volumes:' \
    '      - koma-storage:/var/www/html/storage' \
    '    depends_on:' \
    '      db:' \
    '        condition: service_healthy' \
    '  db:' \
    "    image: $KOMA_MARIADB_IMAGE" \
    '    restart: "no"' \
    '    environment:' \
    '      MARIADB_DATABASE: ${KOMA_DB_DATABASE:?Set KOMA_DB_DATABASE}' \
    '      MARIADB_USER: ${KOMA_DB_USERNAME:?Set KOMA_DB_USERNAME}' \
    '      MARIADB_PASSWORD: ${KOMA_DB_PASSWORD:?Set KOMA_DB_PASSWORD}' \
    '      MARIADB_ROOT_PASSWORD: ${KOMA_DB_ROOT_PASSWORD:?Set KOMA_DB_ROOT_PASSWORD}' \
    '    volumes:' \
    '      - koma-db:/var/lib/mysql' \
    '    healthcheck:' \
    '      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]' \
    '      interval: 10s' \
    '      timeout: 5s' \
    '      retries: 12' \
    'volumes:' \
    '  koma-storage:' \
    '  koma-db:' \
    > "$TARGET_COMPOSE"

STEP='checking the live project is outside the disposable labels'
authoritative_before="$(docker volume ls --filter label=com.docker.compose.project=komaengine --format '{{.Name}}' | LC_ALL=C sort | shasum -a 256 | awk '{print $1}')"

STEP='building and installing the disposable source Compose site'
source_compose up -d --build --wait >/dev/null
STEP='locating the disposable source application'
SOURCE_APP="$(source_compose ps -q app)"
test -n "$SOURCE_APP"
STEP='copying source recovery fixture helpers'
copy_support "$SOURCE_APP" install-database.php
copy_support "$SOURCE_APP" create-recovery-fixture.php
copy_support "$SOURCE_APP" create-recovery-queue-archive.php
copy_support "$SOURCE_APP" verify-recovery-fixture.php
STEP='installing the disposable source database'
source_compose exec -T app gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/install-database.php >/dev/null
STEP='creating the durable source recovery fixture'
source_compose exec -T app gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/create-recovery-fixture.php >/dev/null
STEP='creating the retained source queue archive'
source_compose exec -T app gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/create-recovery-queue-archive.php >/dev/null
STEP='capturing the source recovery identity'
source_compose exec -T app gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/verify-recovery-fixture.php > "$SOURCE_STATE"

STEP='quiescing source workers and scheduler before capture'
source_compose stop worker scheduler >/dev/null
test "$(source_compose ps --status running -q worker)" = ''
test "$(source_compose ps --status running -q scheduler)" = ''

STEP='creating and independently verifying the MariaDB backup'
source_compose exec -T --user root app sh -lc 'umask 077; install -d -o www-data -g www-data -m 0700 /tmp/koma-site-backups /tmp/koma-site-restores; openssl rand -out /tmp/koma-operator-secrets.age 48; chown www-data:www-data /tmp/koma-operator-secrets.age; chmod 600 /tmp/koma-operator-secrets.age'
source_compose exec -T app gosu www-data php artisan koma:site-backup:create "$BACKUP_PATH" --secrets-bundle=/tmp/koma-operator-secrets.age
source_compose exec -T app gosu www-data php artisan koma:site-backup:verify "$BACKUP_PATH"
STEP='checking the independently verified backup inventory'
source_compose exec -T app test -f "$BACKUP_PATH/database/database.sql"
source_compose exec -T app test -f "$BACKUP_PATH/storage/private/archive-imports/source/recovery-runtime.koma.zip"

STEP='damaging only the disposable source database and storage after backup'
source_compose exec -T app sh -lc 'rm -rf /var/www/html/storage/app/media/recovery /var/www/html/storage/app/private/recovery /var/www/html/storage/app/private/archive-imports/source'
source_compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="DELETE FROM pages; DELETE FROM chapters; DELETE FROM series;"' >/dev/null
if source_compose exec -T app test -e /var/www/html/storage/app/media/recovery/reader.txt; then
    echo 'The disposable source media was not damaged after backup.' >&2
    exit 1
fi
test "$(source_compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --batch --skip-column-names --execute="SELECT COUNT(*) FROM series"')" = '0'

STEP='provisioning the separate empty target Compose database and volume'
target_compose up -d db --wait >/dev/null
TARGET_DB="$(target_compose ps -q db)"
test -n "$TARGET_DB"
TARGET_DB_NAME="$(docker inspect --format '{{.Name}}' "$TARGET_DB" | sed 's#^/##')"
target_compose create app >/dev/null
TARGET_STORAGE_VOLUME="${TARGET_PROJECT}_koma-storage"
docker volume inspect "$TARGET_STORAGE_VOLUME" >/dev/null
test "$(target_compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --batch --skip-column-names --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"')" = '0'

printf '%s\n' \
    '[client]' \
    "host=$TARGET_DB_NAME" \
    'port=3306' \
    'user=recovered_koma' \
    "password=$TARGET_DB_PASSWORD" \
    > "$TARGET_CREDENTIALS"
chmod 600 "$TARGET_CREDENTIALS"
docker cp "$TARGET_CREDENTIALS" "$SOURCE_APP:/tmp/koma-recovery-target.cnf"
source_compose exec -T --user root app sh -lc 'chown www-data:www-data /tmp/koma-recovery-target.cnf && chmod 600 /tmp/koma-recovery-target.cnf'
docker network connect "${TARGET_PROJECT}_default" "$SOURCE_APP"

STEP='restoring the verified backup into the separate target database and filesystem stage'
source_compose exec -T app gosu www-data php artisan koma:site-backup:restore "$BACKUP_PATH" \
    --target-root="$RESTORE_PATH" \
    --database-credentials=/tmp/koma-recovery-target.cnf \
    --database-target=recovered_koma \
    --force >/dev/null
source_compose exec -T app test -f "$RESTORE_PATH/storage/app/media/recovery/reader.txt"
source_compose exec -T app test -f "$RESTORE_PATH/storage/app/private/recovery/input.txt"
source_compose exec -T app test -f "$RESTORE_PATH/storage/app/private/archive-imports/source/recovery-runtime.koma.zip"
mkdir -p "$RESTORED_ROOT"
docker cp "$SOURCE_APP:${RESTORE_PATH}/." "$RESTORED_ROOT"
test -d "$RESTORED_ROOT/storage"
test -f "$RESTORED_ROOT/storage/app/media/recovery/reader.txt"
test -f "$RESTORED_ROOT/storage/app/private/recovery/input.txt"
docker run --rm --entrypoint sh \
    --volume "$TARGET_STORAGE_VOLUME:/target" \
    --volume "$RESTORED_ROOT/storage:/restored:ro" \
    komaengine:local -lc 'cp -a /restored/. /target/ && chown -R www-data:www-data /target'
docker run --rm --entrypoint sh \
    --volume "$TARGET_STORAGE_VOLUME:/target" \
    komaengine:local -lc 'test -f /target/app/media/recovery/reader.txt && test -f /target/app/private/recovery/input.txt'

STEP='starting the recovered application without migrations'
target_compose up -d app --wait >/dev/null
TARGET_APP="$(target_compose ps -q app)"
test -n "$TARGET_APP"
copy_support "$TARGET_APP" verify-recovery-fixture.php
copy_support "$TARGET_APP" exercise-recovery-compose-runtime.php
target_compose exec -T app gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/verify-recovery-fixture.php > "$TARGET_STATE"
cmp "$SOURCE_STATE" "$TARGET_STATE"
target_compose exec -T app gosu www-data php artisan koma:doctor --mode=operational --json >/dev/null

STEP='checking recovered HTTP catalog reader media and administrator route'
for attempt in $(seq 1 30); do
    if curl --fail --silent --show-error "http://127.0.0.1:$TARGET_PORT/" | grep -Fq 'Recovery Fixture'; then
        break
    fi
    if [[ "$attempt" -eq 30 ]]; then
        echo 'Recovered Compose application did not serve its catalog.' >&2
        exit 1
    fi
    sleep 1
done
curl --fail --silent --show-error "http://127.0.0.1:$TARGET_PORT/series/recovery-fixture" | grep -Fq 'Recovery Fixture'
curl --fail --silent --show-error "http://127.0.0.1:$TARGET_PORT/series/recovery-fixture/chapter/1" | grep -Fq 'Recovered chapter'
curl --fail --silent --show-error "http://127.0.0.1:$TARGET_PORT/media/series/recovery-fixture/chapters/1/pages/1" | grep -Fq 'reader-media'
test "$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:$TARGET_PORT/admin")" = '302'

STEP='dispatching recovered queue and scheduler work through target Compose services'
target_compose exec -T app env RECOVERY_COMPOSE_ACTION=dispatch gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/exercise-recovery-compose-runtime.php >/dev/null
target_compose up -d worker scheduler >/dev/null
for attempt in $(seq 1 90); do
    runtime_state="$(target_compose exec -T app env RECOVERY_COMPOSE_ACTION=status gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/exercise-recovery-compose-runtime.php)"
    if grep -Fq '"import_status":"completed"' <<< "$runtime_state" \
        && grep -Fq '"import_chapters":1' <<< "$runtime_state" \
        && grep -Fq '"import_pages":1' <<< "$runtime_state" \
        && grep -Fq '"scheduled_chapter_status":"published"' <<< "$runtime_state"; then
        break
    fi
    if [[ "$attempt" -eq 90 ]]; then
        printf 'Recovered Compose worker or scheduler did not complete its proof: %s\n' "$runtime_state" >&2
        exit 1
    fi
    sleep 1
done
target_compose exec -T app gosu www-data php artisan koma:doctor --mode=operational --json >/dev/null
target_compose restart app >/dev/null
target_compose up -d --wait app >/dev/null
target_compose exec -T app gosu www-data env KOMA_APP_ROOT=/var/www/html php /tmp/verify-recovery-fixture.php > "$TEMP_ROOT/restarted-state.json"
cmp "$SOURCE_STATE" "$TEMP_ROOT/restarted-state.json"

STEP='tearing down only the disposable Compose projects'
source_compose down --volumes --remove-orphans >/dev/null
target_compose down --volumes --remove-orphans >/dev/null
require_no_project_resources "$SOURCE_PROJECT"
require_no_project_resources "$TARGET_PROJECT"
CLEANED=true

STEP='checking the live project volume set remains untouched'
authoritative_after="$(docker volume ls --filter label=com.docker.compose.project=komaengine --format '{{.Name}}' | LC_ALL=C sort | shasum -a 256 | awk '{print $1}')"
test "$authoritative_before" = "$authoritative_after"

printf 'Disposable Compose MariaDB backup/restore recovery smoke passed.\n'
