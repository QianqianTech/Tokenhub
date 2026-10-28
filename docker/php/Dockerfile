FROM php:8.5-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev default-mysql-client \
    && docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/tokenhub-entrypoint

RUN chmod +x /usr/local/bin/tokenhub-entrypoint

WORKDIR /srv/app

ENTRYPOINT ["tokenhub-entrypoint"]
CMD ["php-fpm"]
