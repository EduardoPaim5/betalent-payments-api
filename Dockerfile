FROM php:8.5-cli

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev pkg-config \
    && docker-php-ext-install pdo_mysql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --prefer-dist --optimize-autoloader --no-scripts

COPY . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs \
    && chmod +x docker/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 8000

ENTRYPOINT ["./docker/entrypoint.sh"]
