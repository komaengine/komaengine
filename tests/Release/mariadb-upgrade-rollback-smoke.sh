#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"
APPROVED_PREDECESSOR='83eeb5911f617bf79b4e7e899a2cd69001803484'
PREDECESSOR_REF="$APPROVED_PREDECESSOR"
CURRENT_REF=''
PROJECT_BASE=''
EVIDENCE_PATH=''
FAIL_PHASE=''
STEP='initialization'
TEMP_ROOT=''
RUN_ID=''
STARTED_AT=''
FINISHED_AT=''
PHASE_LOG=''
CLEANUP_VERIFIED=false
MAINTAINER_VOLUMES_UNCHANGED=false
DATABASE_NAME='komaengine'

usage() {
    cat <<'EOF'
Usage: tests/Release/mariadb-upgrade-rollback-smoke.sh [options]

Options:
  --predecessor-ref HASH  Full immutable predecessor commit.
  --current-ref HASH      Full immutable current commit (defaults to resolved HEAD).
  --project-base NAME     Test-only deterministic base; must match koma-upgrade-<12 hex>.
  --evidence PATH         Copy machine-readable evidence to PATH.
  --fail-phase PHASE      Inject exit 97 after PHASE to verify scoped cleanup.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --predecessor-ref)
            PREDECESSOR_REF="${2:-}"
            shift 2
            ;;
        --current-ref)
            CURRENT_REF="${2:-}"
            shift 2
            ;;
        --project-base)
            PROJECT_BASE="${2:-}"
            shift 2
            ;;
        --evidence)
            EVIDENCE_PATH="${2:-}"
            shift 2
            ;;
        --fail-phase)
            FAIL_PHASE="${2:-}"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown MariaDB upgrade harness argument: %s\n' "$1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

full_commit() {
    local ref="$1"

    [[ "$ref" =~ ^[0-9a-f]{40}$ ]] || return 1
    [[ "$(git -C "$ROOT" rev-parse --verify "${ref}^{commit}" 2>/dev/null)" == "$ref" ]]
}

is_safe_project_base() {
    local value="$1"

    [[ "$value" =~ ^koma-upgrade-[0-9a-f]{12}$ ]]
    [[ "$value" != komaengine* ]]
}

require_safe_project() {
    local project="$1"

    [[ "$project" == "$PROJECT_BASE"-* ]] || {
        printf 'Refusing unsafe MariaDB project name: %s\n' "$project" >&2
        return 1
    }
    [[ "$project" != komaengine* ]]
}

maintainer_volume_fingerprint() {
    docker volume inspect \
        --format '{{.Name}} {{.CreatedAt}}' \
        komaengine_koma-db komaengine_koma-storage \
        | LC_ALL=C sort \
        | shasum -a 256 \
        | awk '{print $1}'
}

file_mode() {
    local path="$1"

    if stat -f '%Lp' "$path" >/dev/null 2>&1; then
        stat -f '%Lp' "$path"
    else
        stat -c '%a' "$path"
    fi
}

require_mode_600() {
    [[ "$(file_mode "$1")" == 600 ]]
}

initialize() {
    if [[ -z "$CURRENT_REF" ]]; then
        CURRENT_REF="$(git -C "$ROOT" rev-parse HEAD)"
    fi
    if [[ -z "$PROJECT_BASE" ]]; then
        PROJECT_BASE="koma-upgrade-$(openssl rand -hex 6)"
    fi

    is_safe_project_base "$PROJECT_BASE" || {
        echo 'Project base must match koma-upgrade-<12 lowercase hex> and may not use maintainer names.' >&2
        exit 2
    }

    RUN_ID="${PROJECT_BASE#koma-upgrade-}"
    BUILD_PROJECT="${PROJECT_BASE}-mariadb-build"
    SOURCE_PROJECT="${PROJECT_BASE}-mariadb-source"
    UPGRADE_PROJECT="${PROJECT_BASE}-mariadb-upgrade"
    ROLLBACK_PROJECT="${PROJECT_BASE}-mariadb-rollback"
    INTERRUPTED_PROJECT="${PROJECT_BASE}-mariadb-interrupted"
    RECOVERY_PROJECT="${PROJECT_BASE}-mariadb-recovery"
    PREDECESSOR_IMAGE="${PROJECT_BASE}-mariadb-predecessor:${PREDECESSOR_REF:0:12}"
    CURRENT_IMAGE="${PROJECT_BASE}-mariadb-current:${CURRENT_REF:0:12}"
    SOURCE_PORT="$((28000 + 16#${RUN_ID:0:3} % 1500))"
    UPGRADE_PORT="$((31000 + 16#${RUN_ID:3:3} % 1500))"
    ROLLBACK_PORT="$((34000 + 16#${RUN_ID:6:3} % 1500))"
    INTERRUPTED_PORT="$((37000 + 16#${RUN_ID:9:3} % 1500))"
    RECOVERY_PORT="$((40000 + 16#${RUN_ID:0:3} % 1500))"
    APP_KEY_VALUE="base64:$(openssl rand -base64 32 | tr -d '\n')"
    APP_KEY_FINGERPRINT="$(printf '%s' "$APP_KEY_VALUE" | shasum -a 256 | awk '{print $1}')"
    SOURCE_DB_PASSWORD="$(openssl rand -hex 24)"
    SOURCE_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
    UPGRADE_DB_PASSWORD="$(openssl rand -hex 24)"
    UPGRADE_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
    ROLLBACK_DB_PASSWORD="$(openssl rand -hex 24)"
    ROLLBACK_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
    INTERRUPTED_DB_PASSWORD="$(openssl rand -hex 24)"
    INTERRUPTED_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
    RECOVERY_DB_PASSWORD="$(openssl rand -hex 24)"
    RECOVERY_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
}

setup_temp_root() {
    TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-upgrade-mariadb.XXXXXX")"
    PREDECESSOR_CONTEXT="$TEMP_ROOT/predecessor"
    CURRENT_CONTEXT="$TEMP_ROOT/current"
    BUILD_COMPOSE="$TEMP_ROOT/build-compose.yml"
    BUILD_ENV="$TEMP_ROOT/build.env"
    STAGE_COMPOSE="$TEMP_ROOT/stage-compose.yml"
    SOURCE_ENV="$TEMP_ROOT/source.env"
    UPGRADE_ENV="$TEMP_ROOT/upgrade.env"
    ROLLBACK_ENV="$TEMP_ROOT/rollback.env"
    INTERRUPTED_ENV="$TEMP_ROOT/interrupted.env"
    RECOVERY_ENV="$TEMP_ROOT/recovery.env"
    SOURCE_SQL="$TEMP_ROOT/source.sql"
    SOURCE_STORAGE="$TEMP_ROOT/source-storage.tar"
    SOURCE_STATE="$TEMP_ROOT/source-state.json"
    PHASE_LOG="$TEMP_ROOT/phases.tsv"
    INTERNAL_EVIDENCE="$TEMP_ROOT/evidence.json"
    : > "$PHASE_LOG"
}

