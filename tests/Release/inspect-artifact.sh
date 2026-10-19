#!/usr/bin/env bash

set -euo pipefail

ARCHIVE="${1:?Usage: inspect-artifact.sh /path/to/komaengine.zip}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-artifact-inspect.XXXXXX")"
trap 'rm -rf "$TEMP_ROOT"' EXIT

unzip -tq "$ARCHIVE" >/dev/null
unzip -qq "$ARCHIVE" -d "$TEMP_ROOT"
PACKAGE="$TEMP_ROOT/komaengine"

[[ -d "$PACKAGE" ]] || {
    echo "Artifact must contain one komaengine root directory." >&2
    exit 1
}

required=(
    VERSION
    LICENSE
    SECURITY.md
    artisan
    composer.json
    composer.lock
    vendor/autoload.php
    public/build/manifest.json
    public/.htaccess
    themes/aozora/theme.json
    themes/kasai/theme.json
    themes/sumi/theme.json
    .env.example
    .koma-release-build
    KOMA-BUILD-METADATA.txt
    KOMA-FILES.sha256
    docs/install/README.md
    docs/install/plesk.md
    docs/install/cpanel.md
    docs/install/ubuntu-vps.md
    docs/install/shared-hosting.md
    docs/install/troubleshooting.md
    docs/install/recovery.md
)

for path in "${required[@]}"; do
    [[ -f "$PACKAGE/$path" ]] || {
        echo "Artifact is missing required file: $path" >&2
        exit 1
    }
done

grep -Fxq 'MIT License' "$PACKAGE/LICENSE" || {
    echo 'Artifact LICENSE is not MIT.' >&2
    exit 1
}
php -r '
    $composer = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    exit(($composer["license"] ?? null) === "MIT" ? 0 : 1);
' "$PACKAGE/composer.json" || {
    echo 'Artifact composer metadata is not MIT.' >&2
    exit 1
}

[[ ! -e "$PACKAGE/.env" ]] || {
    echo "Artifact contains a deployable .env file." >&2
    exit 1
}
if find "$PACKAGE" -maxdepth 1 -name '.env*' ! -name '.env.example' -print -quit | grep -q .; then
    echo "Artifact contains an unexpected environment file." >&2
    exit 1
fi
grep -Eq '^APP_KEY=$' "$PACKAGE/.env.example" || {
    echo "Artifact environment example contains a populated or malformed APP_KEY." >&2
    exit 1
}

metadata="$PACKAGE/KOMA-BUILD-METADATA.txt"
metadata_value() {
    local key="$1"
    local count

    count="$(grep -Ec "^${key}=" "$metadata" || true)"
    [[ "$count" == 1 ]] || {
        echo "Artifact metadata must contain exactly one ${key} value." >&2
        exit 1
    }
    sed -n "s/^${key}=//p" "$metadata"
}

format="$(metadata_value format)"
artifact_type="$(metadata_value artifact)"
release_version="$(metadata_value release_version)"
source_commit="$(metadata_value source_commit)"
source_dirty="$(metadata_value source_dirty)"
source_policy="$(metadata_value source_policy)"
build_id="$(metadata_value build_id)"
composer_lock_sha="$(metadata_value composer_lock_sha256)"
package_lock_sha="$(metadata_value package_lock_sha256)"
version_file="$(tr -d '\r\n' < "$PACKAGE/VERSION")"

