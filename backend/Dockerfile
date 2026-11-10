# Backend Laravel — image pengembangan (php artisan serve). Untuk produksi ganti ke php-fpm + nginx.
FROM php:8.3-cli-alpine

RUN apk add --no-cache bash git unzip icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd bcmath mbstring opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader --no-progress

COPY . .
RUN composer dump-autoload --optimize \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
