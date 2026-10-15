#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$ROOT/scripts/release/image-lock.env"
APPROVED_PREDECESSOR='83eeb5911f617bf79b4e7e899a2cd69001803484'
MODE='dry-run'
PREDECESSOR_REF="$APPROVED_PREDECESSOR"
CURRENT_REF=''
PROJECT_BASE=''
EVIDENCE_PATH=''
FAIL_PHASE=''
INTERNAL_SIMULATE=false
STEP='initialization'
TEMP_ROOT=''
CLEANUP_VERIFIED=false
MAINTAINER_VOLUMES_UNCHANGED=false
RESOURCES_CREATED=false
RUN_ID=''
STARTED_AT=''
FINISHED_AT=''
PHASE_LOG=''

usage() {
    cat <<'EOF'
Usage: tests/Release/dual-revision-harness.sh [mode] [options]

Modes:
  --dry-run       Validate exact refs and the disposable topology only (default).
  --prepare       Build both refs, install predecessor state, and verify its snapshot.
  --sqlite-upgrade
                  Prove the SQLite forward upgrade and exact predecessor rollback.
  --safety-smoke  Exercise hard guards and failure cleanup for every exposed phase.

Options:
  --predecessor-ref HASH  Full immutable predecessor commit.
  --current-ref HASH      Full immutable current commit (defaults to resolved HEAD).
  --project-base NAME     Test-only deterministic base; must match koma-upgrade-<12 hex>.
  --evidence PATH         Copy machine-readable evidence to PATH.
  --fail-phase PHASE      Inject exit 97 after PHASE (for cleanup testing).
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run|--prepare|--sqlite-upgrade|--safety-smoke)
            MODE="${1#--}"
            shift
            ;;
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
        --internal-simulate)
            INTERNAL_SIMULATE=true
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown dual-revision harness argument: %s\n' "$1" >&2
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

require_safe_name() {
    local name="$1"

    [[ "$name" == "$PROJECT_BASE"-* ]] || {
        printf 'Refusing unsafe harness resource name: %s\n' "$name" >&2
        return 1
    }
    [[ "$name" != komaengine* ]] || {
        printf 'Refusing maintainer resource name: %s\n' "$name" >&2
        return 1
    }
}

resource_label() {
    printf 'io.komaengine.release-harness.run=%s\n' "$RUN_ID"
}

maintainer_volume_fingerprint() {
    docker volume inspect \
        --format '{{.Name}} {{.CreatedAt}}' \
        komaengine_koma-db komaengine_koma-storage \
        | LC_ALL=C sort \
        | shasum -a 256 \
        | awk '{print $1}'
}

verify_resource_labels() {
    local kind="$1"
    local name="$2"
    local enabled=''
    local run=''

    case "$kind" in
        container)
            enabled="$(docker container inspect --format '{{ index .Config.Labels "io.komaengine.release-harness" }}' "$name")"
            run="$(docker container inspect --format '{{ index .Config.Labels "io.komaengine.release-harness.run" }}' "$name")"
            ;;
        volume)
            enabled="$(docker volume inspect --format '{{ index .Labels "io.komaengine.release-harness" }}' "$name")"
            run="$(docker volume inspect --format '{{ index .Labels "io.komaengine.release-harness.run" }}' "$name")"
            ;;
        network)
            enabled="$(docker network inspect --format '{{ index .Labels "io.komaengine.release-harness" }}' "$name")"
            run="$(docker network inspect --format '{{ index .Labels "io.komaengine.release-harness.run" }}' "$name")"
            ;;
        image)
            enabled="$(docker image inspect --format '{{ index .Config.Labels "io.komaengine.release-harness" }}' "$name")"
            run="$(docker image inspect --format '{{ index .Config.Labels "io.komaengine.release-harness.run" }}' "$name")"
            ;;
        *)
            return 1
            ;;
    esac

    [[ "$enabled" == true && "$run" == "$RUN_ID" ]]
}

remove_safe_container() {
    local name="$1"

    require_safe_name "$name"
    if docker container inspect "$name" >/dev/null 2>&1; then
        verify_resource_labels container "$name"
        docker container rm -f "$name" >/dev/null
    fi
}

remove_safe_volume() {
    local name="$1"

    require_safe_name "$name"
    if docker volume inspect "$name" >/dev/null 2>&1; then
        verify_resource_labels volume "$name"
        docker volume rm "$name" >/dev/null
    fi
}

remove_safe_network() {
    local name="$1"

    require_safe_name "$name"
    if docker network inspect "$name" >/dev/null 2>&1; then
        verify_resource_labels network "$name"
        docker network rm "$name" >/dev/null
    fi
}

remove_safe_image() {
    local image="$1"
    local repository="${image%%:*}"

    require_safe_name "$repository"
    if docker image inspect "$image" >/dev/null 2>&1; then
        verify_resource_labels image "$image"
        docker image rm -f "$image" >/dev/null
    fi
}

initialize_topology() {
    if [[ -z "$CURRENT_REF" ]]; then
        CURRENT_REF="$(git -C "$ROOT" rev-parse HEAD)"
    fi

    if [[ -z "$PROJECT_BASE" ]]; then
        PROJECT_BASE="koma-upgrade-$(openssl rand -hex 6)"
    fi

    is_safe_project_base "$PROJECT_BASE" || {
        printf 'Project base must match koma-upgrade-<12 lowercase hex> and may not use maintainer names.\n' >&2
        exit 2
    }

    RUN_ID="${PROJECT_BASE#koma-upgrade-}"
    SOURCE_PROJECT="${PROJECT_BASE}-source"
    UPGRADE_PROJECT="${PROJECT_BASE}-upgrade"
    ROLLBACK_PROJECT="${PROJECT_BASE}-rollback"
    SOURCE_NETWORK="${PROJECT_BASE}-source-net"
    UPGRADE_NETWORK="${PROJECT_BASE}-upgrade-net"
    ROLLBACK_NETWORK="${PROJECT_BASE}-rollback-net"
    SOURCE_DB_VOLUME="${PROJECT_BASE}-source-db"
    SOURCE_STORAGE_VOLUME="${PROJECT_BASE}-source-storage"
    UPGRADE_DB_VOLUME="${PROJECT_BASE}-upgrade-db"
    UPGRADE_STORAGE_VOLUME="${PROJECT_BASE}-upgrade-storage"
    ROLLBACK_DB_VOLUME="${PROJECT_BASE}-rollback-db"
    ROLLBACK_STORAGE_VOLUME="${PROJECT_BASE}-rollback-storage"
    PREDECESSOR_IMAGE="${PROJECT_BASE}-predecessor:${PREDECESSOR_REF:0:12}"
    CURRENT_IMAGE="${PROJECT_BASE}-current:${CURRENT_REF:0:12}"
    MATRIX_IMAGE="${PROJECT_BASE}-php:8.4"
    SOURCE_PORT="$((30000 + 16#${RUN_ID:0:3} % 3000))"
    UPGRADE_PORT="$((34000 + 16#${RUN_ID:3:3} % 3000))"
    ROLLBACK_PORT="$((38000 + 16#${RUN_ID:6:3} % 2000))"
    DATABASE_NAME="koma_${RUN_ID}"
    DATABASE_FILE="${DATABASE_NAME}.sqlite"
    APP_KEY_VALUE="base64:$(openssl rand -base64 32 | tr -d '\n')"
    APP_KEY_FINGERPRINT="$(printf '%s' "$APP_KEY_VALUE" | shasum -a 256 | awk '{print $1}')"
}

