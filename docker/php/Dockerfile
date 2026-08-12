# syntax=docker/dockerfile:1.7

FROM composer:2.10.2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS composer

FROM php:8.2.32-fpm-bookworm@sha256:baed99aec14419f3d4413b3735f0723a0d1d754b9149f46762b662f3e3156284 AS php-base

ENV TZ=UTC \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    apt-get update; \
    apt-get install --no-install-recommends --yes \
        libfcgi-bin \
        libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
    apt-mark manual libfcgi-bin libpq5; \
    apt-get purge --auto-remove --yes libpq-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-fpm.d/app.conf /usr/local/etc/php-fpm.d/zz-app.conf

FROM php-base AS vendor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends --yes unzip; \
    rm -rf /var/lib/apt/lists/*

COPY . /app

RUN set -eux; \
    composer install \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --optimize-autoloader; \
    vendor/bin/codecept build; \
    rm -rf /tmp/composer

FROM php-base

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY --from=vendor /app /app

RUN set -eux; \
    mkdir -p /app/runtime/test-output /app/web/assets; \
    rmdir /app/tests/_output; \
    ln -s /app/runtime/.php-cs-fixer.cache /app/.php-cs-fixer.cache; \
    ln -s /app/runtime/test-output /app/tests/_output; \
    chown -R www-data:www-data /app/runtime /app/web/assets; \
    chmod 0755 /app/runtime /app/web/assets

USER www-data

STOPSIGNAL SIGQUIT

CMD ["php-fpm"]
