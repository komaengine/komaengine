#!/usr/bin/env bash

# Decide and check the identity of one release workflow run before anything is
# built. Writes key=value lines to $GITHUB_OUTPUT (or stdout when unset).
#
# A real release runs only for a pushed tag in the public repository. A manual
# run anywhere else is a rehearsal: it builds and signs the same way but under
# test image names and as a draft prerelease. Every other combination stops.
#
# Inputs (environment): GITHUB_EVENT_NAME, GITHUB_REPOSITORY,
# GITHUB_REPOSITORY_OWNER, GITHUB_WORKFLOW_REF, GITHUB_SHA, KOMA_RELEASE_TAG,
# and optionally KOMA_PUBLIC_REPOSITORY (default komaengine/komaengine).
# It reads VERSION and Git from the current directory.

set -euo pipefail

PUBLIC_REPOSITORY="${KOMA_PUBLIC_REPOSITORY:-komaengine/komaengine}"
EVENT="${GITHUB_EVENT_NAME:?}"
REPOSITORY="${GITHUB_REPOSITORY:?}"
OWNER="${GITHUB_REPOSITORY_OWNER:?}"
WORKFLOW_REF="${GITHUB_WORKFLOW_REF:?}"
TAG="${KOMA_RELEASE_TAG:?}"

fail() {
    echo "Release identity refused: $*" >&2
    exit 1
}

if [[ "$EVENT" == push && "$REPOSITORY" == "$PUBLIC_REPOSITORY" ]]; then
    mode=release
elif [[ "$EVENT" == workflow_dispatch && "$REPOSITORY" != "$PUBLIC_REPOSITORY" ]]; then
    mode=rehearsal
else
    fail "a $EVENT run in $REPOSITORY is neither a public release nor a rehearsal."
fi

[[ "$TAG" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)(-[0-9A-Za-z.-]+)?$ ]] \
    || fail "tag $TAG is not v<major>.<minor>.<patch>[-<prerelease>]."
major="${BASH_REMATCH[1]}"
minor="${BASH_REMATCH[2]}"
version="${TAG#v}"
prerelease_suffix="${BASH_REMATCH[4]}"

[[ "$(tr -d '\r\n' < VERSION)" == "$version" ]] \
    || fail "VERSION does not equal the tag version $version."

tag_commit="$(git rev-parse --verify --quiet "refs/tags/${TAG}^{commit}")" \
    || fail "tag $TAG is not available in this checkout."
head_commit="$(git rev-parse HEAD)"
[[ "$head_commit" == "$tag_commit" ]] || fail "the checkout is not the commit tag $TAG points to."
if [[ "$mode" == release ]]; then
    [[ "${GITHUB_SHA:-}" == "$tag_commit" ]] || fail "the pushed commit is not the commit tag $TAG points to."
fi

identity="https://github.com/${WORKFLOW_REF}"
if [[ "$mode" == release ]]; then
    expected="https://github.com/${PUBLIC_REPOSITORY}/.github/workflows/release.yml@refs/tags/${TAG}"
    [[ "$identity" == "$expected" ]] || fail "the signing identity would be $identity, not $expected."
fi

owner_lower="$(printf '%s' "$OWNER" | tr '[:upper:]' '[:lower:]')"
if [[ "$mode" == release ]]; then
    image="ghcr.io/${PUBLIC_REPOSITORY}"
    if [[ -n "$prerelease_suffix" ]]; then
        prerelease=true
        image_tags="$version"
    else
        prerelease=false
        image_tags="$version ${major}.${minor} ${major} latest"
    fi
else
    image="ghcr.io/${owner_lower}/komaengine-release-rehearsal"
    prerelease=true
    image_tags="rehearsal-${version}"
fi

{
    echo "mode=$mode"
    echo "tag=$TAG"
    echo "version=$version"
    echo "commit=$tag_commit"
    echo "build_id=${version}+${tag_commit:0:12}"
    echo "image=$image"
    echo "image_tags=$image_tags"
    echo "prerelease=$prerelease"
    echo "identity=$identity"
} >> "${GITHUB_OUTPUT:-/dev/stdout}"

printf 'Release identity: %s %s at %s as %s (%s).\n' "$mode" "$TAG" "${tag_commit:0:12}" "$image" "$image_tags" >&2
