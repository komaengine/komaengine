#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_COMMIT="${KOMA_TEST_SOURCE_COMMIT:-$(git -C "$ROOT" rev-parse HEAD)}"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-supply-chain.XXXXXX")"
CONTEXT="$TEMP_ROOT/context"
ARTIFACT_OUTPUT="$TEMP_ROOT/artifacts"
EVIDENCE="$TEMP_ROOT/evidence"
BUILDER_IMAGE="koma-supply-chain-builder-$RANDOM-$$:local"
APP_IMAGE="koma-supply-chain-app-$RANDOM-$$:local"
BUILD_LOG="$TEMP_ROOT/build.log"
ERROR_LOG="$TEMP_ROOT/error.log"

maintainer_volume_identity() {
    local name
    for name in komaengine_koma-db komaengine_koma-storage; do
        if docker volume inspect "$name" >/dev/null 2>&1; then
            docker volume inspect --format '{{.Name}} {{.CreatedAt}}' "$name"
        else
            printf '%s absent\n' "$name"
        fi
    done | LC_ALL=C sort | shasum -a 256 | awk '{print $1}'
}

MAINTAINER_BEFORE="$(maintainer_volume_identity)"
STATUS_BEFORE="$(git -C "$ROOT" status --porcelain=v1 -uall | shasum -a 256 | awk '{print $1}')"
DIFF_BEFORE="$(git -C "$ROOT" diff --binary | shasum -a 256 | awk '{print $1}')"

cleanup() {
    docker image rm "$BUILDER_IMAGE" "$APP_IMAGE" >/dev/null 2>&1 || true
    rm -rf "$TEMP_ROOT"
}
trap cleanup EXIT

[[ "$SOURCE_COMMIT" =~ ^[0-9a-f]{40}$ ]] \
    && [[ "$(git -C "$ROOT" rev-parse --verify "${SOURCE_COMMIT}^{commit}" 2>/dev/null)" == "$SOURCE_COMMIT" ]] || {
    echo 'KOMA_TEST_SOURCE_COMMIT must be an available full lowercase Git commit.' >&2
    exit 2
}

mkdir -p "$CONTEXT" "$ARTIFACT_OUTPUT"
git -C "$ROOT" archive "$SOURCE_COMMIT" | tar -x -C "$CONTEXT"
RELEASE_VERSION="$(tr -d '\r\n' < "$CONTEXT/VERSION")"
BUILD_ID="${RELEASE_VERSION}+${SOURCE_COMMIT:0:12}"

"$ROOT/tests/Release/image-pins-smoke.sh"

docker build \
    --quiet \
    --file "$CONTEXT/tests/Release/flatfile-builder.Dockerfile" \
    --tag "$BUILDER_IMAGE" \
    "$CONTEXT" >/dev/null

docker run --rm \
    --env KOMA_SOURCE_REF="$SOURCE_COMMIT" \
    --env GIT_CONFIG_COUNT=1 \
    --env GIT_CONFIG_KEY_0=safe.directory \
    --env GIT_CONFIG_VALUE_0=/workspace \
    --volume "$ROOT:/workspace:ro" \
    --volume "$ARTIFACT_OUTPUT:/output" \
    --workdir /workspace \
    "$BUILDER_IMAGE" \
    bash -lc 'scripts/build-flatfile.sh /output' | tee "$BUILD_LOG"

ARTIFACT="$ARTIFACT_OUTPUT/$(basename "$(sed -n 's/^ARCHIVE=//p' "$BUILD_LOG" | tail -n 1)")"
[[ -f "$ARTIFACT" ]] || {
    echo 'Exact-source flat-file build did not produce an archive.' >&2
    exit 1
}

docker run --rm \
    --env KOMA_EXPECTED_SOURCE_COMMIT="$SOURCE_COMMIT" \
    --env KOMA_EXPECTED_RELEASE_VERSION="$RELEASE_VERSION" \
    --volume "$ROOT:/workspace:ro" \
    --volume "$ARTIFACT_OUTPUT:/output:ro" \
    --workdir /workspace \
    "$BUILDER_IMAGE" \
    bash -lc "tests/Release/inspect-artifact.sh '/output/$(basename "$ARTIFACT")'"

