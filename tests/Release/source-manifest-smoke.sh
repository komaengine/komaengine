#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MANIFEST_SCRIPT="$ROOT/scripts/release/source-manifest.sh"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-manifest-smoke.XXXXXX")"
FIRST="$TEMP_ROOT/first.tsv"
SECOND="$TEMP_ROOT/second.tsv"
ERROR_LOG="$TEMP_ROOT/error.log"
SOURCE_COMMIT="${KOMA_TEST_SOURCE_COMMIT:-$(git -C "$ROOT" rev-parse HEAD)}"
trap 'rm -rf "$TEMP_ROOT"' EXIT

if [[ ! "$SOURCE_COMMIT" =~ ^[0-9a-f]{40}$ ]] \
    || [[ "$(git -C "$ROOT" rev-parse --verify "${SOURCE_COMMIT}^{commit}" 2>/dev/null)" != "$SOURCE_COMMIT" ]]; then
    echo "KOMA_TEST_SOURCE_COMMIT must be an available full lowercase 40-character Git commit." >&2
    exit 2
fi

KOMA_SOURCE_REPOSITORY="$ROOT" KOMA_SOURCE_COMMIT="$SOURCE_COMMIT" \
    "$MANIFEST_SCRIPT" > "$FIRST"
KOMA_SOURCE_REPOSITORY="$ROOT" KOMA_SOURCE_COMMIT="$SOURCE_COMMIT" \
    "$MANIFEST_SCRIPT" > "$SECOND"

cmp -s "$FIRST" "$SECOND" || {
    echo "Source manifests are not deterministic." >&2
    exit 1
}

grep -Fxq "# source_commit: $SOURCE_COMMIT" "$FIRST" || {
    echo "Source manifest omitted the exact Git commit." >&2
    exit 1
}

if (
    unset KOMA_SOURCE_REPOSITORY
    KOMA_SOURCE_COMMIT="$SOURCE_COMMIT" "$MANIFEST_SCRIPT" > /dev/null 2> "$ERROR_LOG"
); then
    echo "Source manifest accepted an implicit repository." >&2
    exit 1
fi
grep -Fq 'must name an absolute Git repository' "$ERROR_LOG"

if KOMA_SOURCE_REPOSITORY="$ROOT" KOMA_SOURCE_COMMIT=HEAD \
    "$MANIFEST_SCRIPT" > /dev/null 2> "$ERROR_LOG"; then
    echo "Source manifest accepted a symbolic commit." >&2
    exit 1
fi
grep -Fq 'must be a full lowercase 40-character Git commit' "$ERROR_LOG"

required_paths=(
    VERSION
    app/Jobs/ImportSeriesArchive.php
    app/Models/Comment.php
    database/migrations/2026_07_20_000800_create_comment_reports_table.php
    tests/Feature/CombinedWorkflowSmokeTest.php
    themes/kasai/theme.json
    themes/sumi/theme.json
)

for path in "${required_paths[@]}"; do
    grep -Fq $'\t'"$path"$'\t-' "$FIRST" || {
        echo "Manifest omitted a required source: $path" >&2
        exit 1
    }
done

for forbidden in \
    '.env' \
    '.DS_Store' \
    '.claude/launch.json' \
    'database/database.sqlite' \
    'public/storage'; do
    if awk -F '\t' -v path="$forbidden" '$5 == path { found = 1 } END { exit ! found }' "$FIRST"; then
        echo "Manifest included forbidden local/runtime content: $forbidden" >&2
        exit 1
    fi
done

while IFS=$'\t' read -r type mode size digest path target; do
    [[ "$type" == 'file' ]] || continue

    git -C "$ROOT" cat-file -e "${SOURCE_COMMIT}:${path}" 2>/dev/null || {
        echo "Manifest path is not present in the selected Git tree: $path" >&2
        exit 1
    }
done < "$FIRST"

for forbidden_prefix in \
    'test-data/' \
    'storage/framework/sessions/' \
    'storage/framework/views/' \
    'storage/logs/' \
    'research/' \
    'mockup/' \
    'dist/'; do
    if awk -F '\t' -v prefix="$forbidden_prefix" \
        'index($5, prefix) == 1 && $5 !~ /\/\.gitignore$/ { found = 1 } END { exit ! found }' \
        "$FIRST"; then
        echo "Manifest included forbidden local/runtime content: $forbidden_prefix" >&2
        exit 1
    fi
done

file_count="$(grep -c '^file' "$FIRST")"
if [[ "$file_count" -lt 150 ]]; then
    echo "Manifest is unexpectedly small: $file_count files" >&2
    exit 1
fi

printf 'Source manifest smoke passed: %s files.\n' "$file_count"
