#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-backup-restore-sqlite.XXXXXX")"
TEST_IMAGE="komaengine-recovery-test:local"
WEB_CONTAINER="koma-backup-restore-sqlite-web-$RANDOM-$$"
SOURCE_DATABASE="$TEMP_ROOT/source/source.sqlite"
SOURCE_STORAGE="$TEMP_ROOT/source/storage"
SOURCE_CACHE="$TEMP_ROOT/source/bootstrap-cache"
BACKUP="$TEMP_ROOT/backup/sqlite"
TARGET_ROOT="$TEMP_ROOT/recovered-site"
TARGET_APP="$TEMP_ROOT/recovered-app"
TARGET_CACHE="$TEMP_ROOT/recovered-bootstrap-cache"
BUNDLE="$TEMP_ROOT/operator-secrets.age"
SOURCE_STATE="$TEMP_ROOT/source-state.json"
TARGET_STATE="$TEMP_ROOT/target-state.json"
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
DOCKER_USER="$(id -u):$(id -g)"
CONTAINER_TEMP_ROOT="/tmp/koma-recovery"
STEP='initialization'

cleanup() {
    docker rm -f "$WEB_CONTAINER" >/dev/null 2>&1 || true
    docker image rm -f "$TEST_IMAGE" >/dev/null 2>&1 || true
    rm -rf -- "$TEMP_ROOT"
}
on_exit() {
    status=$?
    cleanup

    if [[ "$status" -ne 0 ]]; then
        printf 'Disposable SQLite backup/restore recovery smoke failed during %s.\n' "$STEP" >&2
    fi

    exit "$status"
}
trap on_exit EXIT

hash_file() {
    shasum -a 256 "$1" | awk '{print $1}'
}

assert_no_restore_stage() {
    if find "$TEMP_ROOT" -name '.*.restore-staging-*' -print -quit | grep -q .; then
        echo 'SQLite recovery left an unpublished restore staging directory.' >&2
        exit 1
    fi
}

source_container() {
    docker run --rm \
        --user "$DOCKER_USER" \
        --env HOME="$CONTAINER_TEMP_ROOT/home" \
        --env APP_ENV=testing \
        --env APP_DEBUG=false \
        --env APP_KEY="$APP_KEY_VALUE" \
        --env APP_URL=http://recovery-source.test \
        --env LOG_CHANNEL=stderr \
        --env DB_CONNECTION=sqlite \
        --env DB_DATABASE="$CONTAINER_TEMP_ROOT/source/source.sqlite" \
        --env CACHE_STORE=array \
        --env SESSION_DRIVER=array \
        --env QUEUE_CONNECTION=sync \
        --env INSTALLER_ENVIRONMENT_MODE=process \
        --env INSTALLER_WRITE_ENV=false \
        --env INSTALLER_WRITE_ROBOTS=false \
        --env MEDIA_DISK_DRIVER=local \
        --env KOMA_APP_ROOT=/app \
        --volume "$ROOT:/app:ro" \
        --volume "$TEMP_ROOT:$CONTAINER_TEMP_ROOT" \
        --volume "$SOURCE_STORAGE:/app/storage" \
        --volume "$SOURCE_CACHE:/app/bootstrap/cache" \
        --workdir /app \
        "$TEST_IMAGE" "$@"
}

target_container() {
    docker run --rm \
        --user "$DOCKER_USER" \
        --env HOME="$CONTAINER_TEMP_ROOT/home" \
        --env APP_ENV=production \
        --env APP_DEBUG=false \
        --env APP_KEY="$APP_KEY_VALUE" \
        --env APP_URL=http://recovered.koma.test \
        --env LOG_CHANNEL=stderr \
        --env DB_CONNECTION=sqlite \
        --env DB_DATABASE="$CONTAINER_TEMP_ROOT/recovered-site/database/database.sqlite" \
        --env CACHE_STORE=array \
        --env SESSION_DRIVER=array \
        --env QUEUE_CONNECTION=database \
        --env FILESYSTEM_DISK=public \
        --env MEDIA_DISK_DRIVER=local \
        --env KOMA_APP_ROOT=/app \
        --volume "$TARGET_APP:/app:ro" \
        --volume "$TEMP_ROOT:$CONTAINER_TEMP_ROOT" \
        --volume "$TARGET_ROOT/storage:/app/storage" \
        --volume "$TARGET_CACHE:/app/bootstrap/cache" \
        --volume "$ROOT/tests/Release/support/verify-recovery-fixture.php:/tmp/koma-recovery-verify-recovery-fixture.php:ro" \
        --volume "$ROOT/tests/Release/support/exercise-recovery-runtime.php:/tmp/koma-recovery-exercise-recovery-runtime.php:ro" \
        --workdir /app \
        "$TEST_IMAGE" "$@"
}

