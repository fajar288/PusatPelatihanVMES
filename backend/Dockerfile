# Backend Laravel: image pengembangan (php artisan serve). Untuk produksi ganti ke php-fpm + nginx.
FROM php:8.3-cli-alpine

RUN apk add --no-cache bash git unzip icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd bcmath mbstring opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Batas unggahan sama dengan public/.user.ini (bawaan PHP 2 MB terlalu kecil untuk tugas LMS/dokumen)
RUN printf "upload_max_filesize=25M\npost_max_size=30M\nmax_file_uploads=20\n" > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader --no-progress

COPY . .
RUN composer dump-autoload --optimize \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
