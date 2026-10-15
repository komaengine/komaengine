#!/usr/bin/env bash

set -euo pipefail

APP_ROOT=''
SERVICE_USER='www-data'
SERVICE_GROUP=''
PHP_BIN='php'
UNIT_NAME='komaengine'
UNIT_DIR='/etc/systemd/system'
DRY_RUN=false
ENABLE_UNITS=true
FORCE=false

usage() {
    cat <<'EOF'
Install KomaEngine's queue worker and scheduler as systemd units.

Usage:
  install-ubuntu-services.sh --app-root=/srv/komaengine/current [options]

Options:
  --app-root=PATH       Absolute deployed KomaEngine path containing artisan.
  --service-user=USER   PHP-FPM/service account (default: www-data).
  --service-group=GROUP Service group (default: USER's primary group).
  --php-bin=PATH        PHP CLI binary (default: php from PATH).
  --name=NAME           Safe systemd unit prefix (default: komaengine).
  --dry-run             Validate inputs and print units without changing files.
  --force               Back up and replace changed KomaEngine unit files.
  --unit-dir=PATH       Unit output directory (default: /etc/systemd/system).
  --no-enable           Write units only; do not call systemctl.
  --help                Show this help.

The normal installation must run as root. A custom --unit-dir requires
--no-enable and is intended for review, packaging, or automated tests.
EOF
}

fail() {
    printf 'KomaEngine service install failed: %s\n' "$*" >&2
    exit 1
}

while (($# > 0)); do
    case "$1" in
        --app-root=*)
            APP_ROOT="${1#*=}"
            ;;
        --app-root)
            shift
            (($# > 0)) || fail '--app-root requires a value.'
            APP_ROOT="$1"
            ;;
        --service-user=*)
            SERVICE_USER="${1#*=}"
            ;;
        --service-user)
            shift
            (($# > 0)) || fail '--service-user requires a value.'
            SERVICE_USER="$1"
            ;;
        --service-group=*)
            SERVICE_GROUP="${1#*=}"
            ;;
        --service-group)
            shift
            (($# > 0)) || fail '--service-group requires a value.'
            SERVICE_GROUP="$1"
            ;;
        --php-bin=*)
            PHP_BIN="${1#*=}"
            ;;
        --php-bin)
            shift
            (($# > 0)) || fail '--php-bin requires a value.'
            PHP_BIN="$1"
            ;;
        --name=*)
            UNIT_NAME="${1#*=}"
            ;;
        --name)
            shift
            (($# > 0)) || fail '--name requires a value.'
            UNIT_NAME="$1"
            ;;
        --unit-dir=*)
            UNIT_DIR="${1#*=}"
            ;;
        --unit-dir)
            shift
            (($# > 0)) || fail '--unit-dir requires a value.'
            UNIT_DIR="$1"
            ;;
        --dry-run)
            DRY_RUN=true
            ;;
        --no-enable)
            ENABLE_UNITS=false
            ;;
        --force)
            FORCE=true
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            fail "Unknown option: $1"
            ;;
    esac

    shift
done

