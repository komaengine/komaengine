#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_REF="${KOMA_TEST_SOURCE_COMMIT:-$(git -C "$ROOT" rev-parse HEAD)}"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-browser-qa.XXXXXX")"
RUN_ID="$(openssl rand -hex 6)"
APP_IMAGE="koma-browser-app-${RUN_ID}:local"
RUNNER_IMAGE="koma-browser-runner-${RUN_ID}:local"
COMPOSE_FILE="$ROOT/tests/Browser/docker-compose.yml"
ARTIFACT_ROOT="${KOMA_BROWSER_ARTIFACT_ROOT:-$ROOT/storage/framework/testing/browser-qa/$RUN_ID}"
TRUSTED_BUILD="${KOMA_BROWSER_TRUSTED_BUILD:-true}"
STEP='initialization'
PROJECTS=(
    chromium-desktop
    firefox-desktop
    webkit-desktop
    chromium-phone
    firefox-phone
    webkit-phone
)
ACTIVE_PROJECTS=()

usage() {
    cat <<'EOF'
Usage: tests/Release/browser-qa-smoke.sh [--source-ref HASH] [--project NAME]

Builds an exact Git revision, then runs browser installation and core publishing
journeys against fresh disposable MariaDB/storage for Chromium, Firefox, and
WebKit at desktop and phone viewports. Repeat --project to run a subset.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source-ref)
            SOURCE_REF="${2:-}"
            shift 2
            ;;
        --project)
            ACTIVE_PROJECTS+=("${2:-}")
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown browser QA argument: %s\n' "$1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