mkdir -p \
    "$TEMP_ROOT/source" \
    "$SOURCE_STORAGE/app/media" \
    "$SOURCE_STORAGE/app/private" \
    "$SOURCE_STORAGE/framework/cache" \
    "$SOURCE_STORAGE/framework/sessions" \
    "$SOURCE_STORAGE/framework/views" \
    "$SOURCE_STORAGE/logs" \
    "$SOURCE_CACHE" \
    "$TARGET_CACHE" \
    "$TEMP_ROOT/backup" \
    "$TEMP_ROOT/home"
install -m 0600 /dev/null "$SOURCE_DATABASE"
openssl rand -out "$BUNDLE" 48
chmod 600 "$BUNDLE"

STEP='checking authoritative volume identities'
before_db_volume="$(docker volume inspect --format '{{.Name}} {{.CreatedAt}}' komaengine_koma-db)"
before_storage_volume="$(docker volume inspect --format '{{.Name}} {{.CreatedAt}}' komaengine_koma-storage)"

STEP='building the disposable PHP test image'
docker build \
    --quiet \
    --file "$ROOT/tests/Release/php-matrix.Dockerfile" \
    --build-arg "PHP_IMAGE=$KOMA_PHP_84_CLI_IMAGE" \
    --tag "$TEST_IMAGE" \
    "$ROOT/tests/Release" >/dev/null

STEP='installing the disposable SQLite source'
source_container php tests/Release/support/install-sqlite.php >/dev/null
STEP='creating the durable source fixture'
source_container php tests/Release/support/create-recovery-fixture.php
STEP='capturing the source identity'
source_container php tests/Release/support/verify-recovery-fixture.php > "$SOURCE_STATE"

source_database_hash="$(hash_file "$SOURCE_DATABASE")"
source_media_hash="$(hash_file "$SOURCE_STORAGE/app/media/recovery/reader.txt")"
source_private_hash="$(hash_file "$SOURCE_STORAGE/app/private/recovery/input.txt")"

STEP='creating the SQLite backup'
source_container php artisan koma:site-backup:create "$CONTAINER_TEMP_ROOT/backup/sqlite" \
    --secrets-bundle="$CONTAINER_TEMP_ROOT/operator-secrets.age" >/dev/null
STEP='independently verifying the SQLite backup'
source_container php artisan koma:site-backup:verify "$CONTAINER_TEMP_ROOT/backup/sqlite" >/dev/null

STEP='refusing an invalid restore backup'
if source_container php artisan koma:site-backup:restore "$CONTAINER_TEMP_ROOT/backup/missing" \
    --target-root="$CONTAINER_TEMP_ROOT/missing-backup-target" --force >/dev/null 2>&1; then
    echo 'SQLite recovery accepted a missing backup.' >&2
    exit 1
fi
test ! -e "$TEMP_ROOT/missing-backup-target"
assert_no_restore_stage

STEP='refusing an application-tree restore target'
if source_container php artisan koma:site-backup:restore "$CONTAINER_TEMP_ROOT/backup/sqlite" \
    --target-root=/app/unsafe-recovery-target --force >/dev/null 2>&1; then
    echo 'SQLite recovery accepted an application-tree target.' >&2
    exit 1
fi
test ! -e "$ROOT/unsafe-recovery-target"
assert_no_restore_stage

STEP='damaging only the disposable target'
mkdir -p "$TARGET_ROOT/database" "$TARGET_ROOT/storage/app/media/recovery"
cp "$SOURCE_DATABASE" "$TARGET_ROOT/database/database.sqlite"
printf 'damaged-target-only' > "$TARGET_ROOT/storage/app/media/recovery/reader.txt"
test "$(hash_file "$TARGET_ROOT/storage/app/media/recovery/reader.txt")" != "$source_media_hash"
test "$(hash_file "$SOURCE_DATABASE")" = "$source_database_hash"
test "$(hash_file "$SOURCE_STORAGE/app/media/recovery/reader.txt")" = "$source_media_hash"
test "$(hash_file "$SOURCE_STORAGE/app/private/recovery/input.txt")" = "$source_private_hash"
rm -rf -- "$TARGET_ROOT"

STEP='restoring the verified SQLite backup'
source_container php artisan koma:site-backup:restore "$CONTAINER_TEMP_ROOT/backup/sqlite" \
    --target-root="$CONTAINER_TEMP_ROOT/recovered-site" --force >/dev/null

test -f "$TARGET_ROOT/database/database.sqlite"
test -f "$TARGET_ROOT/storage/app/media/recovery/reader.txt"
test -f "$TARGET_ROOT/storage/app/private/recovery/input.txt"
test -L "$TARGET_ROOT/public/storage"
test "$(readlink "$TARGET_ROOT/public/storage")" = '../storage/app/public'
test "$(hash_file "$BACKUP/database/database.sqlite")" = "$(hash_file "$TARGET_ROOT/database/database.sqlite")"
cmp "$SOURCE_STORAGE/app/media/recovery/reader.txt" "$TARGET_ROOT/storage/app/media/recovery/reader.txt"
cmp "$SOURCE_STORAGE/app/private/recovery/input.txt" "$TARGET_ROOT/storage/app/private/recovery/input.txt"
cmp "$BUNDLE" "$TARGET_ROOT/secrets/operator-secrets.age"
assert_no_restore_stage

