FROM node:22.23.2-bookworm-slim@sha256:48e4b67d85f87bd551df43704e24d252f56cc5f8e9718841aace50f19948f0f9 AS node

FROM php:8.4.26-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b

COPY --from=node /usr/local/ /usr/local/
COPY --from=composer:2.9.7@sha256:02062f7719ec9433a9d4256cfba1c792db96dc9db60a4a92e48264c9e166b877 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        git openssl tar unzip zip libdigest-sha-perl \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libzip-dev libicu-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_sqlite mbstring gd zip intl bcmath exif pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/zz-koma-release-test.ini

WORKDIR /workspace
