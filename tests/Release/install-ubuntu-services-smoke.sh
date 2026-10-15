#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="$ROOT/scripts/install-ubuntu-services.sh"
TEMP_BASE="${TMPDIR:-/tmp}"
TEMP_BASE="${TEMP_BASE%/}"
TEMP_ROOT="$(mktemp -d "$TEMP_BASE/koma-service-install.XXXXXX")"
APP_ROOT="$TEMP_ROOT/app"
UNIT_DIR="$TEMP_ROOT/units"
OUTPUT="$TEMP_ROOT/output.log"
ERROR_LOG="$TEMP_ROOT/error.log"
SERVICE_USER="$(id -un)"
SERVICE_GROUP="$(id -gn "$SERVICE_USER")"
PHP_BIN="$(command -v php)"
SECRET_MARKER='must-not-enter-systemd-units'

cleanup() {
    rm -rf "$TEMP_ROOT"
}
trap cleanup EXIT

[[ -x "$INSTALLER" ]] || {
    echo 'Ubuntu service installer must be executable.' >&2
    exit 1
}

mkdir -p "$APP_ROOT/bootstrap/cache" "$APP_ROOT/storage"
touch "$APP_ROOT/artisan"
printf 'DB_PASSWORD=%s\n' "$SECRET_MARKER" > "$APP_ROOT/.env"
chmod 0600 "$APP_ROOT/.env"

"$INSTALLER" \
    --app-root="$APP_ROOT" \
    --service-user="$SERVICE_USER" \
    --php-bin="$PHP_BIN" \
    --unit-dir="$UNIT_DIR" \
    --no-enable \
    --dry-run > "$OUTPUT"

grep -Fq 'Dry run only; no files or services changed.' "$OUTPUT"
grep -Fq "WorkingDirectory=$APP_ROOT" "$OUTPUT"
grep -Fq 'queue:work database --queue=archive-imports,default' "$OUTPUT"
grep -Fq 'artisan schedule:run --no-interaction' "$OUTPUT"
grep -Fq 'OnCalendar=*-*-* *:*:00' "$OUTPUT"
if grep -Fq "$SECRET_MARKER" "$OUTPUT"; then
    echo 'Dry-run unit output leaked an environment secret.' >&2
    exit 1
fi
[[ ! -e "$UNIT_DIR" ]] || {
    echo 'Dry run created the unit directory.' >&2
    exit 1
}

"$INSTALLER" \
    --app-root="$APP_ROOT" \
    --service-user="$SERVICE_USER" \
    --php-bin="$PHP_BIN" \
    --name=koma-test \
    --unit-dir="$UNIT_DIR" \
    --no-enable > "$OUTPUT"

QUEUE_UNIT="$UNIT_DIR/koma-test-queue.service"
SCHEDULER_UNIT="$UNIT_DIR/koma-test-scheduler.service"
TIMER_UNIT="$UNIT_DIR/koma-test-scheduler.timer"

for unit in "$QUEUE_UNIT" "$SCHEDULER_UNIT" "$TIMER_UNIT"; do
    [[ -f "$unit" && ! -L "$unit" ]] || {
        echo "Expected regular unit file: $unit" >&2
        exit 1
    }
    if grep -Fq "$SECRET_MARKER" "$unit"; then
        echo "Generated unit leaked an environment secret: $unit" >&2
        exit 1
    fi
done

grep -Fq "User=$SERVICE_USER" "$QUEUE_UNIT"
grep -Fq "Group=$SERVICE_GROUP" "$QUEUE_UNIT"
grep -Fq "ExecStart=$PHP_BIN artisan queue:work database" "$QUEUE_UNIT"
grep -Fq "Unit=koma-test-scheduler.service" "$TIMER_UNIT"

before_hashes="$(shasum -a 256 "$QUEUE_UNIT" "$SCHEDULER_UNIT" "$TIMER_UNIT")"
"$INSTALLER" \
    --app-root="$APP_ROOT" \
    --service-user="$SERVICE_USER" \
    --php-bin="$PHP_BIN" \
    --name=koma-test \
    --unit-dir="$UNIT_DIR" \
    --no-enable > "$OUTPUT"
after_hashes="$(shasum -a 256 "$QUEUE_UNIT" "$SCHEDULER_UNIT" "$TIMER_UNIT")"
[[ "$before_hashes" == "$after_hashes" ]]
grep -Fq "Unchanged: $QUEUE_UNIT" "$OUTPUT"

printf '\n# operator customization\n' >> "$QUEUE_UNIT"
modified_hash="$(shasum -a 256 "$QUEUE_UNIT" | awk '{print $1}')"
if "$INSTALLER" \
    --app-root="$APP_ROOT" \
    --service-user="$SERVICE_USER" \
    --php-bin="$PHP_BIN" \
    --name=koma-test \
    --unit-dir="$UNIT_DIR" \
    --no-enable > "$OUTPUT" 2> "$ERROR_LOG"; then
    echo 'Installer overwrote a changed operator unit without --force.' >&2
    exit 1
fi
grep -Fq 'Unit differs from the generated definition' "$ERROR_LOG"
[[ "$(shasum -a 256 "$QUEUE_UNIT" | awk '{print $1}')" == "$modified_hash" ]]

"$INSTALLER" \
    --app-root="$APP_ROOT" \
    --service-user="$SERVICE_USER" \
    --php-bin="$PHP_BIN" \
    --name=koma-test \
    --unit-dir="$UNIT_DIR" \
    --no-enable \
    --force > "$OUTPUT"

if grep -Fq '# operator customization' "$QUEUE_UNIT"; then
    echo 'Forced installation did not restore the generated queue unit.' >&2
    exit 1
fi
find "$UNIT_DIR" -maxdepth 1 -type f \
    -name 'koma-test-queue.service.koma-backup-*' -print -quit | grep -q .

if "$INSTALLER" \
    --app-root=relative/path \
    --service-user="$SERVICE_USER" \
    --php-bin="$PHP_BIN" \
    --dry-run > "$OUTPUT" 2> "$ERROR_LOG"; then
    echo 'Installer accepted a relative application root.' >&2
    exit 1
fi
grep -Fq -- '--app-root must be an absolute path' "$ERROR_LOG"

if "$INSTALLER" \
    --app-root="$APP_ROOT" \
    --service-user="$SERVICE_USER" \
    --php-bin="$PHP_BIN" \
    --name='unsafe name' \
    --dry-run > "$OUTPUT" 2> "$ERROR_LOG"; then
    echo 'Installer accepted an unsafe systemd unit prefix.' >&2
    exit 1
fi
grep -Fq -- '--name is not a safe systemd unit prefix' "$ERROR_LOG"

printf 'Ubuntu service installer dry-run, staging, idempotency, conflict, and guard smoke passed.\n'