stage_values() {
    local stage="$1"

    case "$stage" in
        source)
            STAGE_PROJECT="$SOURCE_PROJECT"
            STAGE_ENV="$SOURCE_ENV"
            STAGE_PORT="$SOURCE_PORT"
            STAGE_IMAGE="$PREDECESSOR_IMAGE"
            ;;
        upgrade)
            STAGE_PROJECT="$UPGRADE_PROJECT"
            STAGE_ENV="$UPGRADE_ENV"
            STAGE_PORT="$UPGRADE_PORT"
            STAGE_IMAGE="$CURRENT_IMAGE"
            ;;
        rollback)
            STAGE_PROJECT="$ROLLBACK_PROJECT"
            STAGE_ENV="$ROLLBACK_ENV"
            STAGE_PORT="$ROLLBACK_PORT"
            STAGE_IMAGE="$PREDECESSOR_IMAGE"
            ;;
        interrupted)
            STAGE_PROJECT="$INTERRUPTED_PROJECT"
            STAGE_ENV="$INTERRUPTED_ENV"
            STAGE_PORT="$INTERRUPTED_PORT"
            STAGE_IMAGE="$CURRENT_IMAGE"
            ;;
        recovery)
            STAGE_PROJECT="$RECOVERY_PROJECT"
            STAGE_ENV="$RECOVERY_ENV"
            STAGE_PORT="$RECOVERY_PORT"
            STAGE_IMAGE="$PREDECESSOR_IMAGE"
            ;;
        *)
            printf 'Unknown MariaDB harness stage: %s\n' "$stage" >&2
            return 2
            ;;
    esac
}

stage_compose() {
    local stage="$1"
    shift
    stage_values "$stage"
    require_safe_project "$STAGE_PROJECT"
    docker compose \
        --project-name "$STAGE_PROJECT" \
        --env-file "$STAGE_ENV" \
        --file "$STAGE_COMPOSE" \
        "$@"
}

record_phase() {
    printf '%s\t%s\n' "$1" "$2" >> "$PHASE_LOG"
}

run_phase() {
    local phase="$1"
    local action="$2"
    local started=$SECONDS

    STEP="$phase"
    "$action"
    record_phase "$phase" "$((SECONDS - started))"

    if [[ "$FAIL_PHASE" == "$phase" ]]; then
        printf 'Injected MariaDB upgrade harness failure after %s.\n' "$phase" >&2
        return 97
    fi
}

validate_inputs() {
    full_commit "$PREDECESSOR_REF" || {
        printf 'Predecessor ref must be an available full commit: %s\n' "$PREDECESSOR_REF" >&2
        return 2
    }
    full_commit "$CURRENT_REF" || {
        printf 'Current ref must be an available full commit: %s\n' "$CURRENT_REF" >&2
        return 2
    }
    [[ "$PREDECESSOR_REF" != "$CURRENT_REF" ]]
    git -C "$ROOT" merge-base --is-ancestor "$PREDECESSOR_REF" "$CURRENT_REF"

    local project
    for project in \
        "$BUILD_PROJECT" "$SOURCE_PROJECT" "$UPGRADE_PROJECT" "$ROLLBACK_PROJECT" \
        "$INTERRUPTED_PROJECT" "$RECOVERY_PROJECT"; do
        require_safe_project "$project"
        test -z "$(docker ps -aq --filter "label=com.docker.compose.project=$project")"
        test -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")"
        test -z "$(docker network ls -q --filter "label=com.docker.compose.project=$project")"
    done

    ! docker image inspect "$PREDECESSOR_IMAGE" >/dev/null 2>&1
    ! docker image inspect "$CURRENT_IMAGE" >/dev/null 2>&1

    local port
    for port in "$SOURCE_PORT" "$UPGRADE_PORT" "$ROLLBACK_PORT" "$INTERRUPTED_PORT" "$RECOVERY_PORT"; do
        if docker ps --format '{{.Ports}}' | grep -Eq "([:.])${port}->"; then
            printf 'Disposable port is already published: %s\n' "$port" >&2
            return 2
        fi
    done

    local insecure="$TEMP_ROOT/insecure.cnf"
    install -m 0644 /dev/null "$insecure"
    if require_mode_600 "$insecure"; then
        echo 'The credential-mode guard accepted a world-readable file.' >&2
        return 1
    fi
    rm -f "$insecure"

    MAINTAINER_VOLUME_FINGERPRINT_BEFORE="$(maintainer_volume_fingerprint)"
    "$ROOT/tests/Release/dual-revision-harness.sh" \
        --dry-run --predecessor-ref "$PREDECESSOR_REF" --current-ref "$CURRENT_REF" \
        >/dev/null
}

export_revisions() {
    mkdir -p "$PREDECESSOR_CONTEXT" "$CURRENT_CONTEXT"
    git -C "$ROOT" archive "$PREDECESSOR_REF" | tar -x -C "$PREDECESSOR_CONTEXT"
    git -C "$ROOT" archive "$CURRENT_REF" | tar -x -C "$CURRENT_CONTEXT"
    "$ROOT/scripts/release/pin-exported-dockerfile.sh" "$PREDECESSOR_CONTEXT/Dockerfile"
    "$ROOT/scripts/release/pin-exported-dockerfile.sh" "$CURRENT_CONTEXT/Dockerfile"
}

write_build_files() {
    local current_version
    local current_build_id

    current_version="$(tr -d '\r\n' < "$CURRENT_CONTEXT/VERSION")"
    current_build_id="${current_version}+${CURRENT_REF:0:12}"

    umask 077
    {
        printf 'PREDECESSOR_IMAGE=%s\n' "$PREDECESSOR_IMAGE"
        printf 'CURRENT_IMAGE=%s\n' "$CURRENT_IMAGE"
        printf 'PREDECESSOR_CONTEXT=%s\n' "$PREDECESSOR_CONTEXT"
        printf 'CURRENT_CONTEXT=%s\n' "$CURRENT_CONTEXT"
        printf 'PREDECESSOR_REF=%s\n' "$PREDECESSOR_REF"
        printf 'CURRENT_REF=%s\n' "$CURRENT_REF"
        printf 'CURRENT_VERSION=%s\n' "$current_version"
        printf 'CURRENT_BUILD_ID=%s\n' "$current_build_id"
        printf 'KOMA_RUN_ID=%s\n' "$RUN_ID"
    } > "$BUILD_ENV"
    chmod 600 "$BUILD_ENV"

    cat > "$BUILD_COMPOSE" <<'YAML'
services:
  predecessor:
    image: ${PREDECESSOR_IMAGE:?Set PREDECESSOR_IMAGE}
    build:
      context: ${PREDECESSOR_CONTEXT:?Set PREDECESSOR_CONTEXT}
      dockerfile: Dockerfile
      labels:
        io.komaengine.release-harness: "true"
        io.komaengine.release-harness.run: ${KOMA_RUN_ID:?Set KOMA_RUN_ID}
        io.komaengine.release-harness.source-ref: ${PREDECESSOR_REF:?Set PREDECESSOR_REF}
  current:
    image: ${CURRENT_IMAGE:?Set CURRENT_IMAGE}
    build:
      context: ${CURRENT_CONTEXT:?Set CURRENT_CONTEXT}
      dockerfile: Dockerfile
      args:
        KOMA_RELEASE_VERSION: ${CURRENT_VERSION:?Set CURRENT_VERSION}
        KOMA_RELEASE_COMMIT: ${CURRENT_REF:?Set CURRENT_REF}
        KOMA_RELEASE_DIRTY: "false"
        KOMA_RELEASE_BUILD_ID: ${CURRENT_BUILD_ID:?Set CURRENT_BUILD_ID}
        KOMA_TRUSTED_BUILD: "true"
      labels:
        io.komaengine.release-harness: "true"
        io.komaengine.release-harness.run: ${KOMA_RUN_ID:?Set KOMA_RUN_ID}
        io.komaengine.release-harness.source-ref: ${CURRENT_REF:?Set CURRENT_REF}
YAML
    chmod 600 "$BUILD_COMPOSE"
}

