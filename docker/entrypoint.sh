#!/bin/sh

set -eu

cd /var/www/html

role="${1:-web}"

if ! php -r '
    $key = getenv("APP_KEY") ?: "";
    if (str_starts_with($key, "base64:")) {
        $decoded = base64_decode(substr($key, 7), true);
        exit($decoded !== false && strlen($decoded) === 32 ? 0 : 1);
    }
    exit(strlen($key) === 32 ? 0 : 1);
'; then
    echo "APP_KEY must be a stable Laravel-compatible 32-byte key." >&2
    exit 64
fi

case "${DB_PASSWORD:-}" in
    ''|secret|rootsecret|change-me|changeme)
        echo "DB_PASSWORD must be set to a non-placeholder secret." >&2
        exit 64
        ;;
esac

runtime_paths="
storage
storage/app
storage/app/private
storage/app/public
storage/app/media
storage/app/archive-import-temp
storage/framework
storage/framework/cache
storage/framework/sessions
storage/framework/views
storage/logs
bootstrap/cache
"

for path in $runtime_paths; do
    mkdir -p "$path"
    chown www-data:www-data "$path"
    chmod ug+rwX "$path"
done

if [ -n "${DB_HOST:-}" ]; then
    echo "Waiting for database ${DB_HOST}:${DB_PORT:-3306} ..."
    attempts=0

    until php -r '$h=getenv("DB_HOST");$p=(int)(getenv("DB_PORT") ?: 3306);$s=@fsockopen($h,$p,$e,$m,2);if($s){fclose($s);exit(0);}exit(1);'; do
        attempts=$((attempts + 1))

        if [ "$attempts" -ge 60 ]; then
            echo "Database remained unreachable after 120 seconds." >&2
            exit 70
        fi

        sleep 2
    done
fi

case "$role" in
    migrate)
        gosu www-data php artisan migrate --force --no-interaction
        exec gosu www-data php artisan koma:media:protect --no-interaction
        ;;
    doctor)
        shift
        exec gosu www-data php artisan koma:doctor "$@"
        ;;
    worker)
        worker_timeout="${QUEUE_WORKER_TIMEOUT:-3600}"
        retry_after="${DB_QUEUE_RETRY_AFTER:-3660}"

        case "$worker_timeout:$retry_after" in
            *[!0-9:]*|:*)
                echo "QUEUE_WORKER_TIMEOUT and DB_QUEUE_RETRY_AFTER must be positive integers." >&2
                exit 64
                ;;
        esac

        if [ "$worker_timeout" -le 0 ] || [ "$retry_after" -le 0 ]; then
            echo "QUEUE_WORKER_TIMEOUT and DB_QUEUE_RETRY_AFTER must be positive integers." >&2
            exit 64
        fi

        if [ "$worker_timeout" -ge "$retry_after" ]; then
            echo "QUEUE_WORKER_TIMEOUT must be lower than DB_QUEUE_RETRY_AFTER." >&2
            exit 64
        fi

        exec gosu www-data php artisan queue:work database \
            --queue=archive-imports,default \
            --tries=1 \
            --timeout="$worker_timeout" \
            --sleep=3 \
            --no-interaction
        ;;
    scheduler)
        exec gosu www-data php artisan schedule:work --no-interaction
        ;;
    web)
        if [ ! -L public/storage ] || [ "$(readlink -f public/storage)" != "$(readlink -f storage/app/public)" ]; then
            echo "public/storage is missing or points to the wrong target." >&2
            exit 78
        fi

        php-fpm -D

        : "${APACHE_CONFDIR:=/etc/apache2}"
        export APACHE_CONFDIR
        . /etc/apache2/envvars 2>/dev/null || true
        : "${APACHE_RUN_DIR:=/var/run/apache2}"
        : "${APACHE_LOCK_DIR:=/var/lock/apache2}"
        : "${APACHE_LOG_DIR:=/var/log/apache2}"
        : "${APACHE_RUN_USER:=www-data}"
        : "${APACHE_RUN_GROUP:=www-data}"
        : "${APACHE_PID_FILE:=${APACHE_RUN_DIR}/apache2.pid}"
        export APACHE_RUN_DIR APACHE_LOCK_DIR APACHE_LOG_DIR APACHE_RUN_USER APACHE_RUN_GROUP APACHE_PID_FILE
        mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"
        rm -f "$APACHE_PID_FILE"
        exec apache2 -D FOREGROUND
        ;;
    *)
        echo "Unknown KomaEngine container role: $role" >&2
        exit 64
        ;;
esac
