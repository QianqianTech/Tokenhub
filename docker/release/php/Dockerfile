# Release image: self-contained application (no bind mounts). Used by deploy/docker/compose.yml,
# the offline bundle and the image registry. Keep the extension list identical to docker/php/Dockerfile.
#
# Base images are pinned through build arguments: scripts/release/pin-base-images.sh resolves
# each tag to its digest (docker/release/base-images.env) and build-images.sh passes them in.
# The runtime stage carries no git, unzip or composer (SEC-008); they exist only in the
# dependencies stage.
ARG PHP_VERSION=8.4
ARG PHP_BASE_IMAGE=php:${PHP_VERSION}-fpm-bookworm
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${PHP_BASE_IMAGE} AS php-base

# phpredis pinned to an explicit release (DEP-101).
ARG PHPREDIS_VERSION=6.2.0

# poppler-utils: `pdftotext` lets PHP parse text PDFs for the RAG knowledge base with page
# numbers; without it every PDF waits for the Python RAG worker.
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev default-mysql-client libfcgi-bin openssl poppler-utils \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip opcache pcntl \
    && pecl install "redis-${PHPREDIS_VERSION}" \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear \
    && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

ENV PHP_FPM_MAX_CHILDREN=32 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    APP_ENV=production

COPY docker/php/conf/zz-tokenhub-fpm.conf /usr/local/etc/php-fpm.d/zz-tokenhub.conf
COPY docker/php/conf/tokenhub.ini /usr/local/etc/php/conf.d/zz-tokenhub.ini
COPY docker/release/php/entrypoint.sh /usr/local/bin/tokenhub-release-entrypoint
COPY docker/php/healthcheck.sh /usr/local/bin/tokenhub-healthcheck
RUN chmod +x /usr/local/bin/tokenhub-release-entrypoint /usr/local/bin/tokenhub-healthcheck

WORKDIR /srv/app

FROM php-base AS dependencies

RUN apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php-base AS runtime

ARG TOKENHUB_VERSION=dev
# Build id for the APP service worker cache (empty: VERSION + content hash).
ARG TOKENHUB_APP_BUILD=
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

RUN sh scripts/release/stamp-app-version.sh /srv/app "${TOKENHUB_APP_BUILD}" \
    && chmod -R a+rX app bin config database public scripts vendor composer.json VERSION .env.example \
    && mkdir -p storage/logs storage/exports storage/media storage/cache storage/caddy \
    && chown -R www-data:www-data storage

HEALTHCHECK --interval=15s --timeout=5s --retries=5 CMD ["tokenhub-healthcheck", "fpm"]

ENTRYPOINT ["tokenhub-release-entrypoint"]
CMD ["php-fpm"]
