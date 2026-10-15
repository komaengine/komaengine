#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOCK_FILE="$ROOT/scripts/release/image-lock.env"
SOURCE_REF=''
ARTIFACT=''
IMAGE=''
IMAGE_DIGEST=''
OUTPUT=''
BUILDER_ID='local:komaengine/scripts/release/generate-supply-chain.sh'

usage() {
    cat <<'EOF'
Usage: scripts/release/generate-supply-chain.sh \
  --source-ref COMMIT --artifact FILE --image REF --output DIRECTORY \
  [--image-digest sha256:...] [--builder-id ID]

Generates SPDX JSON for the final flat-file artifact and runtime image plus an
in-toto/SLSA provenance statement. The source ref must be an exact local commit.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source-ref) SOURCE_REF="${2:-}"; shift 2 ;;
        --artifact) ARTIFACT="${2:-}"; shift 2 ;;
        --image) IMAGE="${2:-}"; shift 2 ;;
        --image-digest) IMAGE_DIGEST="${2:-}"; shift 2 ;;
        --output) OUTPUT="${2:-}"; shift 2 ;;
        --builder-id) BUILDER_ID="${2:-}"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) echo "Unknown supply-chain argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

for tool in cmp date docker git jq shasum unzip; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "Required supply-chain tool is missing: $tool" >&2
        exit 2
    }
done

[[ "$SOURCE_REF" =~ ^[0-9a-f]{40}$ ]] \
    && [[ "$(git -C "$ROOT" rev-parse --verify "${SOURCE_REF}^{commit}" 2>/dev/null)" == "$SOURCE_REF" ]] || {
    echo 'The supply-chain source ref must be an available full Git commit.' >&2
    exit 2
}
[[ -f "$ARTIFACT" && -n "$IMAGE" && -n "$OUTPUT" && -n "$BUILDER_ID" ]] || {
    usage >&2
    exit 2
}

ARTIFACT="$(cd "$(dirname "$ARTIFACT")" && pwd)/$(basename "$ARTIFACT")"
mkdir -p "$OUTPUT"
OUTPUT="$(cd "$OUTPUT" && pwd)"
case "$OUTPUT" in
    /|"$ROOT")
        echo "Refusing unsafe supply-chain output directory: $OUTPUT" >&2
        exit 2
        ;;
esac

outputs=(flatfile.spdx.json npm.spdx.json image.spdx.json provenance.intoto.json image-lock.env SHA256SUMS)
for output in "${outputs[@]}"; do
    [[ ! -e "$OUTPUT/$output" ]] || {
        echo "Refusing to overwrite supply-chain evidence: $OUTPUT/$output" >&2
        exit 2
    }
done

. "$LOCK_FILE"

TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-supply-chain.XXXXXX")"
cleanup() {
    rm -rf "$TEMP_ROOT"
}
trap cleanup EXIT

git -C "$ROOT" show "$SOURCE_REF:scripts/release/image-lock.env" \
    > "$TEMP_ROOT/source-image-lock.env"
cmp "$LOCK_FILE" "$TEMP_ROOT/source-image-lock.env" >/dev/null || {
    echo 'Working image lock does not match the exact source commit.' >&2
    exit 1
}

unzip -tq "$ARTIFACT" >/dev/null
unzip -qq "$ARTIFACT" -d "$TEMP_ROOT/artifact"
PACKAGE="$TEMP_ROOT/artifact/komaengine"
METADATA="$PACKAGE/KOMA-BUILD-METADATA.txt"
[[ -f "$METADATA" ]] || {
    echo 'Flat-file artifact is missing KOMA-BUILD-METADATA.txt.' >&2
    exit 1
}

metadata_value() {
    local key="$1"
    local file="$2"
    local count

    count="$(grep -Ec "^${key}=" "$file" || true)"
    [[ "$count" == 1 ]] || {
        echo "Build metadata field is missing or duplicated: $key" >&2
        exit 1
    }
    sed -n "s/^${key}=//p" "$file"
}

RELEASE_VERSION="$(metadata_value release_version "$METADATA")"
BUILD_ID="$(metadata_value build_id "$METADATA")"
SOURCE_VERSION="$(git -C "$ROOT" show "$SOURCE_REF:VERSION" | tr -d '\r\n')"
[[ "$(metadata_value artifact "$METADATA")" == flatfile \
    && "$(metadata_value source_commit "$METADATA")" == "$SOURCE_REF" \
    && "$(metadata_value source_dirty "$METADATA")" == false \
    && "$(metadata_value source_policy "$METADATA")" == exact-commit \
    && "$RELEASE_VERSION" == "$SOURCE_VERSION" \
    && "$BUILD_ID" == "${RELEASE_VERSION}+${SOURCE_REF:0:12}" ]] || {
    echo 'Flat-file artifact identity does not match the exact source ref.' >&2
    exit 1
}

