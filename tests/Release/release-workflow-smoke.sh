#!/usr/bin/env bash

# Regression checks for the tag-driven release workflow, runnable anywhere:
# the identity rules in scripts/release/release-identity.sh against throwaway
# Git repositories, static safety properties of .github/workflows/release.yml,
# and (unless --no-lint) actionlint from the pinned image.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/release.yml"
IDENTITY="$ROOT/scripts/release/release-identity.sh"
LINT=true
[[ "${1:-}" == --no-lint ]] && LINT=false
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-release-workflow.XXXXXX")"
trap 'rm -rf -- "$TEMP_ROOT"' EXIT
CHECKS=0
PUBLIC=komaengine/komaengine

# A throwaway repository whose VERSION and tag are set per case.
fixture() {
    local version="$1" tag="$2" dir
    dir="$(mktemp -d "$TEMP_ROOT/repo.XXXXXX")"
    git -C "$dir" init --quiet
    printf '%s\n' "$version" > "$dir/VERSION"
    git -C "$dir" add VERSION
    git -C "$dir" -c user.name=smoke -c user.email=smoke@example.invalid \
        commit --quiet --no-gpg-sign -m fixture
    git -C "$dir" -c user.name=smoke -c user.email=smoke@example.invalid \
        tag --no-sign -a "$tag" -m "$tag"
    printf '%s\n' "$dir"
}

# run_identity DIR EVENT REPOSITORY TAG [WORKFLOW_REF] [SHA]
run_identity() {
    local dir="$1" event="$2" repository="$3" tag="$4"
    local workflow_ref="${5:-$repository/.github/workflows/release.yml@refs/tags/$tag}"
    local sha="${6:-$(git -C "$dir" rev-parse HEAD)}"
    (
        cd "$dir"
        GITHUB_OUTPUT='' \
        GITHUB_EVENT_NAME="$event" \
        GITHUB_REPOSITORY="$repository" \
        GITHUB_REPOSITORY_OWNER="${repository%%/*}" \
        GITHUB_WORKFLOW_REF="$workflow_ref" \
        GITHUB_SHA="$sha" \
        KOMA_RELEASE_TAG="$tag" \
        "$IDENTITY" 2>/dev/null
    )
}

expect_value() {
    local output="$1" key="$2" expected="$3" actual
    actual="$(sed -n "s/^$key=//p" <<< "$output")"
    [[ "$actual" == "$expected" ]] || {
        echo "Release identity $key was '$actual', expected '$expected'." >&2
        exit 1
    }
}

expect_refused() {
    local description="$1"
    shift
    if run_identity "$@" >/dev/null; then
        echo "Release identity accepted: $description" >&2
        exit 1
    fi
    CHECKS=$((CHECKS + 1))
}

# A stable release in the public repository publishes the moving tags.
repo="$(fixture 1.2.3 v1.2.3)"
out="$(run_identity "$repo" push "$PUBLIC" v1.2.3)"
expect_value "$out" mode release
expect_value "$out" image "ghcr.io/$PUBLIC"
expect_value "$out" image_tags '1.2.3 1.2 1 latest'
expect_value "$out" prerelease false
expect_value "$out" commit "$(git -C "$repo" rev-parse HEAD)"
expect_value "$out" build_id "1.2.3+$(git -C "$repo" rev-parse --short=12 HEAD)"
expect_value "$out" identity "https://github.com/$PUBLIC/.github/workflows/release.yml@refs/tags/v1.2.3"
CHECKS=$((CHECKS + 1))

# A prerelease publishes only its exact version tag.
repo="$(fixture 2.0.0-rc.1 v2.0.0-rc.1)"
out="$(run_identity "$repo" push "$PUBLIC" v2.0.0-rc.1)"
expect_value "$out" image_tags '2.0.0-rc.1'
expect_value "$out" prerelease true
CHECKS=$((CHECKS + 1))

# A manual run elsewhere is a rehearsal under test names.
repo="$(fixture 1.2.3 v1.2.3)"
out="$(run_identity "$repo" workflow_dispatch Example/KomaEngine v1.2.3 \
    'Example/KomaEngine/.github/workflows/release.yml@refs/heads/main')"
expect_value "$out" mode rehearsal
expect_value "$out" image 'ghcr.io/example/komaengine-release-rehearsal'
expect_value "$out" image_tags 'rehearsal-1.2.3'
expect_value "$out" prerelease true
CHECKS=$((CHECKS + 1))