setup_temp_root() {
    TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-upgrade-harness.XXXXXX")"
    PREDECESSOR_CONTEXT="$TEMP_ROOT/predecessor"
    CURRENT_CONTEXT="$TEMP_ROOT/current"
    BACKUP_ROOT="$TEMP_ROOT/pre-upgrade-backup"
    VERIFY_ROOT="$TEMP_ROOT/verify"
    INTERNAL_EVIDENCE="$TEMP_ROOT/evidence.json"
    PHASE_LOG="$TEMP_ROOT/phases.tsv"
    : > "$PHASE_LOG"
}

record_phase() {
    local phase="$1"
    local duration="$2"

    printf '%s\t%s\n' "$phase" "$duration" >> "$PHASE_LOG"
}

run_phase() {
    local phase="$1"
    local action="$2"
    local started=$SECONDS

    STEP="$phase"
    "$action"
    record_phase "$phase" "$((SECONDS - started))"

    if [[ "$FAIL_PHASE" == "$phase" ]]; then
        printf 'Injected dual-revision harness failure after %s.\n' "$phase" >&2
        return 97
    fi
}

validate_refs_and_topology() {
    full_commit "$PREDECESSOR_REF" || {
        printf 'Predecessor ref must be an available full lowercase commit: %s\n' "$PREDECESSOR_REF" >&2
        return 2
    }
    full_commit "$CURRENT_REF" || {
        printf 'Current ref must be an available full lowercase commit: %s\n' "$CURRENT_REF" >&2
        return 2
    }
    [[ "$PREDECESSOR_REF" != "$CURRENT_REF" ]] || {
        printf 'Predecessor and current refs must differ.\n' >&2
        return 2
    }
    git -C "$ROOT" merge-base --is-ancestor "$PREDECESSOR_REF" "$CURRENT_REF" || {
        printf 'The predecessor must be an ancestor of the current ref.\n' >&2
        return 2
    }

    local required
    for required in Dockerfile composer.lock package-lock.json database/migrations; do
        git -C "$ROOT" cat-file -e "${PREDECESSOR_REF}:${required}"
        git -C "$ROOT" cat-file -e "${CURRENT_REF}:${required}"
    done
    git -C "$ROOT" cat-file -e "${CURRENT_REF}:VERSION"

    local name
    for name in \
        "$SOURCE_PROJECT" "$UPGRADE_PROJECT" "$ROLLBACK_PROJECT" \
        "$SOURCE_NETWORK" "$UPGRADE_NETWORK" "$ROLLBACK_NETWORK" \
        "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" \
        "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" \
        "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME"; do
        require_safe_name "$name"
    done

    [[ "$PREDECESSOR_IMAGE" != 'komaengine:local' ]]
    [[ "$CURRENT_IMAGE" != 'komaengine:local' ]]
    [[ "$SOURCE_PORT" != "$UPGRADE_PORT" && "$SOURCE_PORT" != "$ROLLBACK_PORT" && "$UPGRADE_PORT" != "$ROLLBACK_PORT" ]]

    for name in "$SOURCE_NETWORK" "$UPGRADE_NETWORK" "$ROLLBACK_NETWORK"; do
        ! docker network inspect "$name" >/dev/null 2>&1 || {
            printf 'Disposable network already exists: %s\n' "$name" >&2
            return 2
        }
    done
    for name in \
        "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" \
        "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" \
        "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME"; do
        ! docker volume inspect "$name" >/dev/null 2>&1 || {
            printf 'Disposable volume already exists: %s\n' "$name" >&2
            return 2
        }
    done

    for name in "$PREDECESSOR_IMAGE" "$CURRENT_IMAGE" "$MATRIX_IMAGE"; do
        ! docker image inspect "$name" >/dev/null 2>&1 || {
            printf 'Disposable image already exists: %s\n' "$name" >&2
            return 2
        }
    done
    local port
    for port in "$SOURCE_PORT" "$UPGRADE_PORT" "$ROLLBACK_PORT"; do
        if docker ps --format '{{.Ports}}' | grep -Eq "([:.])${port}->"; then
            printf 'Disposable port is already published: %s\n' "$port" >&2
            return 2
        fi
    done

    MAINTAINER_VOLUME_FINGERPRINT_BEFORE="$(maintainer_volume_fingerprint)"
}

export_revisions() {
    mkdir -p "$PREDECESSOR_CONTEXT" "$CURRENT_CONTEXT"
    git -C "$ROOT" archive "$PREDECESSOR_REF" | tar -x -C "$PREDECESSOR_CONTEXT"
    git -C "$ROOT" archive "$CURRENT_REF" | tar -x -C "$CURRENT_CONTEXT"
    "$ROOT/scripts/release/pin-exported-dockerfile.sh" "$PREDECESSOR_CONTEXT/Dockerfile"
    "$ROOT/scripts/release/pin-exported-dockerfile.sh" "$CURRENT_CONTEXT/Dockerfile"
    printf '%s\n' "$PREDECESSOR_REF" > "$PREDECESSOR_CONTEXT/.koma-harness-ref"
    printf '%s\n' "$CURRENT_REF" > "$CURRENT_CONTEXT/.koma-harness-ref"
}

create_resources() {
    local name
    for name in "$SOURCE_NETWORK" "$UPGRADE_NETWORK" "$ROLLBACK_NETWORK"; do
        docker network create \
            --label io.komaengine.release-harness=true \
            --label "$(resource_label)" \
            --label "io.komaengine.release-harness.project=$PROJECT_BASE" \
            "$name" >/dev/null
    done
    for name in \
        "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" \
        "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" \
        "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME"; do
        docker volume create \
            --label io.komaengine.release-harness=true \
            --label "$(resource_label)" \
            --label "io.komaengine.release-harness.project=$PROJECT_BASE" \
            "$name" >/dev/null
    done
    RESOURCES_CREATED=true
}

build_simulation_image() {
    mkdir -p "$TEMP_ROOT/scratch"
    printf 'FROM scratch\n' > "$TEMP_ROOT/scratch/Dockerfile"
    docker build \
        --quiet \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --label "io.komaengine.release-harness.project=$PROJECT_BASE" \
        --tag "$MATRIX_IMAGE" \
        "$TEMP_ROOT/scratch" >/dev/null
}

build_images() {
    if [[ "$INTERNAL_SIMULATE" == true ]]; then
        build_simulation_image
        return
    fi

    local current_version
    local current_build_id

    current_version="$(tr -d '\r\n' < "$CURRENT_CONTEXT/VERSION")"
    current_build_id="${current_version}+${CURRENT_REF:0:12}"

    docker build \
        --quiet \
        --file "$PREDECESSOR_CONTEXT/Dockerfile" \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --label "io.komaengine.release-harness.project=$PROJECT_BASE" \
        --label "io.komaengine.release-harness.source-ref=$PREDECESSOR_REF" \
        --tag "$PREDECESSOR_IMAGE" \
        "$PREDECESSOR_CONTEXT" >/dev/null

    docker build \
        --quiet \
        --file "$CURRENT_CONTEXT/Dockerfile" \
        --build-arg "KOMA_RELEASE_VERSION=$current_version" \
        --build-arg "KOMA_RELEASE_COMMIT=$CURRENT_REF" \
        --build-arg KOMA_RELEASE_DIRTY=false \
        --build-arg "KOMA_RELEASE_BUILD_ID=$current_build_id" \
        --build-arg KOMA_TRUSTED_BUILD=true \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --label "io.komaengine.release-harness.project=$PROJECT_BASE" \
        --label "io.komaengine.release-harness.source-ref=$CURRENT_REF" \
        --tag "$CURRENT_IMAGE" \
        "$CURRENT_CONTEXT" >/dev/null

    docker build \
        --quiet \
        --file "$ROOT/tests/Release/php-matrix.Dockerfile" \
        --build-arg "PHP_IMAGE=$KOMA_PHP_84_CLI_IMAGE" \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --label "io.komaengine.release-harness.project=$PROJECT_BASE" \
        --tag "$MATRIX_IMAGE" \
        "$ROOT/tests/Release" >/dev/null
}

