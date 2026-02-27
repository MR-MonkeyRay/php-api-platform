FROM php:8.4-fpm-alpine

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apk add --no-cache \
    bash \
    git \
    libpq-dev \
    sqlite-dev \
    libzip-dev \
    oniguruma-dev \
    tzdata \
    unzip \
 && docker-php-ext-install \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
 && apk add --no-cache --virtual .pecl-build-deps $PHPIZE_DEPS \
 && pecl install apcu \
 && docker-php-ext-enable apcu \
 && apk del .pecl-build-deps \
 && git config --system --add safe.directory /var/www/html \
 && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/app/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint \
 && mkdir -p /var/www/html/var/logs /var/www/html/var/data \
 && chown -R www-data:www-data /var/www/html/var

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php-fpm", "-F"]
