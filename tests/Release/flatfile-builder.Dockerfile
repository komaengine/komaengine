FROM node:22.23.4-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d AS node

FROM php:8.4.27-cli-bookworm@sha256:9c558ebd34ddb282c292ebe905da14fbab8b692e3cf8497e1eab8e812fe079cf

COPY --from=node /usr/local/ /usr/local/
COPY --from=composer:2.9.8@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d /usr/bin/composer /usr/local/bin/composer

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
