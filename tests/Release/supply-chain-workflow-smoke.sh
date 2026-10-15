#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-supply-chain-workflow.XXXXXX")"
trap 'rm -rf -- "$TEMP_ROOT"' EXIT
FIXTURE="$TEMP_ROOT/fixture"
CHECKS=0

mkdir -p "$FIXTURE/scripts/release" "$FIXTURE/tests/Release" "$FIXTURE/tests/Browser"
for file in \
    Dockerfile docker-compose.yml \
    scripts/release/image-lock.env scripts/release/pin-exported-dockerfile.sh \
    tests/Release/image-pins-smoke.sh tests/Release/flatfile-builder.Dockerfile \
    tests/Release/php-matrix.Dockerfile \
    tests/Browser/Dockerfile tests/Browser/docker-compose.yml; do
    cp "$ROOT/$file" "$FIXTURE/$file"
done

expect_failure() {
    local expected="$1"
    shift
    if "$@" > "$TEMP_ROOT/failure.log" 2>&1; then
        echo "Workflow regression accepted failure case: $expected" >&2
        exit 1
    fi
    grep -Fq -- "$expected" "$TEMP_ROOT/failure.log"
    CHECKS=$((CHECKS + 1))
}

# The actual CI runner has no ripgrep. A poisoned function also prevents a
# developer machine with rg installed from hiding an accidental dependency.
rg() { return 127; }
export -f rg
bash "$FIXTURE/tests/Release/image-pins-smoke.sh" >/dev/null
CHECKS=$((CHECKS + 1))

printf '%s\n' 'FROM fixture:latest' >> "$FIXTURE/tests/Browser/Dockerfile"
expect_failure 'uses a latest image tag' \
    bash "$FIXTURE/tests/Release/image-pins-smoke.sh"
cp "$ROOT/tests/Browser/Dockerfile" "$FIXTURE/tests/Browser/Dockerfile"
printf '%s\n' 'FROM composer:2' >> "$FIXTURE/tests/Browser/Dockerfile"
expect_failure 'mutable legacy image reference' \
    bash "$FIXTURE/tests/Release/image-pins-smoke.sh"
cp "$ROOT/tests/Browser/Dockerfile" "$FIXTURE/tests/Browser/Dockerfile"

grep() {
    if [[ "${1:-}" == '-Enr' ]]; then
        return 2
    fi
    command grep "$@"
}
export -f grep
expect_failure 'Image reference scan failed (grep exit 2)' \
    bash "$FIXTURE/tests/Release/image-pins-smoke.sh"
unset -f grep

TEST_DIGEST="sha256:$(printf '%064d' 1)"
export TEST_DIGEST
docker() {
    case "${1:-} ${2:-} ${3:-}" in
        'buildx imagetools create') return 0 ;;
        'buildx imagetools inspect')
            if [[ "${5:-}" == '--format' ]]; then
                [[ $# -eq 6 && "$6" == '{{.Manifest.Digest}}' ]] || return 2
                printf '%s\n' "${KOMA_TEST_DIGEST_OUTPUT:-$TEST_DIGEST}"
                return "${KOMA_TEST_INSPECT_EXIT:-0}"
            fi
            # Only locked image-resolution checks may omit the template.
            [[ "${4:-}" == *@sha256:* ]]
            ;;
        *) return 2 ;;
    esac
}
export -f docker

# Exercise each workflow's real assembly body, not a copied version of its
# digest logic. The proof workflow exists only in the maintainer repository.
extract_step() {
    local workflow="$1" step="$2" output="$3"
    awk -v step="      - name: $step" '
        $0 == step {inside = 1; next}
        inside && /^      - name:/ {exit}
        inside && /^        run: \|$/ {body = 1; next}
        body {sub(/^          /, ""); print}
    ' "$ROOT/.github/workflows/$workflow" > "$output"
    test -s "$output"
    bash -n "$output"
}

run_assembly() {
    local workflow="$1" name="$2" output="$3" inspect_exit="$4"
    local script="$TEMP_ROOT/assemble-$workflow.sh"
    local status digests
    export RUNNER_TEMP="$TEMP_ROOT/$workflow-$name"
    export GITHUB_OUTPUT="$RUNNER_TEMP/outputs"
    export GITHUB_SHA="$(printf '%040d' 3)"
    export REGISTRY_IMAGE='ghcr.io/example/koma-workflow-test'
    export RELEASE_IMAGE_TAGS='1.2.3 1.2 1 latest'
    export KOMA_TEST_DIGEST_OUTPUT="$output"
    export KOMA_TEST_INSPECT_EXIT="$inspect_exit"
    if [[ "$workflow" == release.yml ]]; then
        mkdir -p "$RUNNER_TEMP/architectures/architecture-amd64" "$RUNNER_TEMP/architectures/architecture-arm64"
        printf '%s\n' "$TEST_DIGEST" > "$RUNNER_TEMP/architectures/architecture-amd64/image-digest-amd64.txt"
        printf 'sha256:%064d\n' 2 > "$RUNNER_TEMP/architectures/architecture-arm64/image-digest-arm64.txt"
    else
        mkdir -p "$RUNNER_TEMP/digests" "$RUNNER_TEMP/release-evidence"
        printf '%s\n' "$TEST_DIGEST" > "$RUNNER_TEMP/digests/image-digest-amd64.txt"
        printf 'sha256:%064d\n' 2 > "$RUNNER_TEMP/digests/image-digest-arm64.txt"
    fi

    if (cd "$FIXTURE" && bash "$script") > "$RUNNER_TEMP/assembly.log" 2>&1; then
        status=0
    else
        status=$?
    fi
    if [[ "$name" == valid ]]; then
        [[ "$status" -eq 0 ]]
        grep -Fxq "digest=$TEST_DIGEST" "$GITHUB_OUTPUT"
        grep -Fxq "$TEST_DIGEST" "$RUNNER_TEMP/release-evidence/image-index-digest.txt"
    else
        [[ "$status" -ne 0 && ! -e "$GITHUB_OUTPUT" \
            && ! -e "$RUNNER_TEMP/release-evidence/image-index-digest.txt" ]]
        if [[ "$inspect_exit" -ne 0 ]]; then
            [[ "$status" -eq "$inspect_exit" ]]
        fi
    fi
    CHECKS=$((CHECKS + 1))
}

assembled=0
for case in \
    'supply-chain.yml|Verify pins and assemble multi-architecture image' \
    'release.yml|Verify pins and assemble the multi-architecture image'; do
    workflow="${case%%|*}"
    [[ -f "$ROOT/.github/workflows/$workflow" ]] || continue
    extract_step "$workflow" "${case#*|}" "$TEMP_ROOT/assemble-$workflow.sh"
    run_assembly "$workflow" valid "$TEST_DIGEST" 0
    run_assembly "$workflow" malformed 'not-a-digest' 0
    run_assembly "$workflow" registry-failure "$TEST_DIGEST" 255
    assembled=$((assembled + 1))
done
[[ "$assembled" -gt 0 ]] || { echo 'No workflow assembly step was found.' >&2; exit 1; }

printf 'Supply-chain workflow regression smoke passed (%s checks).\n' "$CHECKS"