repo="$(fixture 1.2.3 v1.2.3)"
expect_refused 'a tag push outside the public repository' "$repo" push Example/KomaEngine v1.2.3
expect_refused 'a manual run in the public repository' "$repo" workflow_dispatch "$PUBLIC" v1.2.3
expect_refused 'a signing identity from another ref' "$repo" push "$PUBLIC" v1.2.3 \
    "$PUBLIC/.github/workflows/release.yml@refs/heads/main"
expect_refused 'a pushed commit other than the tagged one' "$repo" push "$PUBLIC" v1.2.3 '' \
    "$(printf '%040d' 7)"
expect_refused 'a tag that is not in the checkout' "$repo" push "$PUBLIC" v9.9.9

repo="$(fixture 1.2.3 v1.2.4)"
expect_refused 'a tag that does not match VERSION' "$repo" push "$PUBLIC" v1.2.4

repo="$(fixture 1.2 v1.2)"
expect_refused 'a tag that is not a full version' "$repo" push "$PUBLIC" v1.2

repo="$(fixture 1.2.3 v1.2.3)"
git -C "$repo" -c user.name=smoke -c user.email=smoke@example.invalid \
    commit --quiet --no-gpg-sign --allow-empty -m later
expect_refused 'a checkout that is not the tagged commit' "$repo" push "$PUBLIC" v1.2.3 '' \
    "$(git -C "$repo" rev-parse v1.2.3^{commit})"

# Static safety properties of the workflow file.
require() {
    grep -Eq -- "$1" "$WORKFLOW" || { echo "release.yml: $2" >&2; exit 1; }
    CHECKS=$((CHECKS + 1))
}
require "^  push:$" 'must run on push'
require "^      - 'v\\*'$" "must run only for v* tags"
require "^permissions:$" 'must declare default permissions'
require "^  contents: read$" 'default permissions must be read-only'
require "github.repository == '$PUBLIC'" 'must gate real releases on the public repository'
require "github.repository != '$PUBLIC'" 'must keep rehearsals out of the public repository'
require 'gh release create .*"\$\{flags\[@\]\}"' 'must create the release through the reviewed flags'
require 'flags=\(--draft ' 'must only ever open a draft release'
require 'composer audit --locked --no-dev' 'must audit the locked production dependencies'

audit_line="$(grep -n 'composer audit --locked --no-dev' "$WORKFLOW" | head -n 1 | cut -d: -f1)"
build_line="$(grep -n '^  build-native:$' "$WORKFLOW" | cut -d: -f1)"
[[ -n "$audit_line" && -n "$build_line" && "$audit_line" -lt "$build_line" ]] || {
    echo 'release.yml must audit dependencies in the identity job, before any image is built.' >&2
    exit 1
}
CHECKS=$((CHECKS + 1))

unpinned="$(grep -E '^\s*(- )?uses:' "$WORKFLOW" | grep -Ev '@[0-9a-f]{40}( |$)' || true)"
[[ -z "$unpinned" ]] || { echo "release.yml has actions not pinned to a commit: $unpinned" >&2; exit 1; }
CHECKS=$((CHECKS + 1))

secrets="$(grep -oE 'secrets\.[A-Za-z_]+' "$WORKFLOW" | sort -u | grep -vx 'secrets.GITHUB_TOKEN' || true)"
[[ -z "$secrets" ]] || { echo "release.yml uses secrets beyond GITHUB_TOKEN: $secrets" >&2; exit 1; }
CHECKS=$((CHECKS + 1))

default_public="$(sed -n "s/^PUBLIC_REPOSITORY=\"\${KOMA_PUBLIC_REPOSITORY:-\\(.*\\)}\"$/\\1/p" "$IDENTITY")"
[[ "$default_public" == "$PUBLIC" ]] || {
    echo 'release-identity.sh and release.yml disagree about the public repository.' >&2
    exit 1
}
CHECKS=$((CHECKS + 1))

if [[ "$LINT" == true ]]; then
    . "$ROOT/scripts/release/image-lock.env"
    docker run --rm --volume "$ROOT:/repo:ro" --workdir /repo "$KOMA_ACTIONLINT_IMAGE" \
        -no-color .github/workflows/release.yml
    CHECKS=$((CHECKS + 1))
fi

printf 'Release workflow regression smoke passed (%s checks).\n' "$CHECKS"