IMAGE_METADATA="$TEMP_ROOT/image-metadata.txt"
docker run --rm --entrypoint sh "$IMAGE" -c \
    'cat /var/www/html/KOMA-BUILD-METADATA.txt' > "$IMAGE_METADATA"
[[ "$(metadata_value artifact "$IMAGE_METADATA")" == docker \
    && "$(metadata_value release_version "$IMAGE_METADATA")" == "$RELEASE_VERSION" \
    && "$(metadata_value source_commit "$IMAGE_METADATA")" == "$SOURCE_REF" \
    && "$(metadata_value source_dirty "$IMAGE_METADATA")" == false \
    && "$(metadata_value source_policy "$IMAGE_METADATA")" == exact-commit \
    && "$(metadata_value build_id "$IMAGE_METADATA")" == "$BUILD_ID" ]] || {
    echo 'Runtime image identity does not match the flat-file artifact.' >&2
    exit 1
}

if [[ -z "$IMAGE_DIGEST" ]]; then
    IMAGE_DIGEST="$(docker image inspect --format '{{.Id}}' "$IMAGE")"
fi
[[ "$IMAGE_DIGEST" =~ ^sha256:[0-9a-f]{64}$ ]] || {
    echo 'Image digest must be a sha256 digest.' >&2
    exit 2
}
if [[ "$IMAGE" == *@sha256:* && "${IMAGE##*@}" != "$IMAGE_DIGEST" ]]; then
    echo 'The explicit image digest does not match the image reference.' >&2
    exit 1
fi

ARTIFACT_SHA="$(shasum -a 256 "$ARTIFACT" | awk '{print $1}')"
COMPOSER_LOCK_SHA="$(shasum -a 256 "$PACKAGE/composer.lock" | awk '{print $1}')"
PACKAGE_LOCK_SHA="$(shasum -a 256 "$PACKAGE/package-lock.json" | awk '{print $1}')"
LOCK_SHA="$(shasum -a 256 "$LOCK_FILE" | awk '{print $1}')"

[[ "$COMPOSER_LOCK_SHA" == "$(metadata_value composer_lock_sha256 "$METADATA")" \
    && "$PACKAGE_LOCK_SHA" == "$(metadata_value package_lock_sha256 "$METADATA")" ]] || {
    echo 'Artifact lock files do not match its build metadata.' >&2
    exit 1
}
[[ "$COMPOSER_LOCK_SHA" == "$(git -C "$ROOT" show "$SOURCE_REF:composer.lock" | shasum -a 256 | awk '{print $1}')" \
    && "$PACKAGE_LOCK_SHA" == "$(git -C "$ROOT" show "$SOURCE_REF:package-lock.json" | shasum -a 256 | awk '{print $1}')" ]] || {
    echo 'Artifact dependency locks do not match the exact source commit.' >&2
    exit 1
}

echo 'Generating flat-file SPDX SBOM'
docker run --rm \
    --volume "$PACKAGE:/scan:ro" \
    "$KOMA_SYFT_IMAGE" scan dir:/scan -o spdx-json > "$OUTPUT/flatfile.spdx.json"

echo 'Generating npm build-dependency SPDX SBOM'
mkdir -p "$TEMP_ROOT/npm"
# npm requires a concrete root version to emit an SPDX package URL. The release
# version is added only to disposable copies; the original lock hash remains the
# provenance authority.
jq --arg version "$RELEASE_VERSION" \
    '. + {name: "KomaEngine", version: $version}' \
    "$PACKAGE/package.json" > "$TEMP_ROOT/npm/package.json"
jq --arg version "$RELEASE_VERSION" '
    . + {name: "KomaEngine", version: $version} |
    .packages[""] += {name: "KomaEngine", version: $version}
    ' "$PACKAGE/package-lock.json" > "$TEMP_ROOT/npm/package-lock.json"
docker run --rm \
    --volume "$TEMP_ROOT/npm:/work:ro" \
    --workdir /work \
    "$KOMA_NODE_IMAGE" npm sbom --package-lock-only --sbom-format spdx \
    > "$OUTPUT/npm.spdx.json"

echo 'Generating runtime-image SPDX SBOM'
docker image save "$IMAGE" --output "$TEMP_ROOT/image.tar"
docker run --rm \
    --volume "$TEMP_ROOT:/scan:ro" \
    "$KOMA_SYFT_IMAGE" scan docker-archive:/scan/image.tar -o spdx-json > "$OUTPUT/image.spdx.json"

