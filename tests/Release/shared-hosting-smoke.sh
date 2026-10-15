#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CURRENT_REF="${KOMA_TEST_SOURCE_COMMIT:-$(git -C "$ROOT" rev-parse HEAD)}"
PREVIOUS_REF="${KOMA_TEST_PREVIOUS_COMMIT:-$(git -C "$ROOT" rev-parse "${CURRENT_REF}^")}"
EVIDENCE_PATH=''
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-shared-host.XXXXXX")"
RUN_ID="$(openssl rand -hex 6)"
IMAGE="koma-shared-host-${RUN_ID}:local"
CONTAINER_PREFIX="koma-shared-host-${RUN_ID}"
DOCKER_USER="$(id -u):$(id -g)"
STEP='initialization'
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
STARTED_SECONDS=$SECONDS
WEB_CONTAINERS=()

usage() {
    cat <<'EOF'
Usage: tests/Release/shared-hosting-smoke.sh [--current-ref HASH] [--previous-ref HASH] [--evidence PATH]

Builds exact-ref flat-file ZIPs, installs a clean extracted package, upgrades an
existing extracted package after a verified backup, and performs an exact-code
rollback. This is Docker machine evidence, not real shared-host acceptance.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --current-ref)
            CURRENT_REF="${2:-}"
            shift 2
            ;;
        --previous-ref)
            PREVIOUS_REF="${2:-}"
            shift 2
            ;;
        --evidence)
            EVIDENCE_PATH="${2:-}"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown shared-hosting smoke argument: %s\n' "$1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

cleanup() {
    local name

    for name in "${WEB_CONTAINERS[@]:-}"; do
        [[ -n "$name" ]] && docker container rm -f "$name" >/dev/null 2>&1 || true
    done
    docker image rm -f "$IMAGE" >/dev/null 2>&1 || true
    rm -rf -- "$TEMP_ROOT"
}

on_exit() {
    local status=$?

    cleanup
    if [[ "$status" -ne 0 ]]; then
        printf 'Shared-hosting artifact smoke failed during %s.\n' "$STEP" >&2
    fi
    exit "$status"
}
trap on_exit EXIT

full_commit() {
    local ref="$1"

    [[ "$ref" =~ ^[0-9a-f]{40}$ ]] || return 1
    [[ "$(git -C "$ROOT" rev-parse --verify "${ref}^{commit}" 2>/dev/null)" == "$ref" ]]
}

hash_file() {
    shasum -a 256 "$1" | awk '{print $1}'
}

maintainer_volume_fingerprint() {
    docker volume inspect \
        --format '{{.Name}} {{.CreatedAt}}' \
        komaengine_koma-db komaengine_koma-storage \
        | LC_ALL=C sort \
        | shasum -a 256 \
        | awk '{print $1}'
}

runtime() {
    local app="$1"
    shift

    docker run --rm \
        --user "$DOCKER_USER" \
        --env HOME=/harness/home \
        --env APP_ENV=production \
        --env APP_DEBUG=false \
        --env APP_URL=http://shared-host.test \
        --env LOG_CHANNEL=stderr \
        --env DB_CONNECTION=sqlite \
        --env DB_DATABASE=/app/database/database.sqlite \
        --env CACHE_STORE=file \
        --env SESSION_DRIVER=file \
        --env QUEUE_CONNECTION=database \
        --env FILESYSTEM_DISK=public \
        --env MEDIA_DISK_DRIVER=local \
        --env KOMA_APP_ROOT=/app \
        --volume "$app:/app" \
        --volume "$ROOT/tests/Release/support:/support:ro" \
        --volume "$TEMP_ROOT:/harness" \
        --workdir /app \
        "$IMAGE" "$@"
}

