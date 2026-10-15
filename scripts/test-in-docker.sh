#!/usr/bin/env bash

# Run the PHP test suite in a disposable container built from the pinned PHP
# image, so no PHP or Composer is needed on the host. The working tree is
# copied in (tracked files plus untracked files Git does not ignore); nothing
# is mounted, and your .env, vendor/, and storage stay out.
#
#   scripts/test-in-docker.sh                 PHP 8.4, full suite
#   scripts/test-in-docker.sh 8.3             another supported PHP version
#   scripts/test-in-docker.sh 8.4 --filter=Installer
#   KOMA_TEST_COMMAND='vendor/bin/pint --test' scripts/test-in-docker.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION='8.4'
if [[ "${1:-}" =~ ^8\.[345]$ ]]; then
    VERSION="$1"
    shift
fi

. "$ROOT/scripts/release/image-lock.env"

case "$VERSION" in
    8.3) PHP_IMAGE="$KOMA_PHP_83_CLI_IMAGE" ;;
    8.4) PHP_IMAGE="$KOMA_PHP_84_CLI_IMAGE" ;;
    8.5) PHP_IMAGE="$KOMA_PHP_85_CLI_IMAGE" ;;
esac

TAG="komaengine-dev-php${VERSION/./}:local"

docker build --quiet --tag "$TAG" --file - "$ROOT/tests/Release" >/dev/null <<DOCKERFILE
FROM $KOMA_COMPOSER_IMAGE AS composer
FROM $PHP_IMAGE
RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libzip-dev libicu-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"\$(nproc)" pdo_mysql pdo_sqlite mbstring gd zip intl bcmath exif pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer
DOCKERFILE

cd "$ROOT"
git ls-files -z --cached --others --exclude-standard \
    | COPYFILE_DISABLE=1 tar --no-xattrs --null --files-from - --create --file - \
    | docker run --rm -i --env KOMA_TEST_COMMAND="${KOMA_TEST_COMMAND:-}" "$TAG" bash -c '
        set -euo pipefail
        mkdir /app && tar --extract --file - --directory /app && cd /app
        composer install --no-interaction --no-progress --prefer-dist --quiet
        install -m 0600 .env.example .env
        php artisan key:generate --force --quiet
        if [[ -n "$KOMA_TEST_COMMAND" ]]; then
            exec bash -c "$KOMA_TEST_COMMAND"
        fi
        exec php -d memory_limit=512M artisan test "$@"
    ' koma-test "$@"
