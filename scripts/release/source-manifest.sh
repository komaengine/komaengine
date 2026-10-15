#!/usr/bin/env bash

set -euo pipefail

REPOSITORY_INPUT="${KOMA_SOURCE_REPOSITORY:-}"
SOURCE_COMMIT="${KOMA_SOURCE_COMMIT:-}"
OUTPUT_PATH="${1:-}"

for tool in git tar; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "Required manifest tool is missing: $tool" >&2
        exit 2
    }
done

if [[ -z "$REPOSITORY_INPUT" || "$REPOSITORY_INPUT" != /* ]]; then
    echo "KOMA_SOURCE_REPOSITORY must name an absolute Git repository." >&2
    exit 2
fi

if [[ ! "$SOURCE_COMMIT" =~ ^[0-9a-f]{40}$ ]]; then
    echo "KOMA_SOURCE_COMMIT must be a full lowercase 40-character Git commit." >&2
    exit 2
fi

REPOSITORY="$(cd "$REPOSITORY_INPUT" 2>/dev/null && pwd -P)" || {
    echo "KOMA_SOURCE_REPOSITORY is not a readable directory: $REPOSITORY_INPUT" >&2
    exit 2
}

RESOLVED_COMMIT="$(git -C "$REPOSITORY" rev-parse --verify "${SOURCE_COMMIT}^{commit}" 2>/dev/null)" || {
    echo "KOMA_SOURCE_COMMIT does not resolve in KOMA_SOURCE_REPOSITORY." >&2
    exit 2
}

if [[ "$RESOLVED_COMMIT" != "$SOURCE_COMMIT" ]]; then
    echo "KOMA_SOURCE_COMMIT must identify the exact commit." >&2
    exit 2
fi

TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-source-manifest.XXXXXX")"
SOURCE_ROOT="$TEMP_ROOT/source"
MANIFEST_TMP="$TEMP_ROOT/manifest.tsv"
trap 'rm -rf "$TEMP_ROOT"' EXIT

mkdir -p "$SOURCE_ROOT"
git -C "$REPOSITORY" archive --format=tar "$SOURCE_COMMIT" | tar -xf - -C "$SOURCE_ROOT"

if [[ ! -f "$SOURCE_ROOT/artisan" || ! -f "$SOURCE_ROOT/composer.json" ]]; then
    echo "Selected Git commit is not a KomaEngine source tree: $SOURCE_COMMIT" >&2
    exit 2
fi

if command -v sha256sum >/dev/null 2>&1; then
    hash_file() { sha256sum "$1" | awk '{print $1}'; }
elif command -v shasum >/dev/null 2>&1; then
    hash_file() { shasum -a 256 "$1" | awk '{print $1}'; }
else
    echo "sha256sum or shasum is required." >&2
    exit 2
fi

if stat -f '%Lp' "$SOURCE_ROOT/artisan" >/dev/null 2>&1; then
    file_mode() { stat -f '%Lp' "$1"; }
    file_size() { stat -f '%z' "$1"; }
else
    file_mode() { stat -c '%a' "$1"; }
    file_size() { stat -c '%s' "$1"; }
fi

root_files=(
    .dockerignore
    .editorconfig
    .env.docker.example
    .env.example
    .env.flatfile.example
    .gitattributes
    .gitignore
    Dockerfile
    LICENSE
    README.md
    SECURITY.md
    VERSION
    artisan
    composer.json
    composer.lock
    docker-compose.yml
    package-lock.json
    package.json
    phpunit.xml
    vite.config.js
)

# Maintainer planning files exist only in the private repository.
optional_root_files=(
    baseplan.md
    progress.md
    roadmap.md
)

source_directories=(
    app
    bootstrap
    config
    database
    docker
    docs
    plugins
    public
    resources
    routes
    scripts
    storage
    tests
    themes
)

declare -a paths=()

for relative in "${root_files[@]}"; do
    if [[ ! -f "$SOURCE_ROOT/$relative" ]]; then
        echo "Required release input is missing: $relative" >&2
        exit 3
    fi

    paths+=("$relative")
done

for relative in "${optional_root_files[@]}"; do
    if [[ -f "$SOURCE_ROOT/$relative" ]]; then
        paths+=("$relative")
    fi
done

for directory in "${source_directories[@]}"; do
    if [[ ! -d "$SOURCE_ROOT/$directory" ]]; then
        echo "Required source directory is missing: $directory" >&2
        exit 3
    fi

    while IFS= read -r relative; do
        case "$relative" in
            */.DS_Store|.DS_Store|database/*.sqlite|database/*.sqlite-*|database/*.sqlite3|public/build/*|public/hot|public/storage|public/storage/*|storage/*)
                continue
                ;;
        esac

        paths+=("$relative")
    done < <(
        cd "$SOURCE_ROOT"
        find "$directory" \( -type f -o -type l \) -print | LC_ALL=C sort
    )
done

# Runtime directory placeholders are the only storage contents that belong in
# a source/release baseline. Sessions, logs, media, imports, and compiled views
# are deliberately excluded above.
while IFS= read -r relative; do
    paths+=("$relative")
done < <(
    cd "$SOURCE_ROOT"
    find storage -type f -name .gitignore -print | LC_ALL=C sort
)

{
    printf '# KomaEngine source manifest v1\n'
    printf '# source_commit: %s\n' "$SOURCE_COMMIT"
    printf '# columns: type mode size sha256 path target\n'

    printf '%s\n' "${paths[@]}" | LC_ALL=C sort -u | while IFS= read -r relative; do
        absolute="$SOURCE_ROOT/$relative"

        if [[ -L "$absolute" ]]; then
            target="$(readlink "$absolute")"

            # Release inputs do not need symlinks. Refusing every one prevents
            # workstation-absolute and traversal links from entering later
            # staging by accident.
            echo "Unsafe symlink in release inputs: $relative -> $target" >&2
            exit 4
        fi

        if [[ ! -f "$absolute" ]]; then
            echo "Release input disappeared while hashing: $relative" >&2
            exit 4
        fi

        printf 'file\t%s\t%s\t%s\t%s\t-\n' \
            "$(file_mode "$absolute")" \
            "$(file_size "$absolute")" \
            "$(hash_file "$absolute")" \
            "$relative"
    done
} > "$MANIFEST_TMP"

if [[ -n "$OUTPUT_PATH" ]]; then
    output_absolute="$OUTPUT_PATH"
    if [[ "$output_absolute" != /* ]]; then
        output_absolute="$(pwd -P)/$output_absolute"
    fi

    mkdir -p "$(dirname "$output_absolute")"
    output_tmp="$(mktemp "$(dirname "$output_absolute")/.koma-manifest.XXXXXX")"
    cp "$MANIFEST_TMP" "$output_tmp"
    chmod 0644 "$output_tmp"
    mv -f "$output_tmp" "$output_absolute"
else
    cat "$MANIFEST_TMP"
fi
