#!/usr/bin/env bash

# Scan the runtime image built from one exact commit with the pinned scanner
# and the same gate the manual supply-chain workflow applies: any critical or
# high finding that has a fix available fails. Without this check a stale base
# image is discovered only when the workflow runs.
#
# Set KOMA_TEST_IMAGE to scan an image that already exists locally instead of
# building one, for example a retained candidate image.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_COMMIT="${KOMA_TEST_SOURCE_COMMIT:-$(git -C "$ROOT" rev-parse HEAD)}"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-image-scan.XXXXXX")"
CONTEXT="$TEMP_ROOT/context"
BUILT_IMAGE="koma-image-scan-$RANDOM-$$:local"
IMAGE="${KOMA_TEST_IMAGE:-$BUILT_IMAGE}"
ARCHIVE="$TEMP_ROOT/image.tar"
REPORT="$TEMP_ROOT/report.json"

# These must stay identical to the scan step in the workflow.
GATE_SCOPE='image --scanners vuln --ignore-unfixed'
GATE_LIMIT='--severity HIGH,CRITICAL --exit-code 1'

cleanup() {
    docker image rm "$BUILT_IMAGE" >/dev/null 2>&1 || true
    rm -rf "$TEMP_ROOT"
}
trap cleanup EXIT

if [[ ! "$SOURCE_COMMIT" =~ ^[0-9a-f]{40}$ ]] \
    || [[ "$(git -C "$ROOT" rev-parse --verify "${SOURCE_COMMIT}^{commit}" 2>/dev/null)" != "$SOURCE_COMMIT" ]]; then
    echo "KOMA_TEST_SOURCE_COMMIT must be an available full lowercase 40-character Git commit." >&2
    exit 2
fi

mkdir -p "$CONTEXT"
git -C "$ROOT" archive "$SOURCE_COMMIT" | tar -x -C "$CONTEXT"
. "$CONTEXT/scripts/release/image-lock.env"

# Workflows are not part of an exported tree, so read them from the commit.
# Every workflow that scans images present at that commit must use this gate.
checked_workflows=0
for workflow in release.yml supply-chain.yml; do
    git -C "$ROOT" show "$SOURCE_COMMIT:.github/workflows/$workflow" > "$TEMP_ROOT/workflow.yml" 2>/dev/null \
        || continue
    for literal in "$GATE_SCOPE" "$GATE_LIMIT"; do
        grep -Fq -- "$literal" "$TEMP_ROOT/workflow.yml" || {
            echo "The $workflow scan gate no longer matches this smoke: $literal" >&2
            exit 1
        }
    done
    checked_workflows=$((checked_workflows + 1))
done
[[ "$checked_workflows" -gt 0 ]] || {
    echo 'No image-scanning workflow exists at this commit.' >&2
    exit 1
}

if [[ -z "${KOMA_TEST_IMAGE:-}" ]]; then
    RELEASE_VERSION="$(tr -d '\r\n' < "$CONTEXT/VERSION")"
    docker build \
        --quiet \
        --file "$CONTEXT/Dockerfile" \
        --tag "$BUILT_IMAGE" \
        --build-arg "KOMA_RELEASE_VERSION=$RELEASE_VERSION" \
        --build-arg "KOMA_RELEASE_COMMIT=$SOURCE_COMMIT" \
        --build-arg KOMA_RELEASE_DIRTY=false \
        --build-arg "KOMA_RELEASE_BUILD_ID=${RELEASE_VERSION}+${SOURCE_COMMIT:0:12}" \
        --build-arg KOMA_TRUSTED_BUILD=true \
        "$CONTEXT" >/dev/null
fi

# The scanner reads a saved image rather than the Docker socket, so a
# third-party container is never given control of the local daemon.
docker save --output "$ARCHIVE" "$IMAGE"

# One container, so the vulnerability database is downloaded once. The first
# pass records what was examined; the second is the gate and prints a table.
status=0
docker run --rm \
    --entrypoint sh \
    --env "GATE_SCOPE=$GATE_SCOPE" \
    --env "GATE_LIMIT=$GATE_LIMIT" \
    --volume "$ARCHIVE:/image.tar:ro" \
    "$KOMA_TRIVY_IMAGE" -c '
        set -eu
        # shellcheck disable=SC2086
        trivy $GATE_SCOPE --severity HIGH,CRITICAL --quiet --format json \
            --output /tmp/report.json --input /image.tar >&2 || exit 2
        cat /tmp/report.json
        # shellcheck disable=SC2086
        trivy $GATE_SCOPE $GATE_LIMIT --quiet --input /image.tar >&2
    ' > "$REPORT" || status=$?

# A scan that examined nothing would also report nothing. Require evidence
# that operating-system and Composer packages were actually inspected.
for marker in '"Family": "debian"' '"Class": "os-pkgs"' 'composer/installed.json'; do
    grep -Fq -- "$marker" "$REPORT" || {
        echo "The scan did not examine what the gate depends on: $marker" >&2
        exit 2
    }
done

if [[ "$status" -ne 0 ]]; then
    echo "Image vulnerability scan failed for $IMAGE (scanner exit $status)." >&2
    exit 1
fi

if grep -Fq '"VulnerabilityID"' "$REPORT"; then
    echo 'The scanner passed the gate but its report lists findings.' >&2
    exit 1
fi

printf 'Image vulnerability scan smoke passed for %s (%s).\n' "$SOURCE_COMMIT" "$IMAGE"
