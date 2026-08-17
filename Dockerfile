FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --ignore-platform-req=ext-sodium

COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache bash icu-dev libzip-dev oniguruma-dev libsodium-dev \
    && docker-php-ext-install intl mbstring opcache pdo_mysql zip sodium

COPY --from=vendor /app /var/www/html

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache storage/app/firebase \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