app_container() {
    local stage="$1"
    local action="$2"
    local database_volume="$3"
    local storage_volume="$4"
    local app_root="$5"
    local network
    local port
    local expected_ref
    shift 5

    case "$stage" in
        source)
            network="$SOURCE_NETWORK"
            port="$SOURCE_PORT"
            expected_ref="$PREDECESSOR_REF"
            [[ "$database_volume" == "$SOURCE_DB_VOLUME" \
                && "$storage_volume" == "$SOURCE_STORAGE_VOLUME" \
                && "$app_root" == "$PREDECESSOR_CONTEXT" ]] || {
                echo 'Source stage resource pairing is invalid.' >&2
                return 86
            }
            ;;
        verify)
            network="$SOURCE_NETWORK"
            port="$SOURCE_PORT"
            expected_ref="$PREDECESSOR_REF"
            [[ "$database_volume" == "$VERIFY_ROOT/database" \
                && "$storage_volume" == "$VERIFY_ROOT/storage" \
                && "$app_root" == "$PREDECESSOR_CONTEXT" ]] || {
                echo 'Backup verification resource pairing is invalid.' >&2
                return 86
            }
            ;;
        upgrade)
            network="$UPGRADE_NETWORK"
            port="$UPGRADE_PORT"
            expected_ref="$CURRENT_REF"
            [[ "$database_volume" == "$UPGRADE_DB_VOLUME" \
                && "$storage_volume" == "$UPGRADE_STORAGE_VOLUME" \
                && "$app_root" == "$CURRENT_CONTEXT" ]] || {
                echo 'Upgrade stage resource pairing is invalid.' >&2
                return 86
            }
            ;;
        rollback)
            network="$ROLLBACK_NETWORK"
            port="$ROLLBACK_PORT"
            expected_ref="$PREDECESSOR_REF"
            [[ "$database_volume" == "$ROLLBACK_DB_VOLUME" \
                && "$storage_volume" == "$ROLLBACK_STORAGE_VOLUME" \
                && "$app_root" == "$PREDECESSOR_CONTEXT" ]] || {
                echo 'Rollback stage resource pairing is invalid.' >&2
                return 86
            }
            ;;
        *)
            printf 'Unknown application stage: %s\n' "$stage" >&2
            return 2
            ;;
    esac

    docker run --rm \
        --name "${PROJECT_BASE}-${stage}-${action}" \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --network "$network" \
        --env APP_ENV=testing \
        --env APP_DEBUG=false \
        --env "APP_KEY=$APP_KEY_VALUE" \
        --env APP_URL="http://127.0.0.1:$port" \
        --env LOG_CHANNEL=stderr \
        --env DB_CONNECTION=sqlite \
        --env "DB_DATABASE=/harness-db/$DATABASE_FILE" \
        --env CACHE_STORE=array \
        --env SESSION_DRIVER=array \
        --env QUEUE_CONNECTION=database \
        --env FILESYSTEM_DISK=public \
        --env MEDIA_DISK_DRIVER=local \
        --env KOMA_APP_ROOT=/app \
        --env "KOMA_HARNESS_EXPECTED_REF=$expected_ref" \
        --volume "$database_volume:/harness-db" \
        --volume "$storage_volume:/app/storage" \
        --volume "$app_root:/app" \
        --volume "$ROOT/tests/Release/support/exercise-upgrade-fixture.php:/tmp/exercise-upgrade-fixture.php:ro" \
        --volume "$ROOT/tests/Release/support/exercise-upgrade-runtime.php:/tmp/exercise-upgrade-runtime.php:ro" \
        --workdir /app \
        "$MATRIX_IMAGE" sh -lc '
            actual_ref="$(cat /app/.koma-harness-ref)"
            if [ "$actual_ref" != "$KOMA_HARNESS_EXPECTED_REF" ]; then
                echo "Application/database stage contract mismatch." >&2
                exit 86
            fi
            exec "$@"
        ' sh "$@"
}

fixture_container() {
    local action="$1"
    local database_volume="$2"
    local storage_volume="$3"
    local app_root="$4"
    shift 4

    app_container verify "$action" "$database_volume" "$storage_volume" "$app_root" "$@"
}

install_predecessor_fixture() {
    if [[ "$INTERNAL_SIMULATE" == true ]]; then
        docker run --rm \
            --label io.komaengine.release-harness=true \
            --label "$(resource_label)" \
            --volume "$SOURCE_DB_VOLUME:/state" \
            --entrypoint /bin/sh \
            "$MATRIX_IMAGE" -c 'printf installed > /state/simulated' 2>/dev/null || true
        return
    fi

    local vendor_container="${PROJECT_BASE}-vendor-predecessor"
    local current_vendor_container="${PROJECT_BASE}-vendor-current"
    STEP='install-predecessor-vendor'
    docker create \
        --name "$vendor_container" \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        "$PREDECESSOR_IMAGE" web >/dev/null
    docker cp "$vendor_container:/var/www/html/vendor" "$PREDECESSOR_CONTEXT/vendor"
    docker rm "$vendor_container" >/dev/null

    STEP='install-current-vendor'
    docker create \
        --name "$current_vendor_container" \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        "$CURRENT_IMAGE" web >/dev/null
    docker cp "$current_vendor_container:/var/www/html/vendor" "$CURRENT_CONTEXT/vendor"
    docker rm "$current_vendor_container" >/dev/null

    rm -f "$PREDECESSOR_CONTEXT/public/storage" "$CURRENT_CONTEXT/public/storage"
    ln -s ../storage/app/public "$PREDECESSOR_CONTEXT/public/storage"
    ln -s ../storage/app/public "$CURRENT_CONTEXT/public/storage"

    STEP='install-database'
    app_container source initialize "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        sh -lc "mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && touch '/harness-db/$DATABASE_FILE' && php artisan migrate --force --no-interaction >/dev/null && php artisan db:seed --class=RolePermissionSeeder --force --no-interaction >/dev/null"
    STEP='install-fixture'
    app_container source fixture-create "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        env KOMA_UPGRADE_FIXTURE_ACTION=create php /tmp/exercise-upgrade-fixture.php \
        > "$TEMP_ROOT/source-fixture-create.json"
    STEP='install-archive-v1'
    if ! app_container source archive-v1 "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        php artisan koma:archive:export pre-release-upgrade-fixture \
            --output=/app/storage/app/private/upgrade/predecessor-v1.koma.zip \
        > "$TEMP_ROOT/source-archive-export.txt"; then
        cat "$TEMP_ROOT/source-archive-export.txt" >&2
        return 1
    fi
    STEP='install-fixture-state'
    app_container source fixture-state "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        env KOMA_UPGRADE_FIXTURE_ACTION=verify php /tmp/exercise-upgrade-fixture.php \
        > "$TEMP_ROOT/source-fixture.json"
    jq -e '.format == "koma-upgrade-fixture-v1" and (.migrations | length) == 20' "$TEMP_ROOT/source-fixture.json" >/dev/null
}