FLATFILE_SBOM_SHA="$(shasum -a 256 "$OUTPUT/flatfile.spdx.json" | awk '{print $1}')"
NPM_SBOM_SHA="$(shasum -a 256 "$OUTPUT/npm.spdx.json" | awk '{print $1}')"
IMAGE_SBOM_SHA="$(shasum -a 256 "$OUTPUT/image.spdx.json" | awk '{print $1}')"
SOURCE_EPOCH="$(git -C "$ROOT" show -s --format=%ct "$SOURCE_REF")"
if date -u -r "$SOURCE_EPOCH" '+%Y-%m-%dT%H:%M:%SZ' >/dev/null 2>&1; then
    BUILD_TIME="$(date -u -r "$SOURCE_EPOCH" '+%Y-%m-%dT%H:%M:%SZ')"
else
    BUILD_TIME="$(date -u -d "@$SOURCE_EPOCH" '+%Y-%m-%dT%H:%M:%SZ')"
fi

jq -n \
    --arg artifact_name "$(basename "$ARTIFACT")" \
    --arg artifact_sha "$ARTIFACT_SHA" \
    --arg image_name "$IMAGE" \
    --arg image_sha "${IMAGE_DIGEST#sha256:}" \
    --arg source_commit "$SOURCE_REF" \
    --arg source_uri 'https://github.com/spydrful/KomaEngine' \
    --arg release_version "$RELEASE_VERSION" \
    --arg build_id "$BUILD_ID" \
    --arg builder_id "$BUILDER_ID" \
    --arg build_time "$BUILD_TIME" \
    --arg composer_lock_sha "$COMPOSER_LOCK_SHA" \
    --arg package_lock_sha "$PACKAGE_LOCK_SHA" \
    --arg image_lock_sha "$LOCK_SHA" \
    --arg flatfile_sbom_sha "$FLATFILE_SBOM_SHA" \
    --arg npm_sbom_sha "$NPM_SBOM_SHA" \
    --arg image_sbom_sha "$IMAGE_SBOM_SHA" \
    --arg node_image "$KOMA_NODE_IMAGE" \
    --arg php_image "$KOMA_PHP_FPM_IMAGE" \
    --arg composer_image "$KOMA_COMPOSER_IMAGE" \
    --arg mariadb_image "$KOMA_MARIADB_IMAGE" \
    '{
      "_type": "https://in-toto.io/Statement/v1",
      subject: [
        {name: $artifact_name, digest: {sha256: $artifact_sha}},
        {name: $image_name, digest: {sha256: $image_sha}}
      ],
      predicateType: "https://slsa.dev/provenance/v1",
      predicate: {
        buildDefinition: {
          buildType: "https://komaengine.org/build-types/community-release/v1",
          externalParameters: {
            sourceCommit: $source_commit,
            releaseVersion: $release_version,
            buildId: $build_id
          },
          internalParameters: {
            composerLockSha256: $composer_lock_sha,
            packageLockSha256: $package_lock_sha,
            imageLockSha256: $image_lock_sha,
            sbom: {
              flatfile: {path: "flatfile.spdx.json", sha256: $flatfile_sbom_sha},
              npm: {path: "npm.spdx.json", sha256: $npm_sbom_sha},
              image: {path: "image.spdx.json", sha256: $image_sbom_sha}
            }
          },
          resolvedDependencies: [
            {uri: $source_uri, digest: {gitCommit: $source_commit}},
            {uri: $node_image},
            {uri: $php_image},
            {uri: $composer_image},
            {uri: $mariadb_image}
          ]
        },
        runDetails: {
          builder: {id: $builder_id},
          metadata: {
            invocationId: $build_id,
            startedOn: $build_time,
            finishedOn: $build_time
          }
        }
      }
    }' > "$OUTPUT/provenance.intoto.json"

cp "$LOCK_FILE" "$OUTPUT/image-lock.env"
(
    cd "$OUTPUT"
    shasum -a 256 \
        flatfile.spdx.json npm.spdx.json image.spdx.json provenance.intoto.json image-lock.env \
        > SHA256SUMS
)

printf 'ARTIFACT_SHA256=%s\n' "$ARTIFACT_SHA"
printf 'IMAGE_DIGEST=%s\n' "$IMAGE_DIGEST"
printf 'FLATFILE_SBOM_SHA256=%s\n' "$FLATFILE_SBOM_SHA"
printf 'NPM_SBOM_SHA256=%s\n' "$NPM_SBOM_SHA"
printf 'IMAGE_SBOM_SHA256=%s\n' "$IMAGE_SBOM_SHA"
printf 'PROVENANCE=%s\n' "$OUTPUT/provenance.intoto.json"