docker build \
    --quiet \
    --file "$CONTEXT/Dockerfile" \
    --tag "$APP_IMAGE" \
    --build-arg "KOMA_RELEASE_VERSION=$RELEASE_VERSION" \
    --build-arg "KOMA_RELEASE_COMMIT=$SOURCE_COMMIT" \
    --build-arg KOMA_RELEASE_DIRTY=false \
    --build-arg "KOMA_RELEASE_BUILD_ID=$BUILD_ID" \
    --build-arg KOMA_TRUSTED_BUILD=true \
    "$CONTEXT" >/dev/null

docker run --rm --entrypoint sh "$APP_IMAGE" -c \
    '! command -v composer && ! command -v git && ! command -v unzip'

"$ROOT/scripts/release/generate-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --artifact "$ARTIFACT" \
    --image "$APP_IMAGE" \
    --output "$EVIDENCE" \
    --builder-id 'local:komaengine/tests/Release/supply-chain-smoke.sh'

"$ROOT/scripts/release/verify-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --artifact "$ARTIFACT" \
    --image "$APP_IMAGE" \
    --evidence "$EVIDENCE"

TAMPERED_ARTIFACT="$TEMP_ROOT/tampered.zip"
cp "$ARTIFACT" "$TAMPERED_ARTIFACT"
printf 'tampered\n' >> "$TAMPERED_ARTIFACT"
if "$ROOT/scripts/release/verify-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --artifact "$TAMPERED_ARTIFACT" \
    --image "$APP_IMAGE" \
    --evidence "$EVIDENCE" > /dev/null 2> "$ERROR_LOG"; then
    echo 'Supply-chain verification accepted a byte-tampered artifact.' >&2
    exit 1
fi
grep -Fq 'Provenance does not bind' "$ERROR_LOG"

TAMPERED_EVIDENCE="$TEMP_ROOT/tampered-evidence"
cp -R "$EVIDENCE" "$TAMPERED_EVIDENCE"
jq '.predicate.buildDefinition.externalParameters.sourceCommit =
    "0000000000000000000000000000000000000000"' \
    "$TAMPERED_EVIDENCE/provenance.intoto.json" \
    > "$TAMPERED_EVIDENCE/provenance.tmp"
mv "$TAMPERED_EVIDENCE/provenance.tmp" "$TAMPERED_EVIDENCE/provenance.intoto.json"
(
    cd "$TAMPERED_EVIDENCE"
    shasum -a 256 \
        flatfile.spdx.json npm.spdx.json image.spdx.json provenance.intoto.json image-lock.env \
        > SHA256SUMS
)
if "$ROOT/scripts/release/verify-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --artifact "$ARTIFACT" \
    --image "$APP_IMAGE" \
    --evidence "$TAMPERED_EVIDENCE" > /dev/null 2> "$ERROR_LOG"; then
    echo 'Supply-chain verification accepted mismatched provenance.' >&2
    exit 1
fi
grep -Fq 'Provenance does not bind' "$ERROR_LOG"

[[ "$(maintainer_volume_identity)" == "$MAINTAINER_BEFORE" ]] || {
    echo 'Authoritative maintainer volume identities changed during the supply-chain smoke.' >&2
    exit 1
}
[[ "$(git -C "$ROOT" status --porcelain=v1 -uall | shasum -a 256 | awk '{print $1}')" == "$STATUS_BEFORE" \
    && "$(git -C "$ROOT" diff --binary | shasum -a 256 | awk '{print $1}')" == "$DIFF_BEFORE" ]] || {
    echo 'The supply-chain smoke mutated the source worktree.' >&2
    exit 1
}

printf 'Supply-chain SBOM/provenance/tamper smoke passed for %s.\n' "$SOURCE_COMMIT"
