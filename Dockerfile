FROM php:8.5-cli

RUN apt-get update && apt-get install -y git unzip libzip-dev libsqlite3-dev pkg-config \
    && docker-php-ext-install pdo_mysql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chmod +x docker/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["./docker/entrypoint.sh"]