write_stage_compose() {
    cat > "$STAGE_COMPOSE" <<'YAML'
services:
  app:
    image: ${KOMA_IMAGE:?Set KOMA_IMAGE}
    command: ["web"]
    restart: "no"
    labels: &harness_labels
      io.komaengine.release-harness: "true"
      io.komaengine.release-harness.run: ${KOMA_RUN_ID:?Set KOMA_RUN_ID}
      io.komaengine.release-harness.stage: ${KOMA_STAGE:?Set KOMA_STAGE}
    environment: &runtime_environment
      APP_NAME: KomaEngine Upgrade Harness
      APP_ENV: production
      APP_KEY: ${KOMA_APP_KEY:?Set KOMA_APP_KEY}
      APP_DEBUG: "false"
      APP_URL: ${KOMA_APP_URL:?Set KOMA_APP_URL}
      LOG_CHANNEL: stderr
      LOG_LEVEL: error
      DB_CONNECTION: mariadb
      DB_HOST: db
      DB_PORT: 3306
      DB_DATABASE: ${KOMA_DB_DATABASE:?Set KOMA_DB_DATABASE}
      DB_USERNAME: ${KOMA_DB_USERNAME:?Set KOMA_DB_USERNAME}
      DB_PASSWORD: ${KOMA_DB_PASSWORD:?Set KOMA_DB_PASSWORD}
      SESSION_DRIVER: file
      SESSION_SECURE_COOKIE: "false"
      CACHE_STORE: file
      QUEUE_CONNECTION: database
      DB_QUEUE_RETRY_AFTER: 3660
      QUEUE_WORKER_TIMEOUT: 3600
      FILESYSTEM_DISK: public
      MEDIA_DISK_DRIVER: local
      INSTALLER_ENVIRONMENT_MODE: process
      INSTALLER_WRITE_ROBOTS: "false"
      MAIL_MAILER: log
    volumes:
      - koma-storage:/var/www/html/storage
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "127.0.0.1:${KOMA_APP_PORT:?Set KOMA_APP_PORT}:80"
    healthcheck:
      test: ["CMD", "gosu", "www-data", "php", "artisan", "koma:doctor", "--mode=bootstrap", "--json"]
      interval: 5s
      timeout: 8s
      retries: 24
      start_period: 10s
  worker:
    image: ${KOMA_IMAGE:?Set KOMA_IMAGE}
    command: ["worker"]
    restart: "no"
    labels: *harness_labels
    environment: *runtime_environment
    volumes:
      - koma-storage:/var/www/html/storage
    depends_on:
      db:
        condition: service_healthy
    stop_grace_period: 61m
  scheduler:
    image: ${KOMA_IMAGE:?Set KOMA_IMAGE}
    command: ["scheduler"]
    restart: "no"
    labels: *harness_labels
    environment: *runtime_environment
    volumes:
      - koma-storage:/var/www/html/storage
    depends_on:
      db:
        condition: service_healthy
  db:
    image: ${KOMA_MARIADB_IMAGE:?Set KOMA_MARIADB_IMAGE}
    restart: "no"
    labels: *harness_labels
    environment:
      MARIADB_DATABASE: ${KOMA_DB_DATABASE:?Set KOMA_DB_DATABASE}
      MARIADB_USER: ${KOMA_DB_USERNAME:?Set KOMA_DB_USERNAME}
      MARIADB_PASSWORD: ${KOMA_DB_PASSWORD:?Set KOMA_DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${KOMA_DB_ROOT_PASSWORD:?Set KOMA_DB_ROOT_PASSWORD}
    volumes:
      - koma-db:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 24
volumes:
  koma-storage:
    labels:
      io.komaengine.release-harness: "true"
      io.komaengine.release-harness.run: ${KOMA_RUN_ID:?Set KOMA_RUN_ID}
      io.komaengine.release-harness.stage: ${KOMA_STAGE:?Set KOMA_STAGE}
  koma-db:
    labels:
      io.komaengine.release-harness: "true"
      io.komaengine.release-harness.run: ${KOMA_RUN_ID:?Set KOMA_RUN_ID}
      io.komaengine.release-harness.stage: ${KOMA_STAGE:?Set KOMA_STAGE}
networks:
  default:
    labels:
      io.komaengine.release-harness: "true"
      io.komaengine.release-harness.run: ${KOMA_RUN_ID:?Set KOMA_RUN_ID}
      io.komaengine.release-harness.stage: ${KOMA_STAGE:?Set KOMA_STAGE}
YAML
    chmod 600 "$STAGE_COMPOSE"
}

write_stage_env() {
    local stage="$1"
    local path="$2"
    local port="$3"
    local image="$4"
    local password="$5"
    local root_password="$6"

    umask 077
    {
        printf 'KOMA_IMAGE=%s\n' "$image"
        printf 'KOMA_MARIADB_IMAGE=%s\n' "$KOMA_MARIADB_IMAGE"
        printf 'KOMA_RUN_ID=%s\n' "$RUN_ID"
        printf 'KOMA_STAGE=%s\n' "$stage"
        printf 'KOMA_APP_KEY=%s\n' "$APP_KEY_VALUE"
        printf 'KOMA_APP_URL=http://127.0.0.1:%s\n' "$port"
        printf 'KOMA_APP_PORT=%s\n' "$port"
        printf 'KOMA_DB_DATABASE=%s\n' "$DATABASE_NAME"
        printf 'KOMA_DB_USERNAME=koma\n'
        printf 'KOMA_DB_PASSWORD=%s\n' "$password"
        printf 'KOMA_DB_ROOT_PASSWORD=%s\n' "$root_password"
    } > "$path"
    chmod 600 "$path"
    require_mode_600 "$path"
}

write_runtime_files() {
    write_build_files
    write_stage_compose
    write_stage_env source "$SOURCE_ENV" "$SOURCE_PORT" "$PREDECESSOR_IMAGE" \
        "$SOURCE_DB_PASSWORD" "$SOURCE_DB_ROOT_PASSWORD"
    write_stage_env upgrade "$UPGRADE_ENV" "$UPGRADE_PORT" "$CURRENT_IMAGE" \
        "$UPGRADE_DB_PASSWORD" "$UPGRADE_DB_ROOT_PASSWORD"
    write_stage_env rollback "$ROLLBACK_ENV" "$ROLLBACK_PORT" "$PREDECESSOR_IMAGE" \
        "$ROLLBACK_DB_PASSWORD" "$ROLLBACK_DB_ROOT_PASSWORD"
    write_stage_env interrupted "$INTERRUPTED_ENV" "$INTERRUPTED_PORT" "$CURRENT_IMAGE" \
        "$INTERRUPTED_DB_PASSWORD" "$INTERRUPTED_DB_ROOT_PASSWORD"
    write_stage_env recovery "$RECOVERY_ENV" "$RECOVERY_PORT" "$PREDECESSOR_IMAGE" \
        "$RECOVERY_DB_PASSWORD" "$RECOVERY_DB_ROOT_PASSWORD"
}

build_images() {
    docker compose \
        --project-name "$BUILD_PROJECT" \
        --env-file "$BUILD_ENV" \
        --file "$BUILD_COMPOSE" \
        build --quiet predecessor current

    local predecessor_label
    local current_label
    predecessor_label="$(docker image inspect --format '{{ index .Config.Labels "io.komaengine.release-harness.source-ref" }}' "$PREDECESSOR_IMAGE")"
    current_label="$(docker image inspect --format '{{ index .Config.Labels "io.komaengine.release-harness.source-ref" }}' "$CURRENT_IMAGE")"
    [[ "$predecessor_label" == "$PREDECESSOR_REF" ]]
    [[ "$current_label" == "$CURRENT_REF" ]]
    docker image inspect --format '{{.Id}}' "$PREDECESSOR_IMAGE" > "$TEMP_ROOT/predecessor-image-id.txt"
    docker image inspect --format '{{.Id}}' "$CURRENT_IMAGE" > "$TEMP_ROOT/current-image-id.txt"
}

ensure_db_client() {
    local stage="$1"

    stage_compose "$stage" exec -T db sh -lc '
        umask 077
        {
            printf "[client]\n"
            printf "host=127.0.0.1\n"
            printf "port=3306\n"
            printf "user=root\n"
            printf "password=%s\n" "$MARIADB_ROOT_PASSWORD"
        } > /tmp/koma-harness-client.cnf
        chmod 600 /tmp/koma-harness-client.cnf
        test "$(stat -c %a /tmp/koma-harness-client.cnf)" = 600
    '
}

db_query() {
    local stage="$1"
    local query="$2"

    stage_compose "$stage" exec -T db \
        mariadb --defaults-extra-file=/tmp/koma-harness-client.cnf \
        --database="$DATABASE_NAME" --batch --skip-column-names --execute="$query"
}

assert_database_empty() {
    local stage="$1"
    local count

    count="$(db_query "$stage" 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')"
    [[ "$count" == 0 ]] || {
        printf 'Refusing to import into non-empty %s database.\n' "$stage" >&2
        return 44
    }
}

dump_database() {
    local stage="$1"
    local path="$2"

    umask 077
    stage_compose "$stage" exec -T db \
        mariadb-dump --defaults-extra-file=/tmp/koma-harness-client.cnf \
        --single-transaction --skip-comments --skip-dump-date --skip-add-locks \
        --skip-disable-keys --order-by-primary --hex-blob --routines --events --triggers \
        "$DATABASE_NAME" > "$path"
    chmod 600 "$path"
    require_mode_600 "$path"
    test -s "$path"
}

import_database() {
    local stage="$1"
    local path="$2"

    assert_database_empty "$stage" || return $?
    stage_compose "$stage" exec -T db \
        mariadb --defaults-extra-file=/tmp/koma-harness-client.cnf \
        --database="$DATABASE_NAME" < "$path" || return $?
    test "$(db_query "$stage" 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')" -gt 0
}

capture_storage() {
    umask 077
    stage_compose source run --rm --no-deps -T --user root --entrypoint sh app \
        -lc 'tar -cf - -C /var/www/html/storage .' > "$SOURCE_STORAGE"
    chmod 600 "$SOURCE_STORAGE"
    require_mode_600 "$SOURCE_STORAGE"
    test -s "$SOURCE_STORAGE"
}

restore_storage() {
    local stage="$1"

    case "$stage" in
        upgrade|rollback|interrupted|recovery) ;;
        *) return 2 ;;
    esac

    stage_compose "$stage" run --rm --no-deps -T --user root --entrypoint sh app -lc '
        find /var/www/html/storage -mindepth 1 -delete
        test -z "$(find /var/www/html/storage -mindepth 1 -print -quit)"
        tar -xf - -C /var/www/html/storage
        chown -R www-data:www-data /var/www/html/storage
    ' < "$SOURCE_STORAGE"
}

