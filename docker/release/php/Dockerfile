FROM php:8.5-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev default-mysql-client openssl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/release/php/entrypoint.sh /usr/local/bin/tokenhub-release-entrypoint
RUN chmod +x /usr/local/bin/tokenhub-release-entrypoint

WORKDIR /srv/app

FROM php-base AS dependencies

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM php-base

WORKDIR /srv/app

COPY --from=dependencies /srv/app/vendor ./vendor
COPY app ./app
COPY bin ./bin
COPY config ./config
COPY database ./database
COPY scripts ./scripts
COPY composer.json .env.example ./

RUN mkdir -p storage/logs storage/exports storage/media storage/caddy

ENTRYPOINT ["tokenhub-release-entrypoint"]
CMD ["php-fpm"]