capture_pre_upgrade_backup() {
    if [[ "$INTERNAL_SIMULATE" == true ]]; then
        mkdir -p "$BACKUP_ROOT"
        printf 'simulated\n' > "$BACKUP_ROOT/marker"
        return
    fi

    mkdir -p "$BACKUP_ROOT"
    docker run --rm \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --volume "$SOURCE_DB_VOLUME:/source-db:ro" \
        --volume "$SOURCE_STORAGE_VOLUME:/source-storage:ro" \
        --volume "$BACKUP_ROOT:/backup" \
        --entrypoint sh \
        "$MATRIX_IMAGE" -lc \
        "cp '/source-db/$DATABASE_FILE' /backup/database.sqlite && tar -cf /backup/storage.tar -C /source-storage ."

    cp "$PREDECESSOR_CONTEXT/composer.lock" "$BACKUP_ROOT/composer.lock"
    cp "$PREDECESSOR_CONTEXT/package-lock.json" "$BACKUP_ROOT/package-lock.json"
    cp "$TEMP_ROOT/source-fixture.json" "$BACKUP_ROOT/fixture-state.json"
    git -C "$ROOT" ls-tree -r --name-only "$PREDECESSOR_REF" database/migrations | LC_ALL=C sort > "$BACKUP_ROOT/predecessor-migrations.txt"
    git -C "$ROOT" ls-tree -r --name-only "$CURRENT_REF" database/migrations | LC_ALL=C sort > "$BACKUP_ROOT/current-migrations.txt"

    jq -n \
        --arg format koma-dual-revision-backup-v1 \
        --arg predecessor_ref "$PREDECESSOR_REF" \
        --arg predecessor_image_id "$(docker image inspect --format '{{.Id}}' "$PREDECESSOR_IMAGE")" \
        --arg app_key_fingerprint "$APP_KEY_FINGERPRINT" \
        --arg database_file "$DATABASE_FILE" \
        '{format: $format, predecessor_ref: $predecessor_ref, predecessor_image_id: $predecessor_image_id, app_key_fingerprint: $app_key_fingerprint, database_file: $database_file, quiesced: true}' \
        > "$BACKUP_ROOT/metadata.json"

    (
        cd "$BACKUP_ROOT"
        shasum -a 256 \
            composer.lock \
            current-migrations.txt \
            database.sqlite \
            fixture-state.json \
            metadata.json \
            package-lock.json \
            predecessor-migrations.txt \
            storage.tar \
            > SHA256SUMS
    )
}

verify_pre_upgrade_backup() {
    if [[ "$INTERNAL_SIMULATE" == true ]]; then
        test -f "$BACKUP_ROOT/marker"
        return
    fi

    (
        cd "$BACKUP_ROOT"
        shasum -a 256 -c SHA256SUMS >/dev/null
    )
    jq -e \
        --arg predecessor_ref "$PREDECESSOR_REF" \
        '.format == "koma-dual-revision-backup-v1" and .predecessor_ref == $predecessor_ref and .quiesced == true' \
        "$BACKUP_ROOT/metadata.json" >/dev/null
    test "$(wc -l < "$BACKUP_ROOT/predecessor-migrations.txt" | tr -d ' ')" = 20
    test "$(wc -l < "$BACKUP_ROOT/current-migrations.txt" | tr -d ' ')" = 22

    mkdir -p "$VERIFY_ROOT/storage" "$VERIFY_ROOT/database"
    cp "$BACKUP_ROOT/database.sqlite" "$VERIFY_ROOT/database/$DATABASE_FILE"
    tar -xf "$BACKUP_ROOT/storage.tar" -C "$VERIFY_ROOT/storage"
    fixture_container verify "$VERIFY_ROOT/database" "$VERIFY_ROOT/storage" "$PREDECESSOR_CONTEXT" \
        env KOMA_UPGRADE_FIXTURE_ACTION=verify php /tmp/exercise-upgrade-fixture.php \
        > "$VERIFY_ROOT/fixture-state.json"
    cmp "$BACKUP_ROOT/fixture-state.json" "$VERIFY_ROOT/fixture-state.json"
}

volume_file_hash() {
    local volume="$1"
    local path="$2"

    docker run --rm \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --volume "$volume:/state:ro" \
        --entrypoint sh \
        "$MATRIX_IMAGE" -lc "sha256sum '/state/$path' | awk '{print \$1}'"
}

volume_inventory_hash() {
    local volume="$1"
    # "protected" lists the predecessor's public manga media at the paths the
    # current release's koma:media:protect moves it to, so an upgraded
    # inventory can be compared with its source file for file.
    local layout="${2:-as-stored}"
    local relocate='cat'

    if [[ "$layout" == protected ]]; then
        relocate="sed 's#  \\./app/public/media/#  ./app/media/#'"
    fi

    docker run --rm \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --volume "$volume:/state:ro" \
        --entrypoint sh \
        "$MATRIX_IMAGE" -lc \
        "cd /state && find . -type f -exec sha256sum {} \\; | $relocate | LC_ALL=C sort | sha256sum | awk '{print \$1}'"
}

copy_stage_volumes() {
    local source_database="$1"
    local source_storage="$2"
    local target_database="$3"
    local target_storage="$4"

    docker run --rm \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --volume "$source_database:/source-db:ro" \
        --volume "$source_storage:/source-storage:ro" \
        --volume "$target_database:/target-db" \
        --volume "$target_storage:/target-storage" \
        --entrypoint sh \
        "$MATRIX_IMAGE" -lc \
        'cp -a /source-db/. /target-db/ && cp -a /source-storage/. /target-storage/'
}

restore_predecessor_snapshot() {
    docker run --rm \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --volume "$ROLLBACK_DB_VOLUME:/rollback-db" \
        --volume "$ROLLBACK_STORAGE_VOLUME:/rollback-storage" \
        --volume "$BACKUP_ROOT:/backup:ro" \
        --entrypoint sh \
        "$MATRIX_IMAGE" -lc \
        "cp '/backup/database.sqlite' '/rollback-db/$DATABASE_FILE' && tar -xf /backup/storage.tar -C /rollback-storage"
}

assert_migration_state() {
    local state_file="$1"
    local expected_file="$2"

    jq -r '.migrations[]' "$state_file" > "$TEMP_ROOT/actual-migrations.txt"
    sed -e 's#database/migrations/##' -e 's#\.php$##' "$expected_file" > "$TEMP_ROOT/expected-migrations.txt"
    cmp "$TEMP_ROOT/expected-migrations.txt" "$TEMP_ROOT/actual-migrations.txt"
}

start_stage_web() {
    local stage="$1"
    local database_volume
    local storage_volume
    local app_root
    local network
    local port
    local expected_ref
    local name="${PROJECT_BASE}-${stage}-web"

    case "$stage" in
        upgrade)
            database_volume="$UPGRADE_DB_VOLUME"
            storage_volume="$UPGRADE_STORAGE_VOLUME"
            app_root="$CURRENT_CONTEXT"
            network="$UPGRADE_NETWORK"
            port="$UPGRADE_PORT"
            expected_ref="$CURRENT_REF"
            ;;
        rollback)
            database_volume="$ROLLBACK_DB_VOLUME"
            storage_volume="$ROLLBACK_STORAGE_VOLUME"
            app_root="$PREDECESSOR_CONTEXT"
            network="$ROLLBACK_NETWORK"
            port="$ROLLBACK_PORT"
            expected_ref="$PREDECESSOR_REF"
            ;;
        *)
            printf 'Unknown web stage: %s\n' "$stage" >&2
            return 2
            ;;
    esac

    docker run -d --rm \
        --name "$name" \
        --label io.komaengine.release-harness=true \
        --label "$(resource_label)" \
        --network "$network" \
        --publish "127.0.0.1:${port}:8080" \
        --env APP_ENV=testing \
        --env APP_DEBUG=false \
        --env "APP_KEY=$APP_KEY_VALUE" \
        --env "APP_URL=http://127.0.0.1:$port" \
        --env LOG_CHANNEL=stderr \
        --env DB_CONNECTION=sqlite \
        --env "DB_DATABASE=/harness-db/$DATABASE_FILE" \
        --env CACHE_STORE=array \
        --env SESSION_DRIVER=array \
        --env QUEUE_CONNECTION=database \
        --env FILESYSTEM_DISK=public \
        --env MEDIA_DISK_DRIVER=local \
        --env "KOMA_HARNESS_EXPECTED_REF=$expected_ref" \
        --volume "$database_volume:/harness-db" \
        --volume "$storage_volume:/app/storage" \
        --volume "$app_root:/app" \
        --volume "$ROOT/tests/Release/support/recovery-router.php:/tmp/recovery-router.php:ro" \
        --workdir /app \
        "$MATRIX_IMAGE" sh -lc '
            test "$(cat /app/.koma-harness-ref)" = "$KOMA_HARNESS_EXPECTED_REF"
            exec php -S 0.0.0.0:8080 -t public /tmp/recovery-router.php
        ' >/dev/null

    for attempt in $(seq 1 30); do
        if curl -fsS "http://127.0.0.1:$port/up" >/dev/null 2>&1; then
            return 0
        fi
        if [[ "$attempt" -eq 30 ]]; then
            docker logs "$name" >&2 || true
            printf '%s web application did not become ready.\n' "$stage" >&2
            return 1
        fi
        sleep 1
    done
}