storage_inventory_hash() {
    local stage="$1"
    # "protected" lists the predecessor's public manga media at the paths the
    # current release's koma:media:protect moves it to, so an upgraded
    # inventory can be compared with its source file for file.
    local layout="${2:-as-stored}"
    local relocate='cat'

    if [[ "$layout" == protected ]]; then
        relocate="sed 's#  \\./app/public/media/#  ./app/media/#'"
    fi

    stage_compose "$stage" run --rm --no-deps -T --entrypoint sh app -lc \
        "cd /var/www/html/storage && find . -type f -exec sha256sum {} \\; | $relocate | LC_ALL=C sort | sha256sum | awk '{print \$1}'"
}

copy_support() {
    local stage="$1"
    local support="$2"
    local app_container

    app_container="$(stage_compose "$stage" ps -q app)"
    test -n "$app_container"
    docker cp "$ROOT/tests/Release/support/$support" "$app_container:/tmp/$support"
}

run_support() {
    local stage="$1"
    shift
    stage_compose "$stage" exec -T app \
        gosu www-data env KOMA_APP_ROOT=/var/www/html "$@"
}

assert_migration_state() {
    local state_file="$1"
    local ref="$2"
    local actual="$TEMP_ROOT/actual-migrations.txt"
    local expected="$TEMP_ROOT/expected-migrations.txt"

    jq -r '.migrations[]' "$state_file" > "$actual"
    git -C "$ROOT" ls-tree -r --name-only "$ref" database/migrations \
        | sed -e 's#database/migrations/##' -e 's#\.php$##' \
        | LC_ALL=C sort > "$expected"
    cmp "$expected" "$actual"
}

