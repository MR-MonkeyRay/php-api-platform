FROM composer:2 AS composer-bin

FROM php:8.4-fpm-alpine AS app-runtime

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_USER=app \
    APP_UID=1000 \
    APP_GID=1000

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
 && rm -rf /tmp/pear

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/app/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint \
 && addgroup -g ${APP_GID} -S ${APP_USER} \
 && adduser -u ${APP_UID} -S -D -G ${APP_USER} ${APP_USER} \
 && mkdir -p /var/www/html/var/logs /var/www/html/var/data /var/www/html/var/audit /var/www/html/var/policy /var/www/html/var/database \
 && chown -R ${APP_USER}:${APP_USER} /var/www/html

WORKDIR /var/www/html

USER ${APP_USER}

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php-fpm", "-F"]
