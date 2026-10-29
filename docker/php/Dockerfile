# Development image: application code is bind mounted from the repository.
# Keep the extension list identical to docker/release/php/Dockerfile.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-bookworm

RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install -y --no-install-recommends git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev default-mysql-client libfcgi-bin openssl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

ENV PHP_FPM_MAX_CHILDREN=16 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf/zz-tokenhub-fpm.conf /usr/local/etc/php-fpm.d/zz-tokenhub.conf
COPY docker/php/conf/tokenhub.ini /usr/local/etc/php/conf.d/zz-tokenhub.ini
COPY docker/php/entrypoint.sh /usr/local/bin/tokenhub-entrypoint
COPY docker/php/healthcheck.sh /usr/local/bin/tokenhub-healthcheck

RUN chmod +x /usr/local/bin/tokenhub-entrypoint /usr/local/bin/tokenhub-healthcheck

WORKDIR /srv/app

ENTRYPOINT ["tokenhub-entrypoint"]
CMD ["php-fpm"]
