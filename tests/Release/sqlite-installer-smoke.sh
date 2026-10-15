#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-sqlite-install.XXXXXX")"
trap 'rm -rf "$TEMP_ROOT"' EXIT

DATABASE="$TEMP_ROOT/koma.sqlite"
touch "$DATABASE"
APP_KEY_VALUE="base64:$(openssl rand -base64 32)"

run_php() {
    APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY="$APP_KEY_VALUE" \
    APP_URL=http://koma.test \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$DATABASE" \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    INSTALLER_ENVIRONMENT_MODE=process \
    INSTALLER_WRITE_ENV=false \
    INSTALLER_WRITE_ROBOTS=false \
    MEDIA_DISK_DRIVER=local \
    php "$1"
}

cd "$ROOT"
run_php "$ROOT/tests/Release/support/install-sqlite.php"
run_php "$ROOT/tests/Release/support/verify-sqlite.php"

printf 'SQLite installer process/restart smoke passed.\n'