build_package() {
    local label="$1"
    local ref="$2"
    local output="$TEMP_ROOT/build-$label"
    local log="$TEMP_ROOT/build-$label.log"
    local archive
    local version

    mkdir -p "$output/home"
    mkdir -p "$TEMP_ROOT/cache/composer" "$TEMP_ROOT/cache/npm"
    docker run --rm \
        --user "$DOCKER_USER" \
        --env HOME="/output/home" \
        --env COMPOSER_CACHE_DIR=/cache/composer \
        --env npm_config_cache=/cache/npm \
        --env KOMA_SOURCE_REF="$ref" \
        --env GIT_CONFIG_COUNT=1 \
        --env GIT_CONFIG_KEY_0=safe.directory \
        --env GIT_CONFIG_VALUE_0=/workspace \
        --volume "$ROOT:/workspace:ro" \
        --volume "$output:/output" \
        --volume "$TEMP_ROOT/cache:/cache" \
        --workdir /workspace \
        "$IMAGE" \
        bash -lc 'scripts/build-flatfile.sh /output' | tee "$log"

    archive="$output/$(basename "$(sed -n 's/^ARCHIVE=//p' "$log" | tail -n 1)")"
    version="$(git -C "$ROOT" show "$ref:VERSION" | tr -d '\r\n')"
    test -f "$archive"
    test "$(sed -n 's/^SOURCE_COMMIT=//p' "$log" | tail -n 1)" = "$ref"

    docker run --rm \
        --env KOMA_EXPECTED_SOURCE_COMMIT="$ref" \
        --env KOMA_EXPECTED_RELEASE_VERSION="$version" \
        --volume "$ROOT:/workspace:ro" \
        --volume "$output:/output:ro" \
        --workdir /workspace \
        "$IMAGE" \
        bash -lc "tests/Release/inspect-artifact.sh '/output/$(basename "$archive")'"

    printf '%s\n' "$archive" > "$TEMP_ROOT/$label-archive.path"
    hash_file "$archive" > "$TEMP_ROOT/$label-archive.sha256"
}

extract_package() {
    local archive="$1"
    local destination="$2"
    local parent="$(dirname "$destination")"
    local extracted="$parent/komaengine"

    mkdir -p "$parent"
    unzip -qq "$archive" -d "$parent"
    test -d "$extracted"
    if [[ "$extracted" != "$destination" ]]; then
        mv "$extracted" "$destination"
    fi
    if find "$destination" -type l -print -quit | grep -q .; then
        echo 'Freshly extracted package unexpectedly contains a symbolic link.' >&2
        return 1
    fi
}

prepare_environment() {
    local app="$1"

    cp "$app/.env.example" "$app/.env"
    runtime "$app" sh -lc '
        sed -i \
            -e "s#^APP_URL=.*#APP_URL=http://shared-host.test#" \
            -e "s#^DB_CONNECTION=.*#DB_CONNECTION=sqlite#" \
            -e "s#^DB_HOST=.*#DB_HOST=127.0.0.1#" \
            -e "s#^DB_PORT=.*#DB_PORT=3306#" \
            -e "s#^DB_DATABASE=.*#DB_DATABASE=/app/database/database.sqlite#" \
            -e "s#^DB_USERNAME=.*#DB_USERNAME=#" \
            -e "s#^DB_PASSWORD=.*#DB_PASSWORD=#" \
            .env
        chmod 600 .env
        mkdir -p database storage/app/public bootstrap/cache
        touch database/database.sqlite
        chmod 600 database/database.sqlite
        php artisan key:generate --force --no-interaction >/dev/null
        php artisan storage:link --force --no-interaction >/dev/null
    '
    assert_environment "$app"
    assert_storage_link "$app"
}

assert_environment() {
    local app="$1"

    test "$(runtime "$app" stat -c '%a' .env)" = 600
    runtime "$app" php -r '
        $contents = file_get_contents(".env");
        if (! preg_match("/^APP_KEY=base64:(.+)$/m", $contents, $matches)
            || strlen((string) base64_decode(trim($matches[1]), true)) !== 32) {
            fwrite(STDERR, "The extracted package does not have a valid generated application key.\n");
            exit(1);
        }
    '
}

assert_storage_link() {
    local app="$1"

    runtime "$app" php -r '
        $link = realpath("public/storage");
        $target = realpath("storage/app/public");
        if (! is_link("public/storage") || $link === false || $link !== $target) {
            fwrite(STDERR, "public/storage must resolve exactly to storage/app/public.\n");
            exit(1);
        }
    '
}

