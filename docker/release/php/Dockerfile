# Release image: self-contained application (no bind mounts). Used by deploy/docker/compose.yml,
# the offline bundle and the image registry. Keep the extension list identical to docker/php/Dockerfile.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev default-mysql-client libfcgi-bin openssl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear \
    && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

ENV PHP_FPM_MAX_CHILDREN=32 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    APP_ENV=production

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf/zz-tokenhub-fpm.conf /usr/local/etc/php-fpm.d/zz-tokenhub.conf
COPY docker/php/conf/tokenhub.ini /usr/local/etc/php/conf.d/zz-tokenhub.ini
COPY docker/release/php/entrypoint.sh /usr/local/bin/tokenhub-release-entrypoint
COPY docker/php/healthcheck.sh /usr/local/bin/tokenhub-healthcheck
RUN chmod +x /usr/local/bin/tokenhub-release-entrypoint /usr/local/bin/tokenhub-healthcheck

WORKDIR /srv/app

FROM php-base AS dependencies

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php-base

ARG TOKENHUB_VERSION=dev
LABEL org.opencontainers.image.title="TokenHub PHP" \
      org.opencontainers.image.version="${TOKENHUB_VERSION}"

WORKDIR /srv/app

COPY --from=dependencies /srv/app/vendor ./vendor
COPY app ./app
COPY bin ./bin
COPY config ./config
COPY database ./database
COPY public ./public
COPY scripts ./scripts
COPY composer.json VERSION .env.example ./

RUN mkdir -p storage/logs storage/exports storage/media storage/cache storage/caddy \
    && chown -R www-data:www-data storage

HEALTHCHECK --interval=15s --timeout=5s --retries=5 CMD ["tokenhub-healthcheck", "fpm"]

ENTRYPOINT ["tokenhub-release-entrypoint"]
CMD ["php-fpm"]
