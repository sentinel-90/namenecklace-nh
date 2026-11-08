FROM composer:2 AS composer

FROM php:8.4-apache-bookworm AS php

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip \
    && a2enmod rewrite

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

FROM php AS app

ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . .
# Compose provides environment variables at runtime.
RUN touch .env \
    && composer dump-autoload --optimize --no-interaction \
    && php artisan storage:link \
    && a2enmod headers \
    && chown -R www-data:www-data storage bootstrap/cache