[[ -n "$APP_ROOT" ]] || fail '--app-root is required.'
[[ "$APP_ROOT" == /* && "$APP_ROOT" =~ ^/[A-Za-z0-9._/-]+$ ]] \
    || fail '--app-root must be an absolute path without whitespace or systemd metacharacters.'
[[ "$APP_ROOT" == '/' ]] || APP_ROOT="${APP_ROOT%/}"
case "$APP_ROOT" in
    *//*|*/../*|*/./*|*/..|*/.)
        fail '--app-root must not contain dot or empty path segments.'
        ;;
esac

[[ "$UNIT_DIR" == /* && "$UNIT_DIR" =~ ^/[A-Za-z0-9._/-]+$ ]] \
    || fail '--unit-dir must be an absolute path without whitespace or systemd metacharacters.'
[[ "$UNIT_DIR" == '/' ]] || UNIT_DIR="${UNIT_DIR%/}"
[[ "$UNIT_DIR" != '/' ]] || fail '--unit-dir must not be the filesystem root.'
case "$UNIT_DIR" in
    *//*|*/../*|*/./*|*/..|*/.)
        fail '--unit-dir must not contain dot or empty path segments.'
        ;;
esac

[[ "$SERVICE_USER" =~ ^[A-Za-z_][A-Za-z0-9_-]*[$]?$ ]] \
    || fail '--service-user is not a safe local account name.'
[[ "$UNIT_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9_.@-]*$ ]] \
    || fail '--name is not a safe systemd unit prefix.'

if [[ "$PHP_BIN" != /* ]]; then
    PHP_BIN="$(command -v "$PHP_BIN" 2>/dev/null || true)"
fi
[[ -n "$PHP_BIN" && "$PHP_BIN" == /* && "$PHP_BIN" =~ ^/[A-Za-z0-9._/-]+$ && -x "$PHP_BIN" ]] \
    || fail '--php-bin must resolve to an executable absolute path without whitespace.'

id "$SERVICE_USER" >/dev/null 2>&1 \
    || fail "Service account does not exist: $SERVICE_USER"
if [[ -z "$SERVICE_GROUP" ]]; then
    SERVICE_GROUP="$(id -gn "$SERVICE_USER")"
fi
[[ "$SERVICE_GROUP" =~ ^[A-Za-z_][A-Za-z0-9_-]*[$]?$ ]] \
    || fail '--service-group is not a safe local group name.'
[[ -d "$APP_ROOT" && -f "$APP_ROOT/artisan" ]] \
    || fail "Application root does not contain artisan: $APP_ROOT"
[[ -f "$APP_ROOT/.env" ]] \
    || fail "Application environment file is missing: $APP_ROOT/.env"
[[ -d "$APP_ROOT/storage" && -d "$APP_ROOT/bootstrap/cache" ]] \
    || fail 'Application runtime directories are missing.'

if [[ "$UNIT_DIR" != '/etc/systemd/system' && "$ENABLE_UNITS" == true ]]; then
    fail 'A custom --unit-dir requires --no-enable.'
fi

if [[ "$DRY_RUN" == false && "$ENABLE_UNITS" == true && "$EUID" -ne 0 ]]; then
    fail 'Run the normal installation as root (for example, with sudo).'
fi

QUEUE_UNIT="$UNIT_NAME-queue.service"
SCHEDULER_UNIT="$UNIT_NAME-scheduler.service"
TIMER_UNIT="$UNIT_NAME-scheduler.timer"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-systemd.XXXXXX")"
TARGET_TMP=''
cleanup() {
    rm -rf "$TEMP_ROOT"
    if [[ -n "$TARGET_TMP" ]]; then
        rm -f "$TARGET_TMP"
    fi
}
trap cleanup EXIT

cat > "$TEMP_ROOT/$QUEUE_UNIT" <<EOF
[Unit]
Description=KomaEngine queue worker ($UNIT_NAME)
Wants=network-online.target
After=network-online.target mariadb.service

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_GROUP
WorkingDirectory=$APP_ROOT
ExecStart=$PHP_BIN artisan queue:work database --queue=archive-imports,default --tries=1 --timeout=3600 --sleep=3 --no-interaction
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=3660
UMask=0027

[Install]
WantedBy=multi-user.target
EOF

cat > "$TEMP_ROOT/$SCHEDULER_UNIT" <<EOF
[Unit]
Description=Run the KomaEngine scheduler ($UNIT_NAME)
Wants=network-online.target
After=network-online.target mariadb.service

[Service]
Type=oneshot
User=$SERVICE_USER
Group=$SERVICE_GROUP
WorkingDirectory=$APP_ROOT
ExecStart=$PHP_BIN artisan schedule:run --no-interaction
TimeoutStartSec=3600
UMask=0027
EOF

cat > "$TEMP_ROOT/$TIMER_UNIT" <<EOF
[Unit]
Description=Run the KomaEngine scheduler every minute ($UNIT_NAME)

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
AccuracySec=1s
Unit=$SCHEDULER_UNIT

[Install]
WantedBy=timers.target
EOF

UNITS=("$QUEUE_UNIT" "$SCHEDULER_UNIT" "$TIMER_UNIT")

if "$DRY_RUN"; then
    for unit in "${UNITS[@]}"; do
        printf '\n### %s/%s\n' "$UNIT_DIR" "$unit"
        cat "$TEMP_ROOT/$unit"
    done

    printf '\nDry run only; no files or services changed.\n'
    exit 0
fi

if command -v systemd-analyze >/dev/null 2>&1; then
    systemd-analyze verify \
        "$TEMP_ROOT/$QUEUE_UNIT" \
        "$TEMP_ROOT/$SCHEDULER_UNIT" \
        "$TEMP_ROOT/$TIMER_UNIT"
elif "$ENABLE_UNITS"; then
    fail 'systemd-analyze is required for an enabled installation.'
else
    printf 'Warning: systemd-analyze is unavailable; staged units were not syntax-verified.\n' >&2
fi

if [[ -L "$UNIT_DIR" || ( -e "$UNIT_DIR" && ! -d "$UNIT_DIR" ) ]]; then
    fail "Refusing unsafe unit directory: $UNIT_DIR"
fi

for unit in "${UNITS[@]}"; do
    target="$UNIT_DIR/$unit"

    if [[ -L "$target" || ( -e "$target" && ! -f "$target" ) ]]; then
        fail "Refusing unsafe unit target: $target"
    fi

    if [[ -f "$target" && "$FORCE" == false ]] \
        && ! cmp -s "$TEMP_ROOT/$unit" "$target"; then
        fail "Unit differs from the generated definition: $target (review it, then rerun with --force to back up and replace it)"
    fi
done

if "$ENABLE_UNITS"; then
    for command_name in systemctl systemd-analyze runuser install; do
        command -v "$command_name" >/dev/null 2>&1 \
            || fail "Required command is missing: $command_name"
    done

    printf 'Running KomaEngine operational checks as %s...\n' "$SERVICE_USER"
    (
        cd "$APP_ROOT"
        runuser -u "$SERVICE_USER" -- "$PHP_BIN" artisan koma:doctor --mode=operational
    )
fi

if [[ ! -d "$UNIT_DIR" ]]; then
    install -d -m 0755 "$UNIT_DIR"
fi
CHANGED=false
BACKUP_STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"

for unit in "${UNITS[@]}"; do
    source_path="$TEMP_ROOT/$unit"
    target="$UNIT_DIR/$unit"

    if [[ -f "$target" ]] && cmp -s "$source_path" "$target"; then
        printf 'Unchanged: %s\n' "$target"
        continue
    fi

    if [[ -f "$target" && "$FORCE" == true ]]; then
        backup="$target.koma-backup-$BACKUP_STAMP"
        cp -p "$target" "$backup"
        printf 'Backed up: %s\n' "$backup"
    fi

    TARGET_TMP="$(mktemp "$UNIT_DIR/.$unit.XXXXXX")"
    install -m 0644 "$source_path" "$TARGET_TMP"
    if [[ "$UNIT_DIR" == '/etc/systemd/system' ]]; then
        chown root:root "$TARGET_TMP"
    fi
    mv -f "$TARGET_TMP" "$target"
    TARGET_TMP=''
    printf 'Installed: %s\n' "$target"
    CHANGED=true
done

activate_units() {
    systemctl daemon-reload || return
    systemctl enable "$QUEUE_UNIT" "$TIMER_UNIT" || return

    if "$CHANGED" || ! systemctl is-active --quiet "$QUEUE_UNIT"; then
        systemctl restart "$QUEUE_UNIT" || return
    fi
    if "$CHANGED" || ! systemctl is-active --quiet "$TIMER_UNIT"; then
        systemctl restart "$TIMER_UNIT" || return
    fi

    systemctl start "$SCHEDULER_UNIT" || return
    systemctl is-enabled --quiet "$QUEUE_UNIT" || return
    systemctl is-enabled --quiet "$TIMER_UNIT" || return
    systemctl is-active --quiet "$QUEUE_UNIT" || return
    systemctl is-active --quiet "$TIMER_UNIT" || return
}

if "$ENABLE_UNITS"; then
    if ! activate_units; then
        fail "Unit files were installed, but activation failed. Inspect systemctl status and journalctl for $QUEUE_UNIT, $SCHEDULER_UNIT, and $TIMER_UNIT."
    fi

    printf 'KomaEngine queue and scheduler services are installed and active.\n'
    printf 'Inspect them with:\n'
    printf '  systemctl status %s %s\n' "$QUEUE_UNIT" "$TIMER_UNIT"
    printf '  journalctl -u %s -u %s -n 50 --no-pager\n' "$QUEUE_UNIT" "$SCHEDULER_UNIT"
else
    printf 'Units written to %s; systemctl was not called.\n' "$UNIT_DIR"
fi
