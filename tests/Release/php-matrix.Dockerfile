ARG PHP_IMAGE=php:8.4.26-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b
FROM ${PHP_IMAGE}

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libzip-dev libicu-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_sqlite mbstring gd zip intl bcmath exif pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/zz-koma-test.ini

WORKDIR /app
