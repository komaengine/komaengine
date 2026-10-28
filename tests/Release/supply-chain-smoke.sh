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

# Run as GitHub Actions would not: the source URI must be given explicitly.
unset GITHUB_SERVER_URL GITHUB_REPOSITORY
SOURCE_URI='https://github.com/komaengine/komaengine'

if "$ROOT/scripts/release/generate-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --artifact "$ARTIFACT" \
    --image "$APP_IMAGE" \
    --output "$TEMP_ROOT/no-uri-evidence" > /dev/null 2> "$ERROR_LOG"; then
    echo 'Supply-chain generation accepted a missing source URI.' >&2
    exit 1
fi
grep -Fq 'source URI' "$ERROR_LOG"

"$ROOT/scripts/release/generate-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --source-uri "$SOURCE_URI" \
    --artifact "$ARTIFACT" \
    --image "$APP_IMAGE" \
    --output "$EVIDENCE" \
    --builder-id 'local:komaengine/tests/Release/supply-chain-smoke.sh'

jq -e --arg uri "$SOURCE_URI" --arg commit "$SOURCE_COMMIT" '
    .predicate.buildDefinition.buildType == "https://komaengine.com/build-types/community-release/v1"
    and ([.predicate.buildDefinition.resolvedDependencies[] | select(.uri == $uri and .digest.gitCommit == $commit)] | length) == 1
' "$EVIDENCE/provenance.intoto.json" > /dev/null

"$ROOT/scripts/release/verify-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --source-uri "$SOURCE_URI" \
    --artifact "$ARTIFACT" \
    --image "$APP_IMAGE" \
    --evidence "$EVIDENCE"

if "$ROOT/scripts/release/verify-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --source-uri 'https://github.com/example/komaengine-fork' \
    --artifact "$ARTIFACT" \
    --image "$APP_IMAGE" \
    --evidence "$EVIDENCE" > /dev/null 2> "$ERROR_LOG"; then
    echo 'Supply-chain verification accepted another source repository.' >&2
    exit 1
fi
grep -Fq 'Provenance does not bind' "$ERROR_LOG"

if "$ROOT/scripts/release/verify-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --artifact "$ARTIFACT" \
    --image "$APP_IMAGE" \
    --evidence "$EVIDENCE" > /dev/null 2> "$ERROR_LOG"; then
    echo 'Supply-chain verification ran without a source URI.' >&2
    exit 1
fi
grep -Fq 'source repository (--source-uri)' "$ERROR_LOG"

TAMPERED_ARTIFACT="$TEMP_ROOT/tampered.zip"
cp "$ARTIFACT" "$TAMPERED_ARTIFACT"
printf 'tampered\n' >> "$TAMPERED_ARTIFACT"
if "$ROOT/scripts/release/verify-supply-chain.sh" \
    --source-ref "$SOURCE_COMMIT" \
    --source-uri "$SOURCE_URI" \
    --artifact "$TAMPERED_ARTIFACT" \
    --image "$APP_IMAGE" \
    --evidence "$EVIDENCE" > /dev/null 2> "$ERROR_LOG"; then
    echo 'Supply-chain verification accepted a byte-tampered artifact.' >&2
    exit 1
fi
grep -Fq 'Provenance does not bind' "$ERROR_LOG"

TAMPERED_EVIDENCE="$TEMP_ROOT/tampered-evidence"
cp -R "$EVIDENCE" "$TAMPERED_EVIDENCE"
# Each tampered provenance keeps valid checksums, so only the provenance
# check itself can catch it.
reject_tampered_provenance() {
    local name="$1"
    local filter="$2"
    local tampered="$TEMP_ROOT/tampered-$name"

    cp -R "$EVIDENCE" "$tampered"
    jq "$filter" "$tampered/provenance.intoto.json" > "$tampered/provenance.tmp"
    mv "$tampered/provenance.tmp" "$tampered/provenance.intoto.json"
    (
        cd "$tampered"
        shasum -a 256 \
            flatfile.spdx.json npm.spdx.json image.spdx.json provenance.intoto.json image-lock.env \
            > SHA256SUMS
    )
    if "$ROOT/scripts/release/verify-supply-chain.sh" \
        --source-ref "$SOURCE_COMMIT" \
        --source-uri "$SOURCE_URI" \
        --artifact "$ARTIFACT" \
        --image "$APP_IMAGE" \
        --evidence "$tampered" > /dev/null 2> "$ERROR_LOG"; then
        echo "Supply-chain verification accepted tampered provenance: $name." >&2
        exit 1
    fi
    grep -Fq "$3" "$ERROR_LOG"
}

reject_tampered_provenance commit \
    '.predicate.buildDefinition.externalParameters.sourceCommit = "0000000000000000000000000000000000000000"' \
    'Provenance does not bind'
reject_tampered_provenance uri \
    '(.predicate.buildDefinition.resolvedDependencies[] | select(.digest.gitCommit != null) | .uri) = "https://github.com/example/komaengine-fork"' \
    'Provenance does not bind'
# The old build type is only accepted for 1.0.x evidence, which this is not
# once VERSION moves past 1.0; before that it is checked by commit alone.
if [[ ! "$RELEASE_VERSION" =~ ^1\.0\. ]]; then
    reject_tampered_provenance legacy-build-type \
        '.predicate.buildDefinition.buildType = "https://komaengine.org/build-types/community-release/v1"' \
        'only valid for 1.0.x evidence'
fi

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