start_database() {
    local stage="$1"

    stage_compose "$stage" up -d db --wait >/dev/null
    ensure_db_client "$stage"
    assert_database_empty "$stage"
}

restore_stage() {
    local stage="$1"

    start_database "$stage"
    import_database "$stage" "$SOURCE_SQL"
    if import_database "$stage" "$SOURCE_SQL" >/dev/null 2>&1; then
        printf 'The %s empty-target guard accepted a second import.\n' "$stage" >&2
        return 1
    fi
    restore_storage "$stage"
}

start_application() {
    local stage="$1"

    stage_compose "$stage" up -d app --wait >/dev/null
    copy_support "$stage" exercise-upgrade-fixture.php
    copy_support "$stage" exercise-upgrade-runtime.php
    copy_support "$stage" exercise-upgrade-compose-runtime.php
}

capture_fixture_state() {
    local stage="$1"
    local output="$2"

    run_support "$stage" env KOMA_UPGRADE_FIXTURE_ACTION=verify \
        php /tmp/exercise-upgrade-fixture.php > "$output"
}

assert_identity() {
    local stage="$1"
    local output="$2"

    run_support "$stage" env KOMA_UPGRADE_RUNTIME_ACTION=identity \
        php /tmp/exercise-upgrade-runtime.php > "$output"
    jq -e --arg key_sha "$APP_KEY_FINGERPRINT" \
        '.password_verified == true and .active_theme == "aozora" and (.enabled_plugins | index("hello-koma")) != null and .hello_koma_compatible == true and .hello_koma_failure == null and .app_key_sha256 == $key_sha' \
        "$output" >/dev/null
}

assert_doctor() {
    local stage="$1"
    local output="$2"

    stage_compose "$stage" exec -T app \
        gosu www-data php artisan koma:doctor --mode=operational --json > "$output"
    jq -e '.ready == true and ([.checks[]] | all)' "$output" >/dev/null
}

assert_http() {
    local stage="$1"
    local state_file="$2"
    local body="$TEMP_ROOT/${stage}-http.html"
    local media="$TEMP_ROOT/${stage}-reader.png"
    local media_sha
    local admin_status

    stage_values "$stage"
    if [[ "$stage" == upgrade ]]; then
        media_url='/media/series/pre-release-upgrade-fixture/chapters/1/pages/1'
    else
        media_url='/storage/media/upgrade/reader.png'
    fi
    curl -fsS "http://127.0.0.1:$STAGE_PORT/" > "$body"
    grep -Fq 'Pre-release Upgrade Fixture' "$body"
    curl -fsS "http://127.0.0.1:$STAGE_PORT/series/pre-release-upgrade-fixture" > "$body"
    grep -Fq 'Pre-release Upgrade Fixture' "$body"
    curl -fsS "http://127.0.0.1:$STAGE_PORT/series/pre-release-upgrade-fixture/chapter/1" > "$body"
    grep -Fq 'Published predecessor chapter' "$body"
    curl -fsS "http://127.0.0.1:$STAGE_PORT$media_url" > "$media"
    media_sha="$(jq -r '.files.media.sha256' "$state_file")"
    [[ "$(shasum -a 256 "$media" | awk '{print $1}')" == "$media_sha" ]]
    admin_status="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$STAGE_PORT/admin")"
    [[ "$admin_status" == 302 ]]
}

exercise_runtime_services() {
    local stage="$1"
    local prefix="$2"
    local dispatch="$TEMP_ROOT/${prefix}-runtime-dispatch.json"
    local runtime="$TEMP_ROOT/${prefix}-runtime-state.json"
    local restarted="$TEMP_ROOT/${prefix}-runtime-restarted.json"
    local key_before
    local key_after
    local attempt

    run_support "$stage" env KOMA_UPGRADE_COMPOSE_ACTION=dispatch \
        php /tmp/exercise-upgrade-compose-runtime.php > "$dispatch"
    key_before="$(jq -r '.key_fingerprint' "$dispatch")"
    [[ "$key_before" == "$APP_KEY_FINGERPRINT" ]]

    stage_compose "$stage" up -d worker scheduler >/dev/null
    for attempt in $(seq 1 75); do
        run_support "$stage" env KOMA_UPGRADE_COMPOSE_ACTION=status \
            php /tmp/exercise-upgrade-compose-runtime.php > "$runtime"
        if jq -e '
            .import_status == "completed"
            and .import_chapters == 1
            and .import_pages == 1
            and .scheduled_chapter_status == "published"
            and .installed == true
        ' "$runtime" >/dev/null; then
            break
        fi
        if [[ "$attempt" -eq 75 ]]; then
            jq . "$runtime" >&2
            echo 'MariaDB worker/scheduler proof timed out.' >&2
            return 1
        fi
        sleep 2
    done

    stage_compose "$stage" restart app worker scheduler >/dev/null
    stage_compose "$stage" up -d app worker scheduler --wait >/dev/null
    test -n "$(stage_compose "$stage" ps --status running -q worker)"
    test -n "$(stage_compose "$stage" ps --status running -q scheduler)"
    run_support "$stage" env KOMA_UPGRADE_COMPOSE_ACTION=status \
        php /tmp/exercise-upgrade-compose-runtime.php > "$restarted"
    key_after="$(jq -r '.key_fingerprint' "$restarted")"
    [[ "$key_before" == "$key_after" ]]
    jq -e '.import_status == "completed" and .scheduled_chapter_status == "published"' \
        "$restarted" >/dev/null
    assert_doctor "$stage" "$TEMP_ROOT/${prefix}-doctor-after-restart.json"
}

install_source() {
    start_database source
    stage_compose source run --rm --no-deps -T app migrate > "$TEMP_ROOT/source-migrate.txt"
    stage_compose source up -d app worker scheduler --wait >/dev/null
    copy_support source exercise-upgrade-fixture.php
    copy_support source create-recovery-queue-archive.php

    stage_compose source exec -T app \
        gosu www-data php artisan db:seed --class=RolePermissionSeeder --force --no-interaction \
        >/dev/null
    run_support source env KOMA_UPGRADE_FIXTURE_ACTION=create \
        php /tmp/exercise-upgrade-fixture.php > "$TEMP_ROOT/source-fixture-create.json"
    stage_compose source exec -T app \
        gosu www-data php artisan cache:clear --no-interaction >/dev/null
    stage_compose source exec -T app gosu www-data php artisan koma:archive:export \
        pre-release-upgrade-fixture \
        --output=/var/www/html/storage/app/private/upgrade/predecessor-v1.koma.zip \
        > "$TEMP_ROOT/source-archive-export.txt"
    run_support source php /tmp/create-recovery-queue-archive.php \
        > "$TEMP_ROOT/source-queue-archive.txt"
    capture_fixture_state source "$SOURCE_STATE"
    assert_migration_state "$SOURCE_STATE" "$PREDECESSOR_REF"
    jq -e '
        (.migrations | length) == 20
        and .user.password_verified == true
        and .extensions.active_theme == "aozora"
        and (.extensions.enabled_plugins | index("hello-koma")) != null
        and .extensions.hello_koma_compatible == true
        and .extensions.hello_koma_failure == null
    ' "$SOURCE_STATE" >/dev/null

    stage_compose source stop worker scheduler >/dev/null
    test -z "$(stage_compose source ps --status running -q worker)"
    test -z "$(stage_compose source ps --status running -q scheduler)"
    assert_doctor source "$TEMP_ROOT/source-doctor.json"
    stage_compose source stop app >/dev/null
}

