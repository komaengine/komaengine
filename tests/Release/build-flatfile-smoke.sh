#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUTPUT_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-flatfile-smoke.XXXXXX")"
LOG="$OUTPUT_ROOT/build.log"
ERROR_LOG="$OUTPUT_ROOT/error.log"
SOURCE_COMMIT="${KOMA_TEST_SOURCE_COMMIT:-$(git -C "$ROOT" rev-parse HEAD)}"
RELEASE_VERSION="$(tr -d '\r\n' < "$ROOT/VERSION")"
EXPECTED_BUILD_ID="${RELEASE_VERSION}+${SOURCE_COMMIT:0:12}"
BUILDER_IMAGE="koma-flatfile-builder-$RANDOM-$$:local"

if [[ ! "$SOURCE_COMMIT" =~ ^[0-9a-f]{40}$ ]] \
    || [[ "$(git -C "$ROOT" rev-parse --verify "${SOURCE_COMMIT}^{commit}" 2>/dev/null)" != "$SOURCE_COMMIT" ]]; then
    echo "KOMA_TEST_SOURCE_COMMIT must be an available full lowercase 40-character Git commit." >&2
    exit 2
fi

cleanup() {
    docker image rm "$BUILDER_IMAGE" >/dev/null 2>&1 || true
    rm -rf "$OUTPUT_ROOT"
}
trap cleanup EXIT

before_status="$(git -C "$ROOT" status --porcelain=v1 -uall | shasum -a 256 | awk '{print $1}')"
before_diff="$(git -C "$ROOT" diff --binary | shasum -a 256 | awk '{print $1}')"

if (unset KOMA_SOURCE_REF; "$ROOT/scripts/build-flatfile.sh" "$OUTPUT_ROOT/missing-ref") \
    > /dev/null 2> "$ERROR_LOG"; then
    echo "Flat-file builder accepted an implicit source revision." >&2
    exit 1
fi
grep -Fq 'must be an explicit full lowercase 40-character Git commit' "$ERROR_LOG"

if KOMA_SOURCE_REF=HEAD "$ROOT/scripts/build-flatfile.sh" "$OUTPUT_ROOT/symbolic-ref" \
    > /dev/null 2> "$ERROR_LOG"; then
    echo "Flat-file builder accepted a symbolic source revision." >&2
    exit 1
fi
grep -Fq 'must be an explicit full lowercase 40-character Git commit' "$ERROR_LOG"

docker build \
    --file "$ROOT/tests/Release/flatfile-builder.Dockerfile" \
    --tag "$BUILDER_IMAGE" \
    "$ROOT"

docker run --rm \
    --env KOMA_SOURCE_REF="$SOURCE_COMMIT" \
    --env GIT_CONFIG_COUNT=1 \
    --env GIT_CONFIG_KEY_0=safe.directory \
    --env GIT_CONFIG_VALUE_0=/workspace \
    --volume "$ROOT:/workspace:ro" \
    --volume "$OUTPUT_ROOT:/output" \
    --workdir /workspace \
    "$BUILDER_IMAGE" \
    bash -lc 'scripts/build-flatfile.sh /output' | tee "$LOG"

container_archive="$(sed -n 's/^ARCHIVE=//p' "$LOG" | tail -n 1)"
container_directory="$(sed -n 's/^DIRECTORY=//p' "$LOG" | tail -n 1)"
reported_commit="$(sed -n 's/^SOURCE_COMMIT=//p' "$LOG" | tail -n 1)"
reported_version="$(sed -n 's/^RELEASE_VERSION=//p' "$LOG" | tail -n 1)"
reported_build_id="$(sed -n 's/^BUILD_ID=//p' "$LOG" | tail -n 1)"
archive="$OUTPUT_ROOT/$(basename "$container_archive")"
directory="$OUTPUT_ROOT/$(basename "$container_directory")"

[[ -f "$archive" && -d "$directory" ]] || {
    echo "Builder did not report valid directory and archive outputs." >&2
    exit 1
}
[[ "$reported_commit" == "$SOURCE_COMMIT" ]] || {
    echo "Builder reported a different source commit." >&2
    exit 1
}
[[ "$reported_version" == "$RELEASE_VERSION" && "$reported_build_id" == "$EXPECTED_BUILD_ID" ]] || {
    echo "Builder reported an inconsistent release identity." >&2
    exit 1
}