assert_stage_http() {
    local stage="$1"
    local port
    local storage_volume
    local media_hash
    local admin_status
    local download="$TEMP_ROOT/${stage}-reader.png"

    case "$stage" in
        upgrade)
            port="$UPGRADE_PORT"
            storage_volume="$UPGRADE_STORAGE_VOLUME"
            media_url='/media/series/pre-release-upgrade-fixture/chapters/1/pages/1'
            media_path='app/media/upgrade/reader.png'
            ;;
        rollback)
            port="$ROLLBACK_PORT"
            storage_volume="$ROLLBACK_STORAGE_VOLUME"
            media_url='/storage/media/upgrade/reader.png'
            media_path='app/public/media/upgrade/reader.png'
            ;;
        *)
            return 2
            ;;
    esac

    curl -fsS "http://127.0.0.1:$port/" | grep -Fq 'Pre-release Upgrade Fixture'
    curl -fsS "http://127.0.0.1:$port/series/pre-release-upgrade-fixture" | grep -Fq 'Pre-release Upgrade Fixture'
    curl -fsS "http://127.0.0.1:$port/series/pre-release-upgrade-fixture/chapter/1" | grep -Fq 'Published predecessor chapter'
    curl -fsS "http://127.0.0.1:$port$media_url" > "$download"
    media_hash="$(volume_file_hash "$storage_volume" "$media_path")"
    test "$(shasum -a 256 "$download" | awk '{print $1}')" = "$media_hash"
    admin_status="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/admin")"
    [[ "$admin_status" == 302 ]]
}

restart_stage_web() {
    local stage="$1"
    local port
    local name="${PROJECT_BASE}-${stage}-web"

    case "$stage" in
        upgrade) port="$UPGRADE_PORT" ;;
        rollback) port="$ROLLBACK_PORT" ;;
        *) return 2 ;;
    esac

    docker restart "$name" >/dev/null

    for attempt in $(seq 1 30); do
        if curl -fsS "http://127.0.0.1:$port/up" >/dev/null 2>&1; then
            assert_stage_http "$stage"
            return 0
        fi
        if [[ "$attempt" -eq 30 ]]; then
            docker logs "$name" >&2 || true
            printf '%s web application did not recover after restart.\n' "$stage" >&2
            return 1
        fi
        sleep 1
    done
}

stop_stage_web() {
    local stage="$1"
    remove_safe_container "${PROJECT_BASE}-${stage}-web"
}

upgrade_sqlite_copy() {
    copy_stage_volumes \
        "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" \
        "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME"

    SOURCE_DATABASE_SHA="$(volume_file_hash "$SOURCE_DB_VOLUME" "$DATABASE_FILE")"
    SOURCE_STORAGE_SHA="$(volume_inventory_hash "$SOURCE_STORAGE_VOLUME")"
    printf '%s\n' "$SOURCE_DATABASE_SHA" > "$TEMP_ROOT/source-database.sha256"
    printf '%s\n' "$SOURCE_STORAGE_SHA" > "$TEMP_ROOT/source-storage.sha256"

    app_container upgrade migrate "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        php artisan migrate --force --no-interaction \
        > "$TEMP_ROOT/upgrade-migrate.txt"
    app_container upgrade protect-media "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        php artisan koma:media:protect --no-interaction \
        > "$TEMP_ROOT/upgrade-media-protect.txt"
    printf '1\n' > "$TEMP_ROOT/upgrade-migration-invocations.txt"

    app_container upgrade migration-status "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        php artisan migrate:status --no-interaction \
        > "$TEMP_ROOT/upgrade-migration-status.txt"

    app_container upgrade verify "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        env KOMA_UPGRADE_FIXTURE_ACTION=verify php /tmp/exercise-upgrade-fixture.php \
        > "$TEMP_ROOT/upgrade-fixture.json"
    assert_migration_state "$TEMP_ROOT/upgrade-fixture.json" "$BACKUP_ROOT/current-migrations.txt"

    jq -S 'del(.migrations)' "$TEMP_ROOT/source-fixture.json" > "$TEMP_ROOT/source-durable.json"
    jq -S 'del(.migrations)' "$TEMP_ROOT/upgrade-fixture.json" > "$TEMP_ROOT/upgrade-durable.json"
    cmp "$TEMP_ROOT/source-durable.json" "$TEMP_ROOT/upgrade-durable.json"

    comm -13 \
        <(jq -r '.migrations[]' "$TEMP_ROOT/source-fixture.json") \
        <(jq -r '.migrations[]' "$TEMP_ROOT/upgrade-fixture.json") \
        > "$TEMP_ROOT/upgrade-migration-delta.txt"
    diff -u <(printf '%s\n' \
        2026_07_29_001100_add_archive_v2_metadata \
        2026_08_01_000100_add_admin_dashboard_preferences_to_users) \
        "$TEMP_ROOT/upgrade-migration-delta.txt"

    UPGRADE_DATABASE_SHA="$(volume_file_hash "$UPGRADE_DB_VOLUME" "$DATABASE_FILE")"
    UPGRADE_STORAGE_SHA="$(volume_inventory_hash "$UPGRADE_STORAGE_VOLUME")"
    test "$UPGRADE_DATABASE_SHA" != "$SOURCE_DATABASE_SHA"
    # The upgrade moves manga media into protected storage and changes nothing
    # else: the same files with the same bytes, at the protected paths.
    test "$UPGRADE_STORAGE_SHA" != "$SOURCE_STORAGE_SHA"
    test "$UPGRADE_STORAGE_SHA" = "$(volume_inventory_hash "$SOURCE_STORAGE_VOLUME" protected)"
    printf '%s\n' "$UPGRADE_DATABASE_SHA" > "$TEMP_ROOT/upgrade-database.sha256"
    printf '%s\n' "$UPGRADE_STORAGE_SHA" > "$TEMP_ROOT/upgrade-storage.sha256"
}

