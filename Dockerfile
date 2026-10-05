# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# Tally: one Dockerfile, three targets.
#
#   base        PHP + extensions, shared by everything below.
#   dev         Dev dependencies and PCOV. Used by compose and the CI test job.
#   production  No dev dependencies, optimised autoloader, non-root, healthcheck.
#
# Build the production image:   docker build --target production -t tally .
# ---------------------------------------------------------------------------

ARG PHP_VERSION=8.4

FROM composer:2 AS composer

# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine AS base

WORKDIR /app

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php/app.ini $PHP_INI_DIR/conf.d/zz-app.ini

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:8080

EXPOSE 8080

# ---------------------------------------------------------------------------
FROM base AS dev

RUN install-php-extensions pcov

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-scripts \
    && php artisan package:discover --ansi

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]

# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

# ---------------------------------------------------------------------------
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi \
    && rm /usr/bin/composer \
    && rm -rf tests

# Run as an unprivileged user on an unprivileged port. FrankenPHP needs its
# Caddy dirs writable; Laravel needs storage and bootstrap/cache.
RUN adduser -D -u 10001 tally \
    && mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chown -R tally:tally /data/caddy /config/caddy storage bootstrap/cache

COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint

USER tally

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD wget -q --spider http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8080"]
