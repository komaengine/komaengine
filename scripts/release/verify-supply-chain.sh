#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOCK_FILE="$ROOT/scripts/release/image-lock.env"
SOURCE_REF=''
ARTIFACT=''
IMAGE=''
IMAGE_DIGEST=''
EVIDENCE=''

usage() {
    cat <<'EOF'
Usage: scripts/release/verify-supply-chain.sh \
  --source-ref COMMIT --artifact FILE --image REF --evidence DIRECTORY \
  [--image-digest sha256:...]
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source-ref) SOURCE_REF="${2:-}"; shift 2 ;;
        --artifact) ARTIFACT="${2:-}"; shift 2 ;;
        --image) IMAGE="${2:-}"; shift 2 ;;
        --image-digest) IMAGE_DIGEST="${2:-}"; shift 2 ;;
        --evidence) EVIDENCE="${2:-}"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) echo "Unknown verification argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

for tool in cmp docker git jq shasum unzip; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "Required verification tool is missing: $tool" >&2
        exit 2
    }
done

[[ "$SOURCE_REF" =~ ^[0-9a-f]{40}$ ]] \
    && [[ "$(git -C "$ROOT" rev-parse --verify "${SOURCE_REF}^{commit}" 2>/dev/null)" == "$SOURCE_REF" ]] \
    && [[ -f "$ARTIFACT" && -n "$IMAGE" && -d "$EVIDENCE" ]] || {
    usage >&2
    exit 2
}

ARTIFACT="$(cd "$(dirname "$ARTIFACT")" && pwd)/$(basename "$ARTIFACT")"
EVIDENCE="$(cd "$EVIDENCE" && pwd)"
for file in flatfile.spdx.json npm.spdx.json image.spdx.json provenance.intoto.json image-lock.env SHA256SUMS; do
    [[ -f "$EVIDENCE/$file" ]] || {
        echo "Supply-chain evidence is missing: $file" >&2
        exit 1
    }
done

(
    cd "$EVIDENCE"
    shasum -a 256 -c SHA256SUMS >/dev/null
)
cmp "$LOCK_FILE" "$EVIDENCE/image-lock.env" >/dev/null || {
    echo 'Evidence image lock does not match the source image lock.' >&2
    exit 1
}

TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-supply-chain-verify.XXXXXX")"
trap 'rm -rf "$TEMP_ROOT"' EXIT
git -C "$ROOT" show "$SOURCE_REF:scripts/release/image-lock.env" \
    > "$TEMP_ROOT/source-image-lock.env"
cmp "$LOCK_FILE" "$TEMP_ROOT/source-image-lock.env" >/dev/null || {
    echo 'Working image lock does not match the exact source commit.' >&2
    exit 1
}
unzip -tq "$ARTIFACT" >/dev/null
unzip -qq "$ARTIFACT" 'komaengine/KOMA-BUILD-METADATA.txt' \
    'komaengine/composer.lock' 'komaengine/package-lock.json' -d "$TEMP_ROOT"
METADATA="$TEMP_ROOT/komaengine/KOMA-BUILD-METADATA.txt"

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
COMPOSER_LOCK_SHA="$(shasum -a 256 "$TEMP_ROOT/komaengine/composer.lock" | awk '{print $1}')"
PACKAGE_LOCK_SHA="$(shasum -a 256 "$TEMP_ROOT/komaengine/package-lock.json" | awk '{print $1}')"
ARTIFACT_SHA="$(shasum -a 256 "$ARTIFACT" | awk '{print $1}')"
LOCK_SHA="$(shasum -a 256 "$LOCK_FILE" | awk '{print $1}')"
FLATFILE_SBOM_SHA="$(shasum -a 256 "$EVIDENCE/flatfile.spdx.json" | awk '{print $1}')"
NPM_SBOM_SHA="$(shasum -a 256 "$EVIDENCE/npm.spdx.json" | awk '{print $1}')"
IMAGE_SBOM_SHA="$(shasum -a 256 "$EVIDENCE/image.spdx.json" | awk '{print $1}')"

[[ "$(metadata_value source_commit "$METADATA")" == "$SOURCE_REF" \
    && "$(metadata_value source_dirty "$METADATA")" == false \
    && "$(metadata_value source_policy "$METADATA")" == exact-commit \
    && "$RELEASE_VERSION" == "$SOURCE_VERSION" \
    && "$BUILD_ID" == "${RELEASE_VERSION}+${SOURCE_REF:0:12}" \
    && "$(metadata_value composer_lock_sha256 "$METADATA")" == "$COMPOSER_LOCK_SHA" \
    && "$(metadata_value package_lock_sha256 "$METADATA")" == "$PACKAGE_LOCK_SHA" ]] || {
    echo 'Flat-file artifact metadata is not internally consistent.' >&2
    exit 1
}
[[ "$COMPOSER_LOCK_SHA" == "$(git -C "$ROOT" show "$SOURCE_REF:composer.lock" | shasum -a 256 | awk '{print $1}')" \
    && "$PACKAGE_LOCK_SHA" == "$(git -C "$ROOT" show "$SOURCE_REF:package-lock.json" | shasum -a 256 | awk '{print $1}')" ]] || {
    echo 'Artifact dependency locks do not match the exact source commit.' >&2
    exit 1
}

