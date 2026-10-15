#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_INPUT="${1:-$ROOT/dist}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
SOURCE_REF="${KOMA_SOURCE_REF:-}"

command -v git >/dev/null 2>&1 || {
    echo "Required build tool is missing: git" >&2
    exit 2
}

if [[ ! "$SOURCE_REF" =~ ^[0-9a-f]{40}$ ]]; then
    echo "KOMA_SOURCE_REF must be an explicit full lowercase 40-character Git commit." >&2
    exit 2
fi

SOURCE_COMMIT="$(git -C "$ROOT" rev-parse --verify "${SOURCE_REF}^{commit}" 2>/dev/null)" || {
    echo "KOMA_SOURCE_REF does not resolve to a local Git commit: $SOURCE_REF" >&2
    exit 2
}

if [[ "$SOURCE_COMMIT" != "$SOURCE_REF" ]]; then
    echo "KOMA_SOURCE_REF must identify the exact commit, not a branch, tag, or abbreviation." >&2
    exit 2
fi

for tool in git tar "$PHP_BIN" "$COMPOSER_BIN" "$NPM_BIN" openssl zip unzip; do
    command -v "${tool%% *}" >/dev/null 2>&1 || {
        echo "Required build tool is missing: $tool" >&2
        exit 2
    }
done

mkdir -p "$OUTPUT_INPUT"
OUTPUT_DIR="$(cd "$OUTPUT_INPUT" && pwd)"

case "$OUTPUT_DIR" in
    /|"$ROOT")
        echo "Refusing to use an unsafe release output directory: $OUTPUT_DIR" >&2
        exit 2
        ;;
esac

WORK_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-flatfile-build.XXXXXX")"
SOURCE_ROOT="$WORK_ROOT/source"
STAGE="$WORK_ROOT/komaengine"
SOURCE_MANIFEST="$WORK_ROOT/source-manifest.tsv"
ARCHIVE_TMP="$OUTPUT_DIR/.komaengine-build-$$.zip"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
FINAL_DIR="$OUTPUT_DIR/komaengine-$STAMP"
FINAL_ARCHIVE="$OUTPUT_DIR/komaengine-$STAMP.zip"

cleanup() {
    rm -rf "$WORK_ROOT"
    rm -f "$ARCHIVE_TMP"
}
trap cleanup EXIT

if [[ -e "$FINAL_DIR" || -e "$FINAL_ARCHIVE" ]]; then
    echo "Release output already exists for timestamp $STAMP; retry in one second." >&2
    exit 2
fi

mkdir -p "$SOURCE_ROOT"
git -C "$ROOT" archive --format=tar "$SOURCE_COMMIT" | tar -xf - -C "$SOURCE_ROOT"