validate_upgraded_sqlite() {
    app_container upgrade identity "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=identity php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/upgrade-identity.json"
    jq -e \
        --arg key_sha "$APP_KEY_FINGERPRINT" \
        '.password_verified == true and .active_theme == "aozora" and (.enabled_plugins | index("hello-koma")) != null and .hello_koma_compatible == true and .hello_koma_failure == null and .app_key_sha256 == $key_sha' \
        "$TEMP_ROOT/upgrade-identity.json" >/dev/null

    app_container upgrade doctor "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        php artisan koma:doctor --mode=operational --json \
        > "$TEMP_ROOT/upgrade-doctor.json"
    jq -e '.ready == true and ([.checks[]] | all)' "$TEMP_ROOT/upgrade-doctor.json" >/dev/null

    app_container upgrade archive-v2 "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        php artisan koma:archive:export pre-release-upgrade-fixture \
            --schema=2 --output=/app/storage/app/private/upgrade/current-v2.koma.zip \
        > "$TEMP_ROOT/upgrade-archive-export.txt"
    app_container upgrade archives "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=archives php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/upgrade-archives.json"

    start_stage_web upgrade
    assert_stage_http upgrade
    restart_stage_web upgrade
    printf 'true\n' > "$TEMP_ROOT/upgrade-http-restart.ok"

    app_container upgrade queue "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=queue php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/upgrade-queue.txt"
    app_container upgrade scheduler "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=scheduler php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/upgrade-scheduler.txt"
    app_container upgrade doctor-after-runtime "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        php artisan koma:doctor --mode=operational --json \
        > "$TEMP_ROOT/upgrade-doctor-after-runtime.json"
    jq -e '.ready == true and ([.checks[]] | all)' "$TEMP_ROOT/upgrade-doctor-after-runtime.json" >/dev/null
}

damage_upgraded_sqlite() {
    STEP='damage-stop-web'
    stop_stage_web upgrade
    STEP='damage-apply'
    if ! app_container upgrade damage "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=damage php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/upgrade-damage.txt"; then
        cat "$TEMP_ROOT/upgrade-damage.txt" >&2
        return 1
    fi
    if ! jq -e '.users == 0 and .series == 0 and .pages == 0 and .private_input_exists == false' \
        "$TEMP_ROOT/upgrade-damage.txt" >/dev/null; then
        cat "$TEMP_ROOT/upgrade-damage.txt" >&2
        return 1
    fi

    STEP='damage-verify-state'
    if ! app_container upgrade verify-damage "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" "$CURRENT_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=verify-damage php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/upgrade-damage-verified.json"; then
        cat "$TEMP_ROOT/upgrade-damage-verified.json" >&2
        return 1
    fi
    jq -e '.users == 0 and .series == 0 and .pages == 0 and .private_input_exists == false' \
        "$TEMP_ROOT/upgrade-damage-verified.json" >/dev/null
    STEP='damage-verify-media'
    test "$(volume_file_hash "$UPGRADE_STORAGE_VOLUME" app/media/upgrade/reader.png)" != \
        "$(jq -r '.files.media.sha256' "$TEMP_ROOT/source-fixture.json")"
    printf 'true\n' > "$TEMP_ROOT/upgrade-damage-verified.ok"
}

rollback_predecessor_sqlite() {
    restore_predecessor_snapshot

    app_container rollback verify "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        env KOMA_UPGRADE_FIXTURE_ACTION=verify php /tmp/exercise-upgrade-fixture.php \
        > "$TEMP_ROOT/rollback-fixture.json"
    assert_migration_state "$TEMP_ROOT/rollback-fixture.json" "$BACKUP_ROOT/predecessor-migrations.txt"
    cmp "$TEMP_ROOT/source-fixture.json" "$TEMP_ROOT/rollback-fixture.json"

    ROLLBACK_DATABASE_SHA="$(volume_file_hash "$ROLLBACK_DB_VOLUME" "$DATABASE_FILE")"
    ROLLBACK_STORAGE_SHA="$(volume_inventory_hash "$ROLLBACK_STORAGE_VOLUME")"
    test "$ROLLBACK_DATABASE_SHA" = "$SOURCE_DATABASE_SHA"
    test "$ROLLBACK_STORAGE_SHA" = "$SOURCE_STORAGE_SHA"
    printf '%s\n' "$ROLLBACK_DATABASE_SHA" > "$TEMP_ROOT/rollback-database.sha256"
    printf '%s\n' "$ROLLBACK_STORAGE_SHA" > "$TEMP_ROOT/rollback-storage.sha256"
}

validate_rolled_back_sqlite() {
    app_container rollback identity "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=identity php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/rollback-identity.json"
    jq -e \
        --arg key_sha "$APP_KEY_FINGERPRINT" \
        '.password_verified == true and .active_theme == "aozora" and (.enabled_plugins | index("hello-koma")) != null and .hello_koma_compatible == true and .hello_koma_failure == null and .app_key_sha256 == $key_sha' \
        "$TEMP_ROOT/rollback-identity.json" >/dev/null

    app_container rollback doctor "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        php artisan koma:doctor --mode=operational --json \
        > "$TEMP_ROOT/rollback-doctor.json"
    jq -e '.ready == true and ([.checks[]] | all)' "$TEMP_ROOT/rollback-doctor.json" >/dev/null

    app_container rollback archive-v1 "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        php artisan koma:archive:export pre-release-upgrade-fixture \
            --output=/app/storage/app/private/upgrade/rollback-v1.koma.zip \
        > "$TEMP_ROOT/rollback-archive-export.txt"
    test -s "$TEMP_ROOT/rollback-archive-export.txt"

    start_stage_web rollback
    assert_stage_http rollback
    restart_stage_web rollback
    printf 'true\n' > "$TEMP_ROOT/rollback-http-restart.ok"

    app_container rollback queue "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=queue php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/rollback-queue.txt"
    app_container rollback scheduler "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        env KOMA_UPGRADE_RUNTIME_ACTION=scheduler php /tmp/exercise-upgrade-runtime.php \
        > "$TEMP_ROOT/rollback-scheduler.txt"
    app_container rollback doctor-after-runtime "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME" "$PREDECESSOR_CONTEXT" \
        php artisan koma:doctor --mode=operational --json \
        > "$TEMP_ROOT/rollback-doctor-after-runtime.json"
    jq -e '.ready == true and ([.checks[]] | all)' "$TEMP_ROOT/rollback-doctor-after-runtime.json" >/dev/null

    stop_stage_web rollback
}

simulate_future_phase() {
    printf '%s\n' "$STEP" > "$TEMP_ROOT/${STEP}.marker"
}

cleanup_resources() {
    local name
    local container_id

    for container_id in $(docker ps -aq --filter "label=io.komaengine.release-harness.run=$RUN_ID"); do
        name="$(docker container inspect --format '{{.Name}}' "$container_id" | sed 's#^/##')"
        remove_safe_container "$name"
    done
    for name in "$PREDECESSOR_IMAGE" "$CURRENT_IMAGE" "$MATRIX_IMAGE"; do
        remove_safe_image "$name"
    done
    for name in \
        "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" \
        "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" \
        "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME"; do
        remove_safe_volume "$name"
    done
    for name in "$SOURCE_NETWORK" "$UPGRADE_NETWORK" "$ROLLBACK_NETWORK"; do
        remove_safe_network "$name"
    done

    CLEANUP_VERIFIED=true
    for name in "$PREDECESSOR_IMAGE" "$CURRENT_IMAGE" "$MATRIX_IMAGE"; do
        ! docker image inspect "$name" >/dev/null 2>&1 || CLEANUP_VERIFIED=false
    done
    for name in \
        "$SOURCE_DB_VOLUME" "$SOURCE_STORAGE_VOLUME" \
        "$UPGRADE_DB_VOLUME" "$UPGRADE_STORAGE_VOLUME" \
        "$ROLLBACK_DB_VOLUME" "$ROLLBACK_STORAGE_VOLUME"; do
        ! docker volume inspect "$name" >/dev/null 2>&1 || CLEANUP_VERIFIED=false
    done
    for name in "$SOURCE_NETWORK" "$UPGRADE_NETWORK" "$ROLLBACK_NETWORK"; do
        ! docker network inspect "$name" >/dev/null 2>&1 || CLEANUP_VERIFIED=false
    done
    MAINTAINER_VOLUMES_UNCHANGED=false
    if [[ -n "${MAINTAINER_VOLUME_FINGERPRINT_BEFORE:-}" ]] \
        && [[ "$(maintainer_volume_fingerprint)" == "$MAINTAINER_VOLUME_FINGERPRINT_BEFORE" ]]; then
        MAINTAINER_VOLUMES_UNCHANGED=true
    fi
    [[ "$CLEANUP_VERIFIED" == true ]]
    [[ "$MAINTAINER_VOLUMES_UNCHANGED" == true ]]
}