capture_source_backup() {
    dump_database source "$SOURCE_SQL"
    capture_storage
    SOURCE_DATABASE_SHA="$(shasum -a 256 "$SOURCE_SQL" | awk '{print $1}')"
    SOURCE_STORAGE_SHA="$(storage_inventory_hash source)"
    SOURCE_PROTECTED_STORAGE_SHA="$(storage_inventory_hash source protected)"
    SOURCE_STATE_SHA="$(shasum -a 256 "$SOURCE_STATE" | awk '{print $1}')"
    printf '%s\n' "$SOURCE_DATABASE_SHA" > "$TEMP_ROOT/source-database.sha256"
    printf '%s\n' "$SOURCE_STORAGE_SHA" > "$TEMP_ROOT/source-storage.sha256"
    printf '%s\n' "$SOURCE_STATE_SHA" > "$TEMP_ROOT/source-state.sha256"
}

prove_interrupted_upgrade() {
    restore_stage interrupted
    stage_compose interrupted run --rm --no-deps -T --entrypoint sh app -lc \
        'exec gosu www-data php artisan migrate --force --no-interaction --path=database/migrations/2026_07_29_001100_add_archive_v2_metadata.php' \
        > "$TEMP_ROOT/interrupted-migrate.txt"
    INTERRUPTED_MIGRATION_COUNT="$(db_query interrupted 'SELECT COUNT(*) FROM migrations')"
    [[ "$INTERRUPTED_MIGRATION_COUNT" == 21 ]]
    db_query interrupted 'SELECT migration FROM migrations ORDER BY id' \
        > "$TEMP_ROOT/interrupted-migrations.txt"
    grep -Fxq '2026_07_29_001100_add_archive_v2_metadata' "$TEMP_ROOT/interrupted-migrations.txt"
    if grep -Fxq '2026_08_01_000100_add_admin_dashboard_preferences_to_users' "$TEMP_ROOT/interrupted-migrations.txt"; then
        echo 'Interrupted upgrade unexpectedly applied the second forward migration.' >&2
        return 1
    fi
    dump_database interrupted "$TEMP_ROOT/interrupted.sql"
    INTERRUPTED_DATABASE_SHA="$(shasum -a 256 "$TEMP_ROOT/interrupted.sql" | awk '{print $1}')"
    [[ "$INTERRUPTED_DATABASE_SHA" != "$SOURCE_DATABASE_SHA" ]]
    printf '%s\n' "$INTERRUPTED_DATABASE_SHA" > "$TEMP_ROOT/interrupted-database.sha256"
}

prove_interrupted_recovery() {
    restore_stage recovery
    dump_database recovery "$TEMP_ROOT/recovery.sql"
    RECOVERY_DATABASE_SHA="$(shasum -a 256 "$TEMP_ROOT/recovery.sql" | awk '{print $1}')"
    RECOVERY_STORAGE_SHA="$(storage_inventory_hash recovery)"
    [[ "$RECOVERY_DATABASE_SHA" == "$SOURCE_DATABASE_SHA" ]]
    [[ "$RECOVERY_STORAGE_SHA" == "$SOURCE_STORAGE_SHA" ]]
    printf '%s\n' "$RECOVERY_DATABASE_SHA" > "$TEMP_ROOT/recovery-database.sha256"
    printf '%s\n' "$RECOVERY_STORAGE_SHA" > "$TEMP_ROOT/recovery-storage.sha256"

    start_application recovery
    capture_fixture_state recovery "$TEMP_ROOT/recovery-state.json"
    cmp "$SOURCE_STATE" "$TEMP_ROOT/recovery-state.json"
    assert_migration_state "$TEMP_ROOT/recovery-state.json" "$PREDECESSOR_REF"
    assert_identity recovery "$TEMP_ROOT/recovery-identity.json"
    assert_doctor recovery "$TEMP_ROOT/recovery-doctor.json"
    stage_compose recovery stop app >/dev/null
}

upgrade_current() {
    restore_stage upgrade
    stage_compose upgrade run --rm --no-deps -T app migrate > "$TEMP_ROOT/upgrade-migrate.txt"
    printf '1\n' > "$TEMP_ROOT/upgrade-migration-invocations.txt"
    dump_database upgrade "$TEMP_ROOT/upgrade-after-migrate.sql"
    UPGRADE_DATABASE_SHA="$(shasum -a 256 "$TEMP_ROOT/upgrade-after-migrate.sql" | awk '{print $1}')"
    UPGRADE_STORAGE_SHA="$(storage_inventory_hash upgrade)"
    [[ "$UPGRADE_DATABASE_SHA" != "$SOURCE_DATABASE_SHA" ]]
    # The upgrade moves manga media into protected storage and changes nothing
    # else: the same files with the same bytes, at the protected paths.
    [[ "$UPGRADE_STORAGE_SHA" != "$SOURCE_STORAGE_SHA" ]]
    [[ "$UPGRADE_STORAGE_SHA" == "$SOURCE_PROTECTED_STORAGE_SHA" ]]
    printf '%s\n' "$UPGRADE_DATABASE_SHA" > "$TEMP_ROOT/upgrade-database.sha256"
    printf '%s\n' "$UPGRADE_STORAGE_SHA" > "$TEMP_ROOT/upgrade-storage.sha256"
}

validate_current_upgrade() {
    start_application upgrade
    capture_fixture_state upgrade "$TEMP_ROOT/upgrade-state.json"
    assert_migration_state "$TEMP_ROOT/upgrade-state.json" "$CURRENT_REF"
    jq -e '(.migrations | length) == 22' "$TEMP_ROOT/upgrade-state.json" >/dev/null
    jq -S 'del(.migrations)' "$SOURCE_STATE" > "$TEMP_ROOT/source-durable.json"
    jq -S 'del(.migrations)' "$TEMP_ROOT/upgrade-state.json" > "$TEMP_ROOT/upgrade-durable.json"
    cmp "$TEMP_ROOT/source-durable.json" "$TEMP_ROOT/upgrade-durable.json"
    assert_identity upgrade "$TEMP_ROOT/upgrade-identity.json"
    assert_doctor upgrade "$TEMP_ROOT/upgrade-doctor.json"

    stage_compose upgrade exec -T app gosu www-data php artisan koma:archive:export \
        pre-release-upgrade-fixture --schema=2 \
        --output=/var/www/html/storage/app/private/upgrade/current-v2.koma.zip \
        > "$TEMP_ROOT/upgrade-archive-export.txt"
    run_support upgrade env KOMA_UPGRADE_RUNTIME_ACTION=archives \
        php /tmp/exercise-upgrade-runtime.php > "$TEMP_ROOT/upgrade-archives.json"
    assert_http upgrade "$TEMP_ROOT/upgrade-state.json"
    exercise_runtime_services upgrade upgrade
}