[[ "$format" == 'koma-build-v1' && "$artifact_type" == 'flatfile' ]] || {
    echo "Artifact metadata format or artifact type is invalid." >&2
    exit 1
}
[[ "$release_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ \
    && "$release_version" == "$version_file" ]] || {
    echo "Artifact release version does not match VERSION." >&2
    exit 1
}
[[ "$source_commit" =~ ^[0-9a-f]{40}$ \
    && "$source_dirty" == false \
    && "$source_policy" == exact-commit \
    && "$build_id" == "${release_version}+${source_commit:0:12}" ]] || {
    echo "Artifact source or build identity is inconsistent." >&2
    exit 1
}
[[ "$composer_lock_sha" == "$(shasum -a 256 "$PACKAGE/composer.lock" | awk '{print $1}')" \
    && "$package_lock_sha" == "$(shasum -a 256 "$PACKAGE/package-lock.json" | awk '{print $1}')" ]] || {
    echo "Artifact lock hashes do not match build metadata." >&2
    exit 1
}

if [[ -n "${KOMA_EXPECTED_SOURCE_COMMIT:-}" ]]; then
    [[ "$source_commit" == "$KOMA_EXPECTED_SOURCE_COMMIT" ]] || {
        echo "Artifact metadata does not match the expected source commit." >&2
        exit 1
    }

    if git -C "$ROOT" cat-file -e \
        "${KOMA_EXPECTED_SOURCE_COMMIT}:scripts/install-ubuntu-services.sh" 2>/dev/null; then
        [[ -x "$PACKAGE/scripts/install-ubuntu-services.sh" ]] || {
            echo 'Artifact is missing its executable Ubuntu service installer.' >&2
            exit 1
        }
    fi
fi

if [[ -e "$PACKAGE/scripts/install-ubuntu-services.sh" ]]; then
    [[ -f "$PACKAGE/scripts/install-ubuntu-services.sh" \
        && -x "$PACKAGE/scripts/install-ubuntu-services.sh" ]] || {
        echo 'Artifact Ubuntu service installer must be an executable regular file.' >&2
        exit 1
    }
    bash -n "$PACKAGE/scripts/install-ubuntu-services.sh"
fi

if [[ -n "${KOMA_EXPECTED_RELEASE_VERSION:-}" && "$release_version" != "$KOMA_EXPECTED_RELEASE_VERSION" ]]; then
    echo "Artifact metadata does not match the expected release version." >&2
    exit 1
fi

if find "$PACKAGE" -type l -print -quit | grep -q .; then
    echo "Artifact contains a symbolic link." >&2
    find "$PACKAGE" -type l -print >&2
    exit 1
fi

for forbidden in \
    database/database.sqlite \
    test-data \
    tests \
    .claude \
    research \
    mockup \
    dist \
    node_modules \
    .git \
    .DS_Store \
    public/storage \
    public/hot \
    vendor/phpunit \
    vendor/pestphp; do
    if [[ -e "$PACKAGE/$forbidden" ]]; then
        echo "Artifact contains forbidden path: $forbidden" >&2
        exit 1
    fi
done

# Community ships exactly these plugins; anything else is local or private.
shipped_plugins=' hello-koma '
for plugin in "$PACKAGE"/plugins/*/; do
    [[ -d "$plugin" ]] || continue
    plugin="$(basename "$plugin")"
    [[ "$shipped_plugins" == *" $plugin "* ]] || {
        echo "Artifact contains an unexpected plugin: $plugin" >&2
        exit 1
    }
done

if find "$PACKAGE" \( -name .DS_Store -o -name .git \) -print -quit | grep -q .; then
    echo "Artifact contains workstation or VCS metadata." >&2
    exit 1
fi

if find "$PACKAGE/storage/logs" "$PACKAGE/storage/framework/cache" \
    "$PACKAGE/storage/framework/sessions" "$PACKAGE/storage/framework/views" \
    -type f ! -name .gitignore -print -quit | grep -q .; then
    echo "Artifact contains runtime log/cache/session/view state." >&2
    exit 1
fi

(
    cd "$PACKAGE"
    shasum -a 256 -c KOMA-FILES.sha256 >/dev/null
    php -r "require 'vendor/autoload.php';"
    composer audit --locked --no-dev --no-interaction >/dev/null
)

printf 'Artifact inspection passed: %s\n' "$(basename "$ARCHIVE")"