write_evidence() {
    local predecessor_image_id='not-built'
    local current_image_id='not-built'
    local predecessor_migrations
    local current_migrations
    local predecessor_migrations_sha
    local current_migrations_sha
    local fixture_sha='not-created'
    local backup_manifest_sha='not-created'
    local phase_json='[]'
    local sqlite_json='null'

    predecessor_migrations="$(git -C "$ROOT" ls-tree -r --name-only "$PREDECESSOR_REF" database/migrations | wc -l | tr -d ' ')"
    current_migrations="$(git -C "$ROOT" ls-tree -r --name-only "$CURRENT_REF" database/migrations | wc -l | tr -d ' ')"
    predecessor_migrations_sha="$(git -C "$ROOT" ls-tree -r --name-only "$PREDECESSOR_REF" database/migrations | LC_ALL=C sort | shasum -a 256 | awk '{print $1}')"
    current_migrations_sha="$(git -C "$ROOT" ls-tree -r --name-only "$CURRENT_REF" database/migrations | LC_ALL=C sort | shasum -a 256 | awk '{print $1}')"

    if [[ -f "$TEMP_ROOT/image-identities.tsv" ]]; then
        predecessor_image_id="$(sed -n '1p' "$TEMP_ROOT/image-identities.tsv")"
        current_image_id="$(sed -n '2p' "$TEMP_ROOT/image-identities.tsv")"
    fi
    if [[ -f "$TEMP_ROOT/source-fixture.json" ]]; then
        fixture_sha="$(shasum -a 256 "$TEMP_ROOT/source-fixture.json" | awk '{print $1}')"
    fi
    if [[ -f "$BACKUP_ROOT/SHA256SUMS" ]]; then
        backup_manifest_sha="$(shasum -a 256 "$BACKUP_ROOT/SHA256SUMS" | awk '{print $1}')"
    fi
    if [[ -s "$PHASE_LOG" ]]; then
        phase_json="$(jq -Rn '[inputs | split("\t") | {phase: .[0], duration_seconds: (.[1] | tonumber)}]' < "$PHASE_LOG")"
    fi
    if [[ "$MODE" == sqlite-upgrade && -f "$TEMP_ROOT/rollback-fixture.json" ]]; then
        sqlite_json="$(jq -n \
            --arg source_database_sha "$(<"$TEMP_ROOT/source-database.sha256")" \
            --arg source_storage_sha "$(<"$TEMP_ROOT/source-storage.sha256")" \
            --arg source_state_sha "$(shasum -a 256 "$TEMP_ROOT/source-fixture.json" | awk '{print $1}')" \
            --arg upgrade_database_sha "$(<"$TEMP_ROOT/upgrade-database.sha256")" \
            --arg upgrade_storage_sha "$(<"$TEMP_ROOT/upgrade-storage.sha256")" \
            --arg upgrade_state_sha "$(shasum -a 256 "$TEMP_ROOT/upgrade-fixture.json" | awk '{print $1}')" \
            --arg durable_state_sha "$(shasum -a 256 "$TEMP_ROOT/source-durable.json" | awk '{print $1}')" \
            --arg rollback_database_sha "$(<"$TEMP_ROOT/rollback-database.sha256")" \
            --arg rollback_storage_sha "$(<"$TEMP_ROOT/rollback-storage.sha256")" \
            --arg rollback_state_sha "$(shasum -a 256 "$TEMP_ROOT/rollback-fixture.json" | awk '{print $1}')" \
            --arg migration_status_sha "$(shasum -a 256 "$TEMP_ROOT/upgrade-migration-status.txt" | awk '{print $1}')" \
            --argjson source_migrations "$(jq -c '.migrations' "$TEMP_ROOT/source-fixture.json")" \
            --argjson upgrade_migrations "$(jq -c '.migrations' "$TEMP_ROOT/upgrade-fixture.json")" \
            --argjson rollback_migrations "$(jq -c '.migrations' "$TEMP_ROOT/rollback-fixture.json")" \
            --argjson archives "$(jq -c . "$TEMP_ROOT/upgrade-archives.json")" \
            --argjson upgrade_doctor "$(jq -c . "$TEMP_ROOT/upgrade-doctor-after-runtime.json")" \
            --argjson rollback_doctor "$(jq -c . "$TEMP_ROOT/rollback-doctor-after-runtime.json")" \
            '{source: {database_sha256: $source_database_sha, storage_inventory_sha256: $source_storage_sha, state_sha256: $source_state_sha, migrations: $source_migrations}, upgrade: {database_sha256_after_migration: $upgrade_database_sha, storage_inventory_sha256_before_runtime: $upgrade_storage_sha, state_sha256: $upgrade_state_sha, durable_state_sha256: $durable_state_sha, migrations: $upgrade_migrations, migration_invocations: 1, migration_status_sha256: $migration_status_sha, archives: $archives, doctor: $upgrade_doctor, http_restart: true, queue: true, scheduler: true}, damage_verified: true, rollback: {database_sha256: $rollback_database_sha, storage_inventory_sha256: $rollback_storage_sha, state_sha256: $rollback_state_sha, migrations: $rollback_migrations, doctor: $rollback_doctor, http_restart: true, queue: true, scheduler: true}, exact_rollback: (($source_database_sha == $rollback_database_sha) and ($source_storage_sha == $rollback_storage_sha) and ($source_state_sha == $rollback_state_sha))}' \
        )"
    fi

    FINISHED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
    jq -n \
        --arg format koma-dual-revision-harness-v1 \
        --arg mode "$MODE" \
        --arg started_at "$STARTED_AT" \
        --arg finished_at "$FINISHED_AT" \
        --arg predecessor_ref "$PREDECESSOR_REF" \
        --arg current_ref "$CURRENT_REF" \
        --arg predecessor_image "$PREDECESSOR_IMAGE" \
        --arg current_image "$CURRENT_IMAGE" \
        --arg predecessor_image_id "$predecessor_image_id" \
        --arg current_image_id "$current_image_id" \
        --arg project_base "$PROJECT_BASE" \
        --arg source_project "$SOURCE_PROJECT" \
        --arg upgrade_project "$UPGRADE_PROJECT" \
        --arg rollback_project "$ROLLBACK_PROJECT" \
        --arg source_network "$SOURCE_NETWORK" \
        --arg upgrade_network "$UPGRADE_NETWORK" \
        --arg rollback_network "$ROLLBACK_NETWORK" \
        --arg source_db_volume "$SOURCE_DB_VOLUME" \
        --arg source_storage_volume "$SOURCE_STORAGE_VOLUME" \
        --arg upgrade_db_volume "$UPGRADE_DB_VOLUME" \
        --arg upgrade_storage_volume "$UPGRADE_STORAGE_VOLUME" \
        --arg rollback_db_volume "$ROLLBACK_DB_VOLUME" \
        --arg rollback_storage_volume "$ROLLBACK_STORAGE_VOLUME" \
        --arg source_database "$DATABASE_NAME" \
        --arg temporary_root "$TEMP_ROOT" \
        --arg backup_root "$BACKUP_ROOT" \
        --arg app_key_fingerprint "$APP_KEY_FINGERPRINT" \
        --arg predecessor_migrations_sha "$predecessor_migrations_sha" \
        --arg current_migrations_sha "$current_migrations_sha" \
        --arg fixture_sha "$fixture_sha" \
        --arg backup_manifest_sha "$backup_manifest_sha" \
        --argjson source_port "$SOURCE_PORT" \
        --argjson upgrade_port "$UPGRADE_PORT" \
        --argjson rollback_port "$ROLLBACK_PORT" \
        --argjson predecessor_migrations "$predecessor_migrations" \
        --argjson current_migrations "$current_migrations" \
        --argjson cleanup_verified "$CLEANUP_VERIFIED" \
        --argjson maintainer_volumes_unchanged "$MAINTAINER_VOLUMES_UNCHANGED" \
        --argjson phases "$phase_json" \
        --argjson sqlite "$sqlite_json" \
        '{format: $format, mode: $mode, started_at: $started_at, finished_at: $finished_at, refs: {predecessor: $predecessor_ref, current: $current_ref}, images: {predecessor: {tag: $predecessor_image, id: $predecessor_image_id}, current: {tag: $current_image, id: $current_image_id}}, topology: {project_base: $project_base, projects: [$source_project, $upgrade_project, $rollback_project], networks: [$source_network, $upgrade_network, $rollback_network], volumes: [$source_db_volume, $source_storage_volume, $upgrade_db_volume, $upgrade_storage_volume, $rollback_db_volume, $rollback_storage_volume], ports: [$source_port, $upgrade_port, $rollback_port], source_database: $source_database, paths: {temporary_root: $temporary_root, backup_root: $backup_root}}, secrets: {app_key_sha256: $app_key_fingerprint}, migrations: {predecessor: {count: $predecessor_migrations, list_sha256: $predecessor_migrations_sha}, current: {count: $current_migrations, list_sha256: $current_migrations_sha}}, fixture_sha256: $fixture_sha, backup_manifest_sha256: $backup_manifest_sha, sqlite: $sqlite, phases: $phases, cleanup: {verified: $cleanup_verified, maintainer_volumes_unchanged: $maintainer_volumes_unchanged}}' \
        > "$INTERNAL_EVIDENCE"

    if [[ -n "$EVIDENCE_PATH" ]]; then
        install -m 0600 "$INTERNAL_EVIDENCE" "$EVIDENCE_PATH"
    fi
    cat "$INTERNAL_EVIDENCE"
}