if [[ ${#ACTIVE_PROJECTS[@]} -gt 0 ]]; then
    PROJECTS=("${ACTIVE_PROJECTS[@]}")
fi

full_commit() {
    local ref="$1"

    [[ "$ref" =~ ^[0-9a-f]{40}$ ]] || return 1
    [[ "$(git -C "$ROOT" rev-parse --verify "${ref}^{commit}" 2>/dev/null)" == "$ref" ]]
}

known_project() {
    case "$1" in
        chromium-desktop|firefox-desktop|webkit-desktop|chromium-phone|firefox-phone|webkit-phone) return 0 ;;
        *) return 1 ;;
    esac
}

compose() {
    local project="$1"
    local env_file="$2"
    shift 2

    docker compose \
        --project-name "$project" \
        --env-file "$env_file" \
        --file "$COMPOSE_FILE" \
        "$@"
}

maintainer_volume_fingerprint() {
    docker volume inspect \
        --format '{{.Name}} {{.CreatedAt}}' \
        komaengine_koma-db komaengine_koma-storage \
        | LC_ALL=C sort \
        | shasum -a 256 \
        | awk '{print $1}'
}

allocate_port() {
    node -e '
        const net = require("node:net");
        const server = net.createServer();
        server.listen(0, "127.0.0.1", () => {
            process.stdout.write(String(server.address().port));
            server.close();
        });
    '
}

cleanup_project() {
    local compose_project="$1"
    local env_file="$2"

    if [[ -f "$env_file" ]]; then
        compose "$compose_project" "$env_file" --profile runtime down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi
}

cleanup() {
    local project
    local compose_project
    local env_file

    for project in "${PROJECTS[@]}"; do
        compose_project="koma-qa-${RUN_ID}-${project//-/_}"
        env_file="$TEMP_ROOT/$project.env"
        cleanup_project "$compose_project" "$env_file"
    done

    docker image rm -f "$RUNNER_IMAGE" "$APP_IMAGE" >/dev/null 2>&1 || true
    rm -rf -- "$TEMP_ROOT"
}

on_exit() {
    local status=$?

    cleanup
    if [[ "$status" -ne 0 ]]; then
        printf 'Browser QA smoke failed during %s.\n' "$STEP" >&2
        printf 'Failure artifacts: %s\n' "$ARTIFACT_ROOT" >&2
    fi
    exit "$status"
}
trap on_exit EXIT

full_commit "$SOURCE_REF" || {
    printf 'Browser QA requires a full immutable commit hash: %s\n' "$SOURCE_REF" >&2
    exit 2
}

[[ "$TRUSTED_BUILD" == true || "$TRUSTED_BUILD" == false ]] || {
    printf 'KOMA_BROWSER_TRUSTED_BUILD must be true or false.\n' >&2
    exit 2
}

for project in "${PROJECTS[@]}"; do
    known_project "$project" || {
        printf 'Unknown Playwright project: %s\n' "$project" >&2
        exit 2
    }
done

STEP='maintainer-volume snapshot'
VOLUMES_BEFORE="$(maintainer_volume_fingerprint)"

STEP='exact source export'
mkdir -p "$TEMP_ROOT/source"
git -C "$ROOT" archive "$SOURCE_REF" | tar -x -C "$TEMP_ROOT/source"
VERSION="$(tr -d '\r\n' < "$TEMP_ROOT/source/VERSION")"
BUILD_ID="${VERSION}+${SOURCE_REF:0:12}"

STEP='exact application image build'
build_args=()
if [[ "$TRUSTED_BUILD" == true ]]; then
    build_args+=(
        --build-arg "KOMA_RELEASE_VERSION=$VERSION"
        --build-arg "KOMA_RELEASE_COMMIT=$SOURCE_REF"
        --build-arg KOMA_RELEASE_DIRTY=false
        --build-arg "KOMA_RELEASE_BUILD_ID=$BUILD_ID"
        --build-arg KOMA_TRUSTED_BUILD=true
    )
fi
docker build --tag "$APP_IMAGE" "${build_args[@]}" "$TEMP_ROOT/source"

STEP='browser runner image build'
mkdir -p "$TEMP_ROOT/runner-context/tests"
cp "$ROOT/package.json" "$ROOT/package-lock.json" "$ROOT/playwright.config.js" "$TEMP_ROOT/runner-context/"
cp -R "$ROOT/tests/Browser" "$TEMP_ROOT/runner-context/tests/Browser"
docker build \
    --file "$TEMP_ROOT/runner-context/tests/Browser/Dockerfile" \
    --tag "$RUNNER_IMAGE" \
    "$TEMP_ROOT/runner-context"

for project in "${PROJECTS[@]}"; do
    compose_project="koma-qa-${RUN_ID}-${project//-/_}"
    env_file="$TEMP_ROOT/$project.env"
    artifact_directory="$ARTIFACT_ROOT/$project"
    port="$(allocate_port)"
    app_url="http://hostmachine:$port"
    database_password="$(openssl rand -hex 24)"
    database_root_password="$(openssl rand -hex 24)"
    application_key="base64:$(openssl rand -base64 32 | tr -d '\r\n')"

    mkdir -p "$artifact_directory"
    umask 077
    {
        printf 'KOMA_QA_APP_IMAGE=%s\n' "$APP_IMAGE"
        printf 'KOMA_QA_APP_KEY=%s\n' "$application_key"
        printf 'KOMA_QA_APP_URL=%s\n' "$app_url"
        printf 'KOMA_QA_APP_PORT=%s\n' "$port"
        printf 'KOMA_QA_DB_PASSWORD=%s\n' "$database_password"
        printf 'KOMA_QA_DB_ROOT_PASSWORD=%s\n' "$database_root_password"
    } > "$env_file"
    chmod 600 "$env_file"

    STEP="$project disposable stack startup"
    compose "$compose_project" "$env_file" up -d --wait app

    STEP="$project browser installer"
    setup_token="$(compose "$compose_project" "$env_file" exec -T app gosu www-data php artisan koma:installer-token)"
    [[ "$setup_token" =~ ^[a-f0-9]{64}$ ]]
    docker run --rm \
        --init \
        --ipc=host \
        --add-host=hostmachine:host-gateway \
        --env "KOMA_BROWSER_BASE_URL=$app_url" \
        --env "KOMA_BROWSER_DB_PASSWORD=$database_password" \
        --env "KOMA_BROWSER_SETUP_TOKEN=$setup_token" \
        --env KOMA_BROWSER_OUTPUT_DIR=/artifacts/install \
        --volume "$artifact_directory:/artifacts" \
        "$RUNNER_IMAGE" \
        tests/Browser/install.spec.js \
        --project="$project"

    STEP="$project isolated fixture seed"
    app_container="$(compose "$compose_project" "$env_file" ps -q app)"
    docker cp "$ROOT/tests/Browser/support/seed-release.php" "$app_container:/tmp/koma-qa-seed.php"
    compose "$compose_project" "$env_file" exec -T app \
        gosu www-data php /tmp/koma-qa-seed.php >/dev/null
    compose "$compose_project" "$env_file" exec -T app rm -f /tmp/koma-qa-seed.php

    STEP="$project queue and scheduler startup"
    compose "$compose_project" "$env_file" --profile runtime up -d worker scheduler

    STEP="$project publishing and reader journey"
    docker run --rm \
        --init \
        --ipc=host \
        --add-host=hostmachine:host-gateway \
        --env "KOMA_BROWSER_BASE_URL=$app_url" \
        --env KOMA_BROWSER_OUTPUT_DIR=/artifacts/core \
        --volume "$artifact_directory:/artifacts" \
        "$RUNNER_IMAGE" \
        tests/Browser/core.spec.js \
        --project="$project"

    STEP="$project release administration and edge-state journey"
    docker run --rm \
        --init \
        --ipc=host \
        --add-host=hostmachine:host-gateway \
        --env "KOMA_BROWSER_BASE_URL=$app_url" \
        --env KOMA_BROWSER_OUTPUT_DIR=/artifacts/release \
        --volume "$artifact_directory:/artifacts" \
        "$RUNNER_IMAGE" \
        tests/Browser/release.spec.js \
        --project="$project"

    STEP="$project operational doctor"
    compose "$compose_project" "$env_file" exec -T app \
        gosu www-data php artisan koma:doctor --mode=operational --json \
        | grep -q '"ready":true'

    STEP="$project cleanup"
    cleanup_project "$compose_project" "$env_file"
done

STEP='maintainer-volume verification'
VOLUMES_AFTER="$(maintainer_volume_fingerprint)"
[[ "$VOLUMES_AFTER" == "$VOLUMES_BEFORE" ]] || {
    printf 'Authoritative maintainer volume identities changed during browser QA.\n' >&2
    exit 1
}

STEP='complete'
printf 'Browser QA smoke passed for source %s across %s project(s).\n' "$SOURCE_REF" "${#PROJECTS[@]}"