install_fixture() {
    local app="$1"

    runtime "$app" php /support/install-database.php >/dev/null
    assert_environment "$app"
    assert_storage_link "$app"
    runtime "$app" php /support/verify-database.php >/dev/null
    runtime "$app" php /support/create-recovery-fixture.php
}

verify_state() {
    local app="$1"
    local destination="$2"

    runtime "$app" php /support/verify-recovery-fixture.php > "$destination"
}

verify_doctor() {
    local app="$1"
    local destination="$2"

    runtime "$app" php artisan koma:doctor --mode=operational --json > "$destination"
    jq -e '.ready == true and ([.checks[]] | all)' "$destination" >/dev/null
}

start_web() {
    local app="$1"
    local stage="$2"
    local name="${CONTAINER_PREFIX}-${stage}-web"

    docker run -d --rm \
        --name "$name" \
        --user "$DOCKER_USER" \
        --env HOME=/harness/home \
        --env APP_ENV=production \
        --env APP_DEBUG=false \
        --env APP_URL=http://shared-host.test \
        --env LOG_CHANNEL=stderr \
        --env DB_CONNECTION=sqlite \
        --env DB_DATABASE=/app/database/database.sqlite \
        --env CACHE_STORE=file \
        --env SESSION_DRIVER=file \
        --env QUEUE_CONNECTION=database \
        --env FILESYSTEM_DISK=public \
        --env MEDIA_DISK_DRIVER=local \
        --env KOMA_APP_ROOT=/app \
        --volume "$app:/app" \
        --volume "$ROOT/tests/Release/support/recovery-router.php:/support/recovery-router.php:ro" \
        --workdir /app \
        "$IMAGE" \
        php -S 127.0.0.1:8080 -t public /support/recovery-router.php >/dev/null
    WEB_CONTAINERS+=("$name")

    for attempt in $(seq 1 30); do
        if docker exec "$name" php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);' >/dev/null 2>&1; then
            printf '%s\n' "$name" > "$TEMP_ROOT/$stage-web.name"
            return 0
        fi
        if [[ "$attempt" -eq 30 ]]; then
            docker logs "$name" >&2 || true
            printf '%s web process did not become ready.\n' "$stage" >&2
            return 1
        fi
        sleep 1
    done
}

assert_installer_http() {
    local name="$1"

    # A fresh, uninstalled site must open on the setup-token gate and show
    # no installer step until that token is supplied.
    docker exec "$name" php -r '
        $body = (string) file_get_contents("http://127.0.0.1:8080/install");
        if (! str_contains($body, "Authorize this installer") || str_contains($body, "Choose your language")) {
            fwrite(STDERR, "The clean extracted installer did not open on its setup-token gate.\n");
            exit(1);
        }
    '
}

assert_content_http() {
    local name="$1"

    docker exec "$name" php -r '
        $context = stream_context_create(["http" => ["ignore_errors" => true, "max_redirects" => 0]]);
        $check = function (string $path, string $status, ?string $needle = null) use ($context): void {
            $body = file_get_contents("http://127.0.0.1:8080".$path, false, $context);
            $actual = $http_response_header[0] ?? "";
            if (! str_contains($actual, $status) || ($needle !== null && ! str_contains((string) $body, $needle))) {
                fwrite(STDERR, "Shared-host HTTP check failed for {$path}.\n");
                exit(1);
            }
        };
        $check("/", "200", "Recovery Fixture");
        $check("/series/recovery-fixture", "200", "Recovery Fixture");
        $check("/series/recovery-fixture/chapter/1", "200", "Recovered chapter");
        $check("/media/series/recovery-fixture/chapters/1/pages/1", "200", "reader-media");
        $check("/admin", "302");
    '
}

restart_web() {
    local name="$1"

    docker restart "$name" >/dev/null
    for attempt in $(seq 1 30); do
        if docker exec "$name" php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);' >/dev/null 2>&1; then
            assert_content_http "$name"
            return 0
        fi
        if [[ "$attempt" -eq 30 ]]; then
            docker logs "$name" >&2 || true
            echo 'Web process did not recover after restart.' >&2
            return 1
        fi
        sleep 1
    done
}

stop_web() {
    local name="$1"

    docker container rm -f "$name" >/dev/null
}