capture_image_identities() {
    docker image inspect --format '{{.Id}}' "$PREDECESSOR_IMAGE" > "$TEMP_ROOT/image-identities.tsv"
    docker image inspect --format '{{.Id}}' "$CURRENT_IMAGE" >> "$TEMP_ROOT/image-identities.tsv"
}

on_exit() {
    local status=$?

    if [[ -n "$TEMP_ROOT" ]]; then
        cleanup_resources >/dev/null 2>&1 || true
        rm -rf -- "$TEMP_ROOT"
    fi

    if [[ "$status" -ne 0 && "$MODE" != safety-smoke ]]; then
        printf 'Dual-revision harness failed during %s.\n' "$STEP" >&2
    fi
    exit "$status"
}

run_safety_smoke() {
    local sentinel_id
    local sentinel_base
    local sentinel_volume
    local sentinel_network
    local phase
    local child_base
    local status
    local invalid_ref='0000000000000000000000000000000000000000'
    local phases=(validate export resources build install backup verify upgrade validate-upgrade damage rollback validate-rollback cleanup)

    safety_cleanup() {
        docker volume rm "$sentinel_volume" >/dev/null 2>&1 || true
        docker network rm "$sentinel_network" >/dev/null 2>&1 || true
    }

    sentinel_id="$(openssl rand -hex 6)"
    sentinel_base="koma-upgrade-$sentinel_id"
    sentinel_volume="${sentinel_base}-sentinel-volume"
    sentinel_network="${sentinel_base}-sentinel-network"
    trap safety_cleanup EXIT
    docker volume create \
        --label io.komaengine.release-harness=true \
        --label "io.komaengine.release-harness.run=$sentinel_id" \
        "$sentinel_volume" >/dev/null
    docker network create \
        --label io.komaengine.release-harness=true \
        --label "io.komaengine.release-harness.run=$sentinel_id" \
        "$sentinel_network" >/dev/null

    if "$0" --dry-run --project-base komaengine --current-ref "$CURRENT_REF" >/dev/null 2>&1; then
        echo 'Harness accepted a maintainer project name.' >&2
        return 1
    fi
    if "$0" --dry-run --project-base "koma-upgrade-$(openssl rand -hex 6)" --predecessor-ref "$invalid_ref" --current-ref "$CURRENT_REF" >/dev/null 2>&1; then
        echo 'Harness accepted an unresolved predecessor ref.' >&2
        return 1
    fi

    for phase in "${phases[@]}"; do
        child_base="koma-upgrade-$(openssl rand -hex 6)"
        set +e
        "$0" --prepare --internal-simulate \
            --project-base "$child_base" \
            --predecessor-ref "$PREDECESSOR_REF" \
            --current-ref "$CURRENT_REF" \
            --fail-phase "$phase" \
            >/dev/null 2>&1
        status=$?
        set -e
        [[ "$status" -eq 97 ]] || {
            printf 'Failure injection for %s exited %s instead of 97.\n' "$phase" "$status" >&2
            return 1
        }
        test -z "$(docker ps -aq --filter "label=io.komaengine.release-harness.run=${child_base#koma-upgrade-}")"
        test -z "$(docker volume ls -q --filter "label=io.komaengine.release-harness.run=${child_base#koma-upgrade-}")"
        test -z "$(docker network ls -q --filter "label=io.komaengine.release-harness.run=${child_base#koma-upgrade-}")"
        test -z "$(docker image ls -q --filter "label=io.komaengine.release-harness.run=${child_base#koma-upgrade-}")"
        docker volume inspect "$sentinel_volume" >/dev/null
        docker network inspect "$sentinel_network" >/dev/null
    done

    safety_cleanup
    trap - EXIT
    printf 'Dual-revision harness safety smoke passed for %s phases.\n' "${#phases[@]}"
}

initialize_topology

if [[ "$MODE" == safety-smoke ]]; then
    run_safety_smoke
    exit 0
fi

setup_temp_root
trap on_exit EXIT
STARTED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

run_phase validate validate_refs_and_topology

if [[ "$MODE" == dry-run ]]; then
    CLEANUP_VERIFIED=true
    MAINTAINER_VOLUMES_UNCHANGED=true
    write_evidence
    exit 0
fi

run_phase export export_revisions
run_phase resources create_resources
run_phase build build_images

if [[ "$INTERNAL_SIMULATE" == false ]]; then
    capture_image_identities
fi

run_phase install install_predecessor_fixture
run_phase backup capture_pre_upgrade_backup
run_phase verify verify_pre_upgrade_backup

if [[ "$INTERNAL_SIMULATE" == true ]]; then
    run_phase upgrade simulate_future_phase
    run_phase validate-upgrade simulate_future_phase
    run_phase damage simulate_future_phase
    run_phase rollback simulate_future_phase
    run_phase validate-rollback simulate_future_phase
elif [[ "$MODE" == sqlite-upgrade ]]; then
    run_phase upgrade upgrade_sqlite_copy
    run_phase validate-upgrade validate_upgraded_sqlite
    run_phase damage damage_upgraded_sqlite
    run_phase rollback rollback_predecessor_sqlite
    run_phase validate-rollback validate_rolled_back_sqlite
fi

run_phase cleanup cleanup_resources

if [[ "$INTERNAL_SIMULATE" == false ]]; then
    write_evidence
fi