damage_current_upgrade() {
    stage_compose upgrade stop worker scheduler >/dev/null
    run_support upgrade env KOMA_UPGRADE_RUNTIME_ACTION=damage \
        php /tmp/exercise-upgrade-runtime.php > "$TEMP_ROOT/upgrade-damage.json"
    jq -e '.users == 0 and .series == 0 and .pages == 0 and .private_input_exists == false' \
        "$TEMP_ROOT/upgrade-damage.json" >/dev/null
    run_support upgrade env KOMA_UPGRADE_RUNTIME_ACTION=verify-damage \
        php /tmp/exercise-upgrade-runtime.php > "$TEMP_ROOT/upgrade-damage-verified.json"
    jq -e '.users == 0 and .series == 0 and .pages == 0 and .private_input_exists == false' \
        "$TEMP_ROOT/upgrade-damage-verified.json" >/dev/null
    stage_compose upgrade stop app >/dev/null
}

restore_predecessor() {
    restore_stage rollback
    dump_database rollback "$TEMP_ROOT/rollback.sql"
    ROLLBACK_DATABASE_SHA="$(shasum -a 256 "$TEMP_ROOT/rollback.sql" | awk '{print $1}')"
    ROLLBACK_STORAGE_SHA="$(storage_inventory_hash rollback)"
    [[ "$ROLLBACK_DATABASE_SHA" == "$SOURCE_DATABASE_SHA" ]]
    [[ "$ROLLBACK_STORAGE_SHA" == "$SOURCE_STORAGE_SHA" ]]
    printf '%s\n' "$ROLLBACK_DATABASE_SHA" > "$TEMP_ROOT/rollback-database.sha256"
    printf '%s\n' "$ROLLBACK_STORAGE_SHA" > "$TEMP_ROOT/rollback-storage.sha256"
}

validate_predecessor_rollback() {
    start_application rollback
    capture_fixture_state rollback "$TEMP_ROOT/rollback-state.json"
    cmp "$SOURCE_STATE" "$TEMP_ROOT/rollback-state.json"
    assert_migration_state "$TEMP_ROOT/rollback-state.json" "$PREDECESSOR_REF"
    jq -e '(.migrations | length) == 20' "$TEMP_ROOT/rollback-state.json" >/dev/null
    assert_identity rollback "$TEMP_ROOT/rollback-identity.json"
    assert_doctor rollback "$TEMP_ROOT/rollback-doctor.json"
    assert_http rollback "$TEMP_ROOT/rollback-state.json"

    stage_compose rollback exec -T app gosu www-data php artisan koma:archive:export \
        pre-release-upgrade-fixture \
        --output=/var/www/html/storage/app/private/upgrade/rollback-v1.koma.zip \
        > "$TEMP_ROOT/rollback-archive-export.txt"
    exercise_runtime_services rollback rollback
}

capture_image_and_server_identity() {
    docker image inspect --format '{{.Id}}' "$KOMA_MARIADB_IMAGE" > "$TEMP_ROOT/mariadb-image-id.txt"
    docker image inspect --format '{{join .RepoDigests ","}}' "$KOMA_MARIADB_IMAGE" > "$TEMP_ROOT/mariadb-repo-digests.txt"
    test -s "$TEMP_ROOT/mariadb-image-id.txt"
}

assert_no_secrets_in_process_args() {
    local container_id
    local process
    local secret

    for container_id in $(docker ps -aq --filter "label=io.komaengine.release-harness.run=$RUN_ID"); do
        process="$(docker inspect --format '{{json .Path}} {{json .Args}}' "$container_id")"
        for secret in \
            "$APP_KEY_VALUE" \
            "$SOURCE_DB_PASSWORD" "$SOURCE_DB_ROOT_PASSWORD" \
            "$UPGRADE_DB_PASSWORD" "$UPGRADE_DB_ROOT_PASSWORD" \
            "$ROLLBACK_DB_PASSWORD" "$ROLLBACK_DB_ROOT_PASSWORD" \
            "$INTERRUPTED_DB_PASSWORD" "$INTERRUPTED_DB_ROOT_PASSWORD" \
            "$RECOVERY_DB_PASSWORD" "$RECOVERY_DB_ROOT_PASSWORD"; do
            [[ "$process" != *"$secret"* ]] || {
                echo 'A harness credential appeared in container process arguments.' >&2
                return 1
            }
        done
    done
}

remove_safe_image() {
    local image="$1"
    local enabled
    local run

    if ! docker image inspect "$image" >/dev/null 2>&1; then
        return 0
    fi
    enabled="$(docker image inspect --format '{{ index .Config.Labels "io.komaengine.release-harness" }}' "$image")"
    run="$(docker image inspect --format '{{ index .Config.Labels "io.komaengine.release-harness.run" }}' "$image")"
    [[ "$enabled" == true && "$run" == "$RUN_ID" ]]
    docker image rm -f "$image" >/dev/null
}

cleanup_projects() {
    local stage

    if [[ -z "$TEMP_ROOT" || ! -f "$STAGE_COMPOSE" ]]; then
        return 0
    fi
    for stage in source upgrade rollback interrupted recovery; do
        stage_compose "$stage" down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1 || true
    done
    remove_safe_image "$PREDECESSOR_IMAGE"
    remove_safe_image "$CURRENT_IMAGE"
}

verify_cleanup() {
    local project

    CLEANUP_VERIFIED=true
    for project in "$SOURCE_PROJECT" "$UPGRADE_PROJECT" "$ROLLBACK_PROJECT" "$INTERRUPTED_PROJECT" "$RECOVERY_PROJECT"; do
        if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" \
            || -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")" \
            || -n "$(docker network ls -q --filter "label=com.docker.compose.project=$project")" ]]; then
            CLEANUP_VERIFIED=false
        fi
    done
    if [[ -n "$(docker ps -aq --filter "label=io.komaengine.release-harness.run=$RUN_ID")" \
        || -n "$(docker volume ls -q --filter "label=io.komaengine.release-harness.run=$RUN_ID")" \
        || -n "$(docker network ls -q --filter "label=io.komaengine.release-harness.run=$RUN_ID")" \
        || -n "$(docker image ls -q --filter "label=io.komaengine.release-harness.run=$RUN_ID")" ]]; then
        CLEANUP_VERIFIED=false
    fi

    MAINTAINER_VOLUMES_UNCHANGED=false
    if [[ "$(maintainer_volume_fingerprint)" == "$MAINTAINER_VOLUME_FINGERPRINT_BEFORE" ]]; then
        MAINTAINER_VOLUMES_UNCHANGED=true
    fi
    [[ "$CLEANUP_VERIFIED" == true ]]
    [[ "$MAINTAINER_VOLUMES_UNCHANGED" == true ]]
}