exercise_background_work() {
    local app="$1"

    runtime "$app" env RECOVERY_RUNTIME_ACTION=queue php /support/exercise-recovery-runtime.php >/dev/null
    runtime "$app" env RECOVERY_RUNTIME_ACTION=scheduler php /support/exercise-recovery-runtime.php >/dev/null
}

STEP='validating immutable revisions'
full_commit "$CURRENT_REF" || {
    echo 'Current ref must be an available full lowercase Git commit.' >&2
    exit 2
}
full_commit "$PREVIOUS_REF" || {
    echo 'Previous ref must be an available full lowercase Git commit.' >&2
    exit 2
}
test "$CURRENT_REF" != "$PREVIOUS_REF"
git -C "$ROOT" merge-base --is-ancestor "$PREVIOUS_REF" "$CURRENT_REF"

BEFORE_STATUS="$(git -C "$ROOT" status --porcelain=v1 -uall | shasum -a 256 | awk '{print $1}')"
BEFORE_DIFF="$(git -C "$ROOT" diff --binary | shasum -a 256 | awk '{print $1}')"
BEFORE_VOLUMES="$(maintainer_volume_fingerprint)"

STEP='building shared-host PHP image'
docker build \
    --quiet \
    --file "$ROOT/tests/Release/flatfile-builder.Dockerfile" \
    --tag "$IMAGE" \
    "$ROOT" >/dev/null

STEP='building and inspecting previous exact-ref package'
build_package previous "$PREVIOUS_REF"
STEP='building and inspecting current exact-ref package'
build_package current "$CURRENT_REF"
PREVIOUS_ARCHIVE="$(cat "$TEMP_ROOT/previous-archive.path")"
CURRENT_ARCHIVE="$(cat "$TEMP_ROOT/current-archive.path")"

STEP='extracting packages'
CLEAN_APP="$TEMP_ROOT/clean/app"
SOURCE_APP="$TEMP_ROOT/source/app"
UPGRADE_APP="$TEMP_ROOT/upgrade/app"
ROLLBACK_APP="$TEMP_ROOT/rollback/app"
extract_package "$CURRENT_ARCHIVE" "$CLEAN_APP"
extract_package "$PREVIOUS_ARCHIVE" "$SOURCE_APP"
extract_package "$CURRENT_ARCHIVE" "$UPGRADE_APP"
extract_package "$PREVIOUS_ARCHIVE" "$ROLLBACK_APP"

STEP='preparing clean-install environment and storage link'
prepare_environment "$CLEAN_APP"
STEP='checking clean installer HTTP and document root'
start_web "$CLEAN_APP" clean-installer
CLEAN_INSTALLER_WEB="$(cat "$TEMP_ROOT/clean-installer-web.name")"
assert_installer_http "$CLEAN_INSTALLER_WEB"
stop_web "$CLEAN_INSTALLER_WEB"
STEP='installing clean extracted package'
install_fixture "$CLEAN_APP"
verify_state "$CLEAN_APP" "$TEMP_ROOT/clean-state.json"
verify_doctor "$CLEAN_APP" "$TEMP_ROOT/clean-doctor.json"
STEP='checking clean content and restart'
start_web "$CLEAN_APP" clean
CLEAN_WEB="$(cat "$TEMP_ROOT/clean-web.name")"
assert_content_http "$CLEAN_WEB"
restart_web "$CLEAN_WEB"
stop_web "$CLEAN_WEB"
STEP='checking clean queue and scheduler'
exercise_background_work "$CLEAN_APP"

STEP='installing previous extracted package'
prepare_environment "$SOURCE_APP"
install_fixture "$SOURCE_APP"
verify_state "$SOURCE_APP" "$TEMP_ROOT/source-state.json"
verify_doctor "$SOURCE_APP" "$TEMP_ROOT/source-doctor.json"
SOURCE_DATABASE_SHA="$(hash_file "$SOURCE_APP/database/database.sqlite")"