RELEASE_VERSION="$(tr -d '\r\n' < "$SOURCE_ROOT/VERSION")"
if [[ "$(wc -l < "$SOURCE_ROOT/VERSION" | tr -d ' ')" != 1 ]] \
    || [[ ! "$RELEASE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "Selected source contains a missing or invalid VERSION." >&2
    exit 3
fi
BUILD_ID="${RELEASE_VERSION}+${SOURCE_COMMIT:0:12}"

KOMA_SOURCE_REPOSITORY="$ROOT" \
KOMA_SOURCE_COMMIT="$SOURCE_COMMIT" \
    "$ROOT/scripts/release/source-manifest.sh" > "$SOURCE_MANIFEST"
mkdir -p "$STAGE"

while IFS=$'\t' read -r type mode size digest relative target; do
    [[ "$type" == 'file' ]] || continue

    case "$relative" in
        scripts/install-ubuntu-services.sh)
            ;;
        # Only the published operator and developer references ship. Any other
        # document under docs/ stays in the repository.
        docs/install/*|docs/schema/*|docs/bulk-upload-api.md|docs/image-processing.md|docs/koma-archive-v1.md|docs/koma-archive-v2.md|docs/koma-site-backup-v1.md|docs/OPERATIONS_RECOVERY_POLICY.md|docs/plugin-development.md|docs/SUPPLY_CHAIN.md|docs/theme-development.md)
            ;;
        docs/*)
            continue
            ;;
        .dockerignore|.editorconfig|.env.docker.example|.gitattributes|.gitignore|Dockerfile|baseplan.md|docker-compose.yml|phpunit.xml|progress.md|roadmap.md|tests/*|docker/*|scripts/*|bootstrap/cache/*.php )
            continue
            ;;
    esac

    destination="$STAGE/$relative"
    mkdir -p "$(dirname "$destination")"
    cp -p "$SOURCE_ROOT/$relative" "$destination"
done < "$SOURCE_MANIFEST"

# The public archive contains no reusable application key. Operators generate
# one unique key before the first HTTP request.
cp "$SOURCE_ROOT/.env.flatfile.example" "$STAGE/.env.example"
rm -f "$STAGE/.env.flatfile.example" "$STAGE/.env.docker.example" "$STAGE/.env"

if ! grep -Eq '^APP_KEY=$' "$STAGE/.env.example"; then
    echo "The public flat-file environment example must contain a blank APP_KEY." >&2
    exit 3
fi

mkdir -p \
    "$STAGE/storage/app/private" \
    "$STAGE/storage/app/media" \
    "$STAGE/storage/app/public" \
    "$STAGE/storage/app/archive-import-temp" \
    "$STAGE/storage/framework/cache" \
    "$STAGE/storage/framework/sessions" \
    "$STAGE/storage/framework/views" \
    "$STAGE/storage/logs" \
    "$STAGE/bootstrap/cache"

echo "==> Installing production PHP dependencies in isolated staging"
(
    cd "$STAGE"
    $COMPOSER_BIN install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
)

echo "==> Building front-end assets in isolated staging"
(
    cd "$STAGE"
    $NPM_BIN ci
    $NPM_BIN run build
    rm -rf node_modules
)

BUILD_KEY="base64:$(openssl rand -base64 32)"
(
    cd "$STAGE"
    APP_ENV=production \
    APP_KEY="$BUILD_KEY" \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    INSTALLER_ENVIRONMENT_MODE=process \
    "$PHP_BIN" artisan package:discover --ansi >/dev/null
)

rm -f "$STAGE/public/hot" "$STAGE/public/storage"

source_manifest_sha="$(shasum -a 256 "$SOURCE_MANIFEST" | awk '{print $1}')"
composer_lock_sha="$(shasum -a 256 "$SOURCE_ROOT/composer.lock" | awk '{print $1}')"
package_lock_sha="$(shasum -a 256 "$SOURCE_ROOT/package-lock.json" | awk '{print $1}')"

printf '%s\n' \
    "format=koma-build-v1" \
    "artifact=flatfile" \
    "release_version=$RELEASE_VERSION" \
    "source_commit=$SOURCE_COMMIT" \
    "source_dirty=false" \
    "source_policy=exact-commit" \
    "build_id=$BUILD_ID" \
    "source_manifest_sha256=$source_manifest_sha" \
    "composer_lock_sha256=$composer_lock_sha" \
    "package_lock_sha256=$package_lock_sha" \
    "php=$($PHP_BIN -r 'echo PHP_VERSION;')" \
    "composer=$($COMPOSER_BIN --version --no-ansi 2>/dev/null | head -n 1)" \
    "node=$($NPM_BIN exec --yes -- node --version 2>/dev/null || node --version)" \
    > "$STAGE/KOMA-BUILD-METADATA.txt"

printf 'KomaEngine %s build %s from %s\n' \
    "$RELEASE_VERSION" "$BUILD_ID" "$SOURCE_COMMIT" > "$STAGE/.koma-release-build"

(
    cd "$STAGE"
    find . -type f ! -name KOMA-FILES.sha256 -print | LC_ALL=C sort | while IFS= read -r path; do
        printf '%s  %s\n' "$(shasum -a 256 "$path" | awk '{print $1}')" "$path"
    done > KOMA-FILES.sha256
)

echo "==> Creating and verifying archive"
(
    cd "$WORK_ROOT"
    zip -rq "$ARCHIVE_TMP" komaengine
)
unzip -tq "$ARCHIVE_TMP" >/dev/null

mv "$STAGE" "$FINAL_DIR"
mv "$ARCHIVE_TMP" "$FINAL_ARCHIVE"
trap - EXIT
rm -rf "$WORK_ROOT"

archive_sha="$(shasum -a 256 "$FINAL_ARCHIVE" | awk '{print $1}')"
printf 'DIRECTORY=%s\n' "$FINAL_DIR"
printf 'ARCHIVE=%s\n' "$FINAL_ARCHIVE"
printf 'SHA256=%s\n' "$archive_sha"
printf 'RELEASE_VERSION=%s\n' "$RELEASE_VERSION"
printf 'BUILD_ID=%s\n' "$BUILD_ID"
printf 'SOURCE_COMMIT=%s\n' "$SOURCE_COMMIT"