IMAGE_METADATA="$TEMP_ROOT/image-metadata.txt"
docker run --rm --entrypoint sh "$IMAGE" -c \
    'cat /var/www/html/KOMA-BUILD-METADATA.txt' > "$IMAGE_METADATA"
[[ "$(metadata_value source_commit "$IMAGE_METADATA")" == "$SOURCE_REF" \
    && "$(metadata_value release_version "$IMAGE_METADATA")" == "$RELEASE_VERSION" \
    && "$(metadata_value build_id "$IMAGE_METADATA")" == "$BUILD_ID" \
    && "$(metadata_value composer_lock_sha256 "$IMAGE_METADATA")" == "$COMPOSER_LOCK_SHA" \
    && "$(metadata_value package_lock_sha256 "$IMAGE_METADATA")" == "$PACKAGE_LOCK_SHA" ]] || {
    echo 'Runtime image metadata does not match the artifact/source identity.' >&2
    exit 1
}

if [[ -z "$IMAGE_DIGEST" ]]; then
    IMAGE_DIGEST="$(docker image inspect --format '{{.Id}}' "$IMAGE")"
fi
[[ "$IMAGE_DIGEST" =~ ^sha256:[0-9a-f]{64}$ ]] || {
    echo 'Image digest must be a sha256 digest.' >&2
    exit 2
}

for sbom in flatfile.spdx.json npm.spdx.json image.spdx.json; do
    jq -e '.spdxVersion | startswith("SPDX-")' "$EVIDENCE/$sbom" >/dev/null || {
        echo "Evidence file is not an SPDX document: $sbom" >&2
        exit 1
    }
done
jq -e '[.packages[]?.name] | index("laravel/framework") != null' \
    "$EVIDENCE/flatfile.spdx.json" >/dev/null || {
    echo 'Flat-file SBOM does not contain the Laravel Composer dependency.' >&2
    exit 1
}
jq -e '[.packages[]?.name] | index("vite") != null' \
    "$EVIDENCE/npm.spdx.json" >/dev/null || {
    echo 'npm SBOM does not contain the Vite build dependency.' >&2
    exit 1
}
jq -e '[.packages[]?.name] | index("apache2") != null' \
    "$EVIDENCE/image.spdx.json" >/dev/null || {
    echo 'Image SBOM does not contain the Apache OS package.' >&2
    exit 1
}
jq -e '[.packages[]?.name] | index("mariadb-client") != null' \
    "$EVIDENCE/image.spdx.json" >/dev/null || {
    echo 'Image SBOM does not contain the MariaDB runtime client.' >&2
    exit 1
}

jq -e \
    --arg artifact_name "$(basename "$ARTIFACT")" \
    --arg artifact_sha "$ARTIFACT_SHA" \
    --arg image_name "$IMAGE" \
    --arg image_sha "${IMAGE_DIGEST#sha256:}" \
    --arg source_commit "$SOURCE_REF" \
    --arg release_version "$RELEASE_VERSION" \
    --arg build_id "$BUILD_ID" \
    --arg composer_lock_sha "$COMPOSER_LOCK_SHA" \
    --arg package_lock_sha "$PACKAGE_LOCK_SHA" \
    --arg image_lock_sha "$LOCK_SHA" \
    --arg flatfile_sbom_sha "$FLATFILE_SBOM_SHA" \
    --arg npm_sbom_sha "$NPM_SBOM_SHA" \
    --arg image_sbom_sha "$IMAGE_SBOM_SHA" '
      ._type == "https://in-toto.io/Statement/v1" and
      .predicateType == "https://slsa.dev/provenance/v1" and
      .predicate.buildDefinition.buildType == "https://komaengine.org/build-types/community-release/v1" and
      ([.subject[] | select(.name == $artifact_name and .digest.sha256 == $artifact_sha)] | length) == 1 and
      ([.subject[] | select(.name == $image_name and .digest.sha256 == $image_sha)] | length) == 1 and
      .predicate.buildDefinition.externalParameters.sourceCommit == $source_commit and
      .predicate.buildDefinition.externalParameters.releaseVersion == $release_version and
      .predicate.buildDefinition.externalParameters.buildId == $build_id and
      .predicate.buildDefinition.internalParameters.composerLockSha256 == $composer_lock_sha and
      .predicate.buildDefinition.internalParameters.packageLockSha256 == $package_lock_sha and
      .predicate.buildDefinition.internalParameters.imageLockSha256 == $image_lock_sha and
      .predicate.buildDefinition.internalParameters.sbom.flatfile.sha256 == $flatfile_sbom_sha and
      .predicate.buildDefinition.internalParameters.sbom.npm.sha256 == $npm_sbom_sha and
      .predicate.buildDefinition.internalParameters.sbom.image.sha256 == $image_sbom_sha and
      ([.predicate.buildDefinition.resolvedDependencies[] |
          select(.digest.gitCommit == $source_commit)] | length) == 1
    ' "$EVIDENCE/provenance.intoto.json" >/dev/null || {
    echo 'Provenance does not bind the exact artifact, image, source, locks, and SBOMs.' >&2
    exit 1
}

printf 'Supply-chain evidence verified for %s (%s).\n' "$SOURCE_REF" "$BUILD_ID"