STEP='creating and verifying pre-upgrade backup'
openssl rand -out "$TEMP_ROOT/operator-secrets.age" 48
chmod 600 "$TEMP_ROOT/operator-secrets.age"
mkdir -p "$TEMP_ROOT/backups"
if ! runtime "$SOURCE_APP" php artisan koma:site-backup:create /harness/backups/pre-upgrade \
    --secrets-bundle=/harness/operator-secrets.age > "$TEMP_ROOT/backup-create.log" 2>&1; then
    cat "$TEMP_ROOT/backup-create.log" >&2
    exit 1
fi
if ! runtime "$SOURCE_APP" php artisan koma:site-backup:verify /harness/backups/pre-upgrade \
    > "$TEMP_ROOT/backup-verify.log" 2>&1; then
    cat "$TEMP_ROOT/backup-verify.log" >&2
    exit 1
fi

STEP='copying durable state into current extracted package'
cp "$SOURCE_APP/.env" "$UPGRADE_APP/.env"
chmod 600 "$UPGRADE_APP/.env"
cp "$SOURCE_APP/database/database.sqlite" "$UPGRADE_APP/database/database.sqlite"
rm -rf -- "$UPGRADE_APP/storage"
cp -a "$SOURCE_APP/storage" "$UPGRADE_APP/storage"
rm -f "$UPGRADE_APP/public/storage"
runtime "$UPGRADE_APP" php artisan storage:link --force --no-interaction >/dev/null
assert_environment "$UPGRADE_APP"
assert_storage_link "$UPGRADE_APP"

STEP='running current upgrade exactly once'
runtime "$UPGRADE_APP" php artisan optimize:clear --no-interaction >/dev/null
runtime "$UPGRADE_APP" php artisan package:discover --ansi >/dev/null
runtime "$UPGRADE_APP" php artisan migrate --force --no-interaction > "$TEMP_ROOT/upgrade-migrate.txt"
runtime "$UPGRADE_APP" php artisan koma:media:protect --no-interaction > "$TEMP_ROOT/upgrade-media-protect.txt"
printf '1\n' > "$TEMP_ROOT/upgrade-migration-invocations.txt"
verify_state "$UPGRADE_APP" "$TEMP_ROOT/upgrade-state.json"
cmp "$TEMP_ROOT/source-state.json" "$TEMP_ROOT/upgrade-state.json"
verify_doctor "$UPGRADE_APP" "$TEMP_ROOT/upgrade-doctor.json"

STEP='checking upgraded content restart queue and scheduler'
start_web "$UPGRADE_APP" upgrade
UPGRADE_WEB="$(cat "$TEMP_ROOT/upgrade-web.name")"
assert_content_http "$UPGRADE_WEB"
restart_web "$UPGRADE_WEB"
stop_web "$UPGRADE_WEB"
exercise_background_work "$UPGRADE_APP"

STEP='restoring verified backup into isolated target'
if ! runtime "$SOURCE_APP" php artisan koma:site-backup:restore /harness/backups/pre-upgrade \
    --target-root=/harness/restored-site --force > "$TEMP_ROOT/backup-restore.log" 2>&1; then
    cat "$TEMP_ROOT/backup-restore.log" >&2
    exit 1
fi
test -f "$TEMP_ROOT/restored-site/database/database.sqlite"
test -d "$TEMP_ROOT/restored-site/storage"

STEP='pairing restored state with exact previous package'
cp "$SOURCE_APP/.env" "$ROLLBACK_APP/.env"
chmod 600 "$ROLLBACK_APP/.env"
cp "$TEMP_ROOT/restored-site/database/database.sqlite" "$ROLLBACK_APP/database/database.sqlite"
rm -rf -- "$ROLLBACK_APP/storage"
cp -a "$TEMP_ROOT/restored-site/storage" "$ROLLBACK_APP/storage"
rm -f "$ROLLBACK_APP/public/storage"
runtime "$ROLLBACK_APP" php artisan storage:link --force --no-interaction >/dev/null
assert_environment "$ROLLBACK_APP"
assert_storage_link "$ROLLBACK_APP"

STEP='checking restored backup bytes and logical state'
BACKUP_DATABASE_SHA="$(hash_file "$TEMP_ROOT/backups/pre-upgrade/database/database.sqlite")"
ROLLBACK_DATABASE_SHA="$(hash_file "$ROLLBACK_APP/database/database.sqlite")"
test "$BACKUP_DATABASE_SHA" = "$ROLLBACK_DATABASE_SHA"
verify_state "$ROLLBACK_APP" "$TEMP_ROOT/rollback-state.json"
cmp "$TEMP_ROOT/source-state.json" "$TEMP_ROOT/rollback-state.json"