for path in \
    .claude/launch.json \
    app/Archives/ArchiveImportBatchOptions.php; do
    if ! git -C "$ROOT" cat-file -e "${SOURCE_COMMIT}:${path}" 2>/dev/null \
        && unzip -Z1 "$archive" | grep -Fxq "komaengine/$path"; then
        echo "Artifact contains a path absent from the selected Git tree: $path" >&2
        exit 1
    fi
done

# Only the published references may ship from docs/, whatever else the
# selected tree contains.
while IFS= read -r doc; do
    case "$doc" in
        docs/install/*|docs/schema/*|docs/bulk-upload-api.md|docs/image-processing.md|docs/koma-archive-v1.md|docs/koma-archive-v2.md|docs/koma-site-backup-v1.md|docs/OPERATIONS_RECOVERY_POLICY.md|docs/plugin-development.md|docs/SUPPLY_CHAIN.md|docs/theme-development.md) ;;
        *)
            echo "Artifact ships a document that is not a published reference: $doc" >&2
            exit 1
            ;;
    esac
done < <(unzip -Z1 "$archive" | sed -n 's|^komaengine/\(docs/.*[^/]\)$|\1|p')

# Local or private plugins can sit ignored in a checkout; only plugins in the
# selected tree may ship.
while IFS= read -r plugin; do
    git -C "$ROOT" cat-file -e "${SOURCE_COMMIT}:plugins/${plugin}" 2>/dev/null || {
        echo "Artifact contains a plugin absent from the selected Git tree: $plugin" >&2
        exit 1
    }
done < <(unzip -Z1 "$archive" | sed -n 's|^komaengine/plugins/\([^/][^/]*\)/.*|\1|p' | sort -u)

docker run --rm \
    --env KOMA_EXPECTED_SOURCE_COMMIT="$SOURCE_COMMIT" \
    --env KOMA_EXPECTED_RELEASE_VERSION="$RELEASE_VERSION" \
    --volume "$ROOT:/workspace:ro" \
    --volume "$OUTPUT_ROOT:/output:ro" \
    --workdir /workspace \
    "$BUILDER_IMAGE" \
    bash -lc "tests/Release/inspect-artifact.sh '/output/$(basename "$archive")'"

app_key="base64:$(openssl rand -base64 32)"
docker run --rm \
    --env APP_ENV=production \
    --env APP_DEBUG=false \
    --env APP_KEY="$app_key" \
    --env APP_URL=http://koma-flatfile.test \
    --env DB_CONNECTION=sqlite \
    --env DB_DATABASE=/output/runtime.sqlite \
    --env CACHE_STORE=file \
    --env SESSION_DRIVER=file \
    --env QUEUE_CONNECTION=sync \
    --env INSTALLER_ENVIRONMENT_MODE=process \
    --env INSTALLER_WRITE_ENV=false \
    --env INSTALLER_WRITE_ROBOTS=false \
    --env MEDIA_DISK_DRIVER=local \
    --env KOMA_APP_ROOT="/output/$(basename "$directory")" \
    --volume "$ROOT:/workspace:ro" \
    --volume "$OUTPUT_ROOT:/output" \
    --workdir "/output/$(basename "$directory")" \
    "$BUILDER_IMAGE" \
    bash -lc 'touch "$DB_DATABASE" \
        && php /workspace/tests/Release/support/install-database.php \
        && php /workspace/tests/Release/support/verify-database.php \
        && php artisan koma:doctor --mode=operational --json \
        && touch .env \
        && chmod 600 .env \
        && scripts/install-ubuntu-services.sh \
            --app-root="$KOMA_APP_ROOT" \
            --service-user="$(id -un)" \
            --php-bin="$(command -v php)" \
            --dry-run >/dev/null'

after_status="$(git -C "$ROOT" status --porcelain=v1 -uall | shasum -a 256 | awk '{print $1}')"
after_diff="$(git -C "$ROOT" diff --binary | shasum -a 256 | awk '{print $1}')"

[[ "$before_status" == "$after_status" && "$before_diff" == "$after_diff" ]] || {
    echo "Flat-file build mutated the source worktree." >&2
    exit 1
}

printf 'Flat-file exact-revision build/install/restart smoke passed for %s.\n' "$SOURCE_COMMIT"
