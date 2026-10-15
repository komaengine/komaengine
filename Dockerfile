# No `# syntax=` directive on purpose. That directive makes BuildKit fetch the
# `docker/dockerfile` frontend image from Docker Hub on every build, which
# breaks rebuilds on an offline or air-gapped host. Nothing below needs a
# frontend newer than the one built into BuildKit, so we use the built-in.

# ---- Stage 1: compile front-end assets with Vite/Tailwind ----
FROM node:22.23.2-bookworm-slim@sha256:48e4b67d85f87bd551df43704e24d252f56cc5f8e9718841aace50f19948f0f9 AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources resources
RUN npm run build

# ---- Stage 2: runtime (Apache + PHP-FPM 8.4) ----
FROM php:8.4.26-fpm-bookworm@sha256:6bfef8e416977aa41f48e3e42a40c1e08050d24e4a938c6edb421400bff24601 AS runtime

ARG KOMA_RELEASE_VERSION=1.0.0
ARG KOMA_RELEASE_COMMIT=unavailable
ARG KOMA_RELEASE_DIRTY=unknown
ARG KOMA_RELEASE_BUILD_ID=development
ARG KOMA_TRUSTED_BUILD=false

LABEL org.opencontainers.image.title="KomaEngine" \
      org.opencontainers.image.version="${KOMA_RELEASE_VERSION}" \
      org.opencontainers.image.revision="${KOMA_RELEASE_COMMIT}" \
      org.opencontainers.image.licenses="MIT" \
      io.komaengine.build-id="${KOMA_RELEASE_BUILD_ID}" \
      io.komaengine.source-dirty="${KOMA_RELEASE_DIRTY}"

ENV KOMA_RELEASE_VERSION="${KOMA_RELEASE_VERSION}" \
    KOMA_RELEASE_COMMIT="${KOMA_RELEASE_COMMIT}" \
    KOMA_RELEASE_BUILD_ID="${KOMA_RELEASE_BUILD_ID}" \
    KOMA_RELEASE_DIRTY="${KOMA_RELEASE_DIRTY}" \
    KOMA_TRUSTED_BUILD="${KOMA_TRUSTED_BUILD}"

WORKDIR /var/www/html
COPY VERSION ./VERSION
RUN set -eu; \
    release_version="$(tr -d '\r\n' < VERSION)"; \
    printf '%s\n' "$release_version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'; \
    [ "$KOMA_RELEASE_VERSION" = "$release_version" ]; \
    case "$KOMA_TRUSTED_BUILD" in true|false) ;; *) exit 1 ;; esac; \
    case "$KOMA_RELEASE_DIRTY" in true|false|unknown) ;; *) exit 1 ;; esac; \
    if [ "$KOMA_TRUSTED_BUILD" = true ]; then \
        printf '%s\n' "$KOMA_RELEASE_COMMIT" | grep -Eq '^[0-9a-f]{40}$'; \
        [ "$KOMA_RELEASE_DIRTY" = false ]; \
        [ "$KOMA_RELEASE_BUILD_ID" = "${release_version}+$(printf '%.12s' "$KOMA_RELEASE_COMMIT")" ]; \
    fi

RUN apt-get update && apt-get install -y --no-install-recommends \
        apache2 gosu unzip git mariadb-client \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring gd zip intl bcmath exif opcache pcntl \
    && (a2dismod mpm_prefork 2>/dev/null || true) \
    && a2enmod mpm_event proxy proxy_fcgi rewrite headers expires \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.9.7@sha256:02062f7719ec9433a9d4256cfba1c792db96dc9db60a4a92e48264c9e166b877 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# PHP / FPM / Apache / entrypoint configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-koma.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-koma.conf
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/koma-entrypoint
RUN chmod +x /usr/local/bin/koma-entrypoint

# Install PHP dependencies first for better layer caching.
COPY composer.json composer.lock package-lock.json ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Application source is an allowlist. Never replace this with COPY . .;
# ignored local databases and test manga must not enter a release image.
COPY LICENSE SECURITY.md ./
COPY artisan ./artisan
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY plugins ./plugins
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY themes ./themes
COPY --from=assets /app/public/build ./public/build
RUN set -eu; \
    release_version="$(tr -d '\r\n' < VERSION)"; \
    if [ "$KOMA_TRUSTED_BUILD" = true ]; then source_policy=exact-commit; else source_policy=working-tree; fi; \
    { \
        printf 'format=koma-build-v1\n'; \
        printf 'artifact=docker\n'; \
        printf 'release_version=%s\n' "$release_version"; \
        printf 'source_commit=%s\n' "$KOMA_RELEASE_COMMIT"; \
        printf 'source_dirty=%s\n' "$KOMA_RELEASE_DIRTY"; \
        printf 'source_policy=%s\n' "$source_policy"; \
        printf 'build_id=%s\n' "$KOMA_RELEASE_BUILD_ID"; \
        printf 'composer_lock_sha256=%s\n' "$(sha256sum composer.lock | cut -d ' ' -f 1)"; \
        printf 'package_lock_sha256=%s\n' "$(sha256sum package-lock.json | cut -d ' ' -f 1)"; \
    } > KOMA-BUILD-METADATA.txt; \
    composer dump-autoload --optimize --no-dev --no-scripts \
    && ln -snf /var/www/html/storage/app/public /var/www/html/public/storage \
    && mkdir -p storage/app/media storage/app/private storage/app/public storage/app/archive-import-temp \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && rm -f /usr/bin/composer \
    && apt-get purge -y --auto-remove git unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/koma-entrypoint"]
CMD ["web"]