STEP='checking rolled-back operational doctor'
verify_doctor "$ROLLBACK_APP" "$TEMP_ROOT/rollback-doctor.json"

STEP='checking rolled-back content and restart'
start_web "$ROLLBACK_APP" rollback
ROLLBACK_WEB="$(cat "$TEMP_ROOT/rollback-web.name")"
assert_content_http "$ROLLBACK_WEB"
restart_web "$ROLLBACK_WEB"
stop_web "$ROLLBACK_WEB"

STEP='checking exact package identities'
grep -Fxq "source_commit=$CURRENT_REF" "$UPGRADE_APP/KOMA-BUILD-METADATA.txt"
grep -Fxq "source_commit=$PREVIOUS_REF" "$ROLLBACK_APP/KOMA-BUILD-METADATA.txt"
cmp "$SOURCE_APP/composer.lock" "$TEMP_ROOT/backups/pre-upgrade/release/composer.lock"
cmp "$SOURCE_APP/composer.lock" "$ROLLBACK_APP/composer.lock"

STEP='recording sanitized evidence'
FINISHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
DURATION_SECONDS="$((SECONDS - STARTED_SECONDS))"
EVIDENCE_INTERNAL="$TEMP_ROOT/evidence.json"
jq -n \
    --arg format koma-shared-host-smoke-v1 \
    --arg started_at "$STARTED_AT" \
    --arg finished_at "$FINISHED_AT" \
    --arg previous_ref "$PREVIOUS_REF" \
    --arg current_ref "$CURRENT_REF" \
    --arg previous_archive_sha256 "$(cat "$TEMP_ROOT/previous-archive.sha256")" \
    --arg current_archive_sha256 "$(cat "$TEMP_ROOT/current-archive.sha256")" \
    --arg source_database_sha256 "$SOURCE_DATABASE_SHA" \
    --arg backup_database_sha256 "$BACKUP_DATABASE_SHA" \
    --arg rollback_database_sha256 "$ROLLBACK_DATABASE_SHA" \
    --argjson duration_seconds "$DURATION_SECONDS" \
    '{format: $format, started_at: $started_at, finished_at: $finished_at,
      refs: {previous: $previous_ref, current: $current_ref},
      artifacts: {previous_sha256: $previous_archive_sha256, current_sha256: $current_archive_sha256},
      database: {live_source_sha256: $source_database_sha256, backup_sha256: $backup_database_sha256,
        rollback_sha256: $rollback_database_sha256, backup_matches_rollback: true},
      gates: {clean_install: true, restart: true, upgrade_once: true, queue: true, scheduler: true,
        verified_backup: true, rollback: true, real_control_panel: false},
      duration_seconds: $duration_seconds}' > "$EVIDENCE_INTERNAL"
if [[ -n "$EVIDENCE_PATH" ]]; then
    mkdir -p "$(dirname "$EVIDENCE_PATH")"
    cp "$EVIDENCE_INTERNAL" "$EVIDENCE_PATH"
fi

STEP='checking isolation and cleanup invariants'
AFTER_VOLUMES="$(maintainer_volume_fingerprint)"
AFTER_STATUS="$(git -C "$ROOT" status --porcelain=v1 -uall | shasum -a 256 | awk '{print $1}')"
AFTER_DIFF="$(git -C "$ROOT" diff --binary | shasum -a 256 | awk '{print $1}')"
test "$BEFORE_VOLUMES" = "$AFTER_VOLUMES"
test "$BEFORE_STATUS" = "$AFTER_STATUS"
test "$BEFORE_DIFF" = "$AFTER_DIFF"

printf 'Shared-hosting extracted-package smoke passed in %s seconds.\n' "$DURATION_SECONDS"
printf 'Previous ref: %s\nCurrent ref: %s\n' "$PREVIOUS_REF" "$CURRENT_REF"
printf 'Real control-panel acceptance remains a separate human gate.\n'