STEP='provisioning the separate recovered application'
mkdir -p "$TARGET_APP"
(
    cd "$ROOT"
    tar -cf - app bootstrap config database/migrations public resources routes themes plugins vendor VERSION artisan composer.json composer.lock
) | tar -C "$TARGET_APP" -xf -
rm -f "$TARGET_APP/public/storage"
mkdir -p "$TARGET_APP/storage"
ln -s ../storage/app/public "$TARGET_APP/public/storage"
cmp "$TARGET_ROOT/release/composer.lock" "$TARGET_APP/composer.lock"

STEP='checking recovered durable identity'
target_container php /tmp/koma-recovery-verify-recovery-fixture.php > "$TARGET_STATE"
cmp "$SOURCE_STATE" "$TARGET_STATE"
STEP='checking recovered operational doctor'
target_container php artisan koma:doctor --mode=operational --json >/dev/null

STEP='starting recovered HTTP application'
docker run -d --rm \
    --name "$WEB_CONTAINER" \
    --user "$DOCKER_USER" \
    --env HOME="$CONTAINER_TEMP_ROOT/home" \
    --env APP_ENV=production \
    --env APP_DEBUG=false \
    --env APP_KEY="$APP_KEY_VALUE" \
    --env APP_URL=http://recovered.koma.test \
    --env LOG_CHANNEL=stderr \
    --env DB_CONNECTION=sqlite \
    --env DB_DATABASE="$CONTAINER_TEMP_ROOT/recovered-site/database/database.sqlite" \
    --env CACHE_STORE=array \
    --env SESSION_DRIVER=array \
    --env QUEUE_CONNECTION=database \
    --env FILESYSTEM_DISK=public \
    --env MEDIA_DISK_DRIVER=local \
    --env KOMA_APP_ROOT=/app \
    --volume "$TARGET_APP:/app:ro" \
    --volume "$TEMP_ROOT:$CONTAINER_TEMP_ROOT" \
    --volume "$TARGET_ROOT/storage:/app/storage" \
    --volume "$TARGET_CACHE:/app/bootstrap/cache" \
    --volume "$ROOT/tests/Release/support/recovery-router.php:/tmp/recovery-router.php:ro" \
    --workdir /app \
    "$TEST_IMAGE" sh -lc 'exec php -S 127.0.0.1:8080 -t public /tmp/recovery-router.php' >/dev/null

STEP='waiting for recovered HTTP application'
for attempt in $(seq 1 20); do
    if docker exec "$WEB_CONTAINER" php -r 'exit(@file_get_contents("http://127.0.0.1:8080/") === false ? 1 : 0);' >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 20 ]]; then
        echo 'Recovered SQLite application did not serve HTTP.' >&2
        exit 1
    fi
    sleep 1
done

STEP='checking recovered HTTP routes and media'
docker exec "$WEB_CONTAINER" php -r '
    $context = stream_context_create(["http" => ["ignore_errors" => true, "max_redirects" => 0]]);
    $check = function (string $path, string $status, ?string $needle = null) use ($context): void {
        $body = file_get_contents("http://127.0.0.1:8080".$path, false, $context);
        $actual = $http_response_header[0] ?? "";
        if (! str_contains($actual, $status) || ($needle !== null && ! str_contains((string) $body, $needle))) {
            fwrite(STDERR, "Recovered SQLite HTTP check failed for {$path}.\n");
            exit(1);
        }
    };
    $check("/", "200", "Recovery Fixture");
    $check("/series/recovery-fixture", "200", "Recovery Fixture");
    $check("/series/recovery-fixture/chapter/1", "200", "Recovered chapter");
    $check("/media/series/recovery-fixture/chapters/1/pages/1", "200", "reader-media");
    $check("/admin", "302");
'

STEP='executing the recovered queue probe'
target_container env RECOVERY_RUNTIME_ACTION=queue php /tmp/koma-recovery-exercise-recovery-runtime.php >/dev/null
STEP='executing the recovered scheduler probe'
target_container env RECOVERY_RUNTIME_ACTION=scheduler php /tmp/koma-recovery-exercise-recovery-runtime.php >/dev/null
STEP='rechecking recovered operational doctor'
target_container php artisan koma:doctor --mode=operational --json >/dev/null

STEP='checking authoritative volume identities after the drill'
after_db_volume="$(docker volume inspect --format '{{.Name}} {{.CreatedAt}}' komaengine_koma-db)"
after_storage_volume="$(docker volume inspect --format '{{.Name}} {{.CreatedAt}}' komaengine_koma-storage)"
test "$before_db_volume" = "$after_db_volume"
test "$before_storage_volume" = "$after_storage_volume"

STEP='removing recovered HTTP application'
docker rm -f "$WEB_CONTAINER" >/dev/null
test -z "$(docker ps -aq --filter "name=^/${WEB_CONTAINER}$")"

printf 'Disposable SQLite backup/restore recovery smoke passed.\n'
