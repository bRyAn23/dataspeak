# Data Speak backend - Laravel via artisan serve (local development image)
FROM php:8.4-cli-alpine
ENV LANG=C.UTF-8 LC_ALL=C.UTF-8
RUN apk add --no-cache postgresql-dev icu-dev oniguruma-dev libzip-dev git unzip \
        libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql pgsql intl zip gd \
    && docker-php-ext-enable opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
    || composer install --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY backend/ ./
RUN composer dump-autoload --optimize --no-scripts \
    && mkdir -p storage/app/tmp storage/fonts storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R a+rwX storage bootstrap/cache
COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
# php -S (not `artisan serve`): ServeCommand strips container env vars except a passthrough list,
# which would make the web workers read the bind-mounted .env instead of DB_HOST=postgres etc.
CMD ["sh", "-c", "cd public && exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]