cleanup_phase() {
    cleanup_projects
    verify_cleanup
}

write_evidence() {
    local phase_json='[]'
    local source_migrations
    local upgrade_migrations
    local rollback_migrations

    phase_json="$(jq -Rn '[inputs | split("\t") | {phase: .[0], duration_seconds: (.[1] | tonumber)}]' < "$PHASE_LOG")"
    source_migrations="$(jq -c '.migrations' "$SOURCE_STATE")"
    upgrade_migrations="$(jq -c '.migrations' "$TEMP_ROOT/upgrade-state.json")"
    rollback_migrations="$(jq -c '.migrations' "$TEMP_ROOT/rollback-state.json")"
    FINISHED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

    jq -n \
        --arg format koma-mariadb-upgrade-harness-v1 \
        --arg started_at "$STARTED_AT" \
        --arg finished_at "$FINISHED_AT" \
        --arg predecessor_ref "$PREDECESSOR_REF" \
        --arg current_ref "$CURRENT_REF" \
        --arg predecessor_image_id "$(<"$TEMP_ROOT/predecessor-image-id.txt")" \
        --arg current_image_id "$(<"$TEMP_ROOT/current-image-id.txt")" \
        --arg mariadb_image_id "$(<"$TEMP_ROOT/mariadb-image-id.txt")" \
        --arg mariadb_repo_digests "$(<"$TEMP_ROOT/mariadb-repo-digests.txt")" \
        --arg project_base "$PROJECT_BASE" \
        --arg app_key_sha "$APP_KEY_FINGERPRINT" \
        --arg source_database_sha "$(<"$TEMP_ROOT/source-database.sha256")" \
        --arg source_storage_sha "$(<"$TEMP_ROOT/source-storage.sha256")" \
        --arg source_state_sha "$(<"$TEMP_ROOT/source-state.sha256")" \
        --arg interrupted_database_sha "$(<"$TEMP_ROOT/interrupted-database.sha256")" \
        --arg recovery_database_sha "$(<"$TEMP_ROOT/recovery-database.sha256")" \
        --arg recovery_storage_sha "$(<"$TEMP_ROOT/recovery-storage.sha256")" \
        --arg upgrade_database_sha "$(<"$TEMP_ROOT/upgrade-database.sha256")" \
        --arg upgrade_storage_sha "$(<"$TEMP_ROOT/upgrade-storage.sha256")" \
        --arg rollback_database_sha "$(<"$TEMP_ROOT/rollback-database.sha256")" \
        --arg rollback_storage_sha "$(<"$TEMP_ROOT/rollback-storage.sha256")" \
        --argjson source_migrations "$source_migrations" \
        --argjson upgrade_migrations "$upgrade_migrations" \
        --argjson rollback_migrations "$rollback_migrations" \
        --argjson archives "$(jq -c . "$TEMP_ROOT/upgrade-archives.json")" \
        --argjson upgrade_runtime "$(jq -c . "$TEMP_ROOT/upgrade-runtime-restarted.json")" \
        --argjson rollback_runtime "$(jq -c . "$TEMP_ROOT/rollback-runtime-restarted.json")" \
        --argjson cleanup_verified "$CLEANUP_VERIFIED" \
        --argjson maintainer_unchanged "$MAINTAINER_VOLUMES_UNCHANGED" \
        --argjson phases "$phase_json" \
        '{format: $format, started_at: $started_at, finished_at: $finished_at, refs: {predecessor: $predecessor_ref, current: $current_ref}, images: {predecessor: {id: $predecessor_image_id}, current: {id: $current_image_id}, mariadb: {id: $mariadb_image_id, repo_digests: ($mariadb_repo_digests | split(",") | map(select(length > 0)))}}, topology: {project_base: $project_base, projects: ["source", "upgrade", "rollback", "interrupted", "recovery"]}, secrets: {app_key_sha256: $app_key_sha, credential_files_mode: "0600", process_args_clean: true}, source: {database_dump_sha256: $source_database_sha, storage_inventory_sha256: $source_storage_sha, state_sha256: $source_state_sha, migrations: $source_migrations}, interrupted_upgrade: {migration_count: 21, database_dump_sha256: $interrupted_database_sha, restored_to_fresh_target: true, recovery_database_dump_sha256: $recovery_database_sha, recovery_storage_inventory_sha256: $recovery_storage_sha}, upgrade: {database_dump_sha256_after_migration: $upgrade_database_sha, storage_inventory_sha256_before_runtime: $upgrade_storage_sha, migrations: $upgrade_migrations, migration_invocations: 1, archives: $archives, runtime: $upgrade_runtime, doctor: true, http: true, restart: true, damage_verified: true}, rollback: {database_dump_sha256: $rollback_database_sha, storage_inventory_sha256: $rollback_storage_sha, migrations: $rollback_migrations, runtime: $rollback_runtime, doctor: true, http: true, restart: true, exact: (($source_database_sha == $rollback_database_sha) and ($source_storage_sha == $rollback_storage_sha))}, phases: $phases, cleanup: {verified: $cleanup_verified, maintainer_volumes_unchanged: $maintainer_unchanged}}' \
        > "$INTERNAL_EVIDENCE"

    if [[ -n "$EVIDENCE_PATH" ]]; then
        install -m 0600 "$INTERNAL_EVIDENCE" "$EVIDENCE_PATH"
    fi
    cat "$INTERNAL_EVIDENCE"
}

on_exit() {
    local exit_code=$?

    if [[ -n "$TEMP_ROOT" ]]; then
        cleanup_projects >/dev/null 2>&1 || true
        rm -rf -- "$TEMP_ROOT"
    fi
    if [[ "$exit_code" -ne 0 ]]; then
        printf 'MariaDB upgrade/rollback harness failed during %s.\n' "$STEP" >&2
    fi
    exit "$exit_code"
}

initialize
setup_temp_root
trap on_exit EXIT
STARTED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

run_phase validate validate_inputs
run_phase export export_revisions
run_phase topology write_runtime_files
run_phase build build_images
run_phase source install_source
run_phase backup capture_source_backup
run_phase interrupted-upgrade prove_interrupted_upgrade
run_phase interrupted-recovery prove_interrupted_recovery
run_phase upgrade upgrade_current
run_phase validate-upgrade validate_current_upgrade
run_phase damage damage_current_upgrade
run_phase rollback restore_predecessor
run_phase validate-rollback validate_predecessor_rollback
run_phase identity capture_image_and_server_identity
run_phase process-scan assert_no_secrets_in_process_args
run_phase cleanup cleanup_phase
write_evidence
