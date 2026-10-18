FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# install deps first so Docker caches this layer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

# build tools are only needed to install packages, so remove them from the final image
RUN apt-get purge -y git unzip && rm -f /usr/bin/composer

RUN printf "expose_php=Off\ndisplay_errors=Off\n" > /usr/local/etc/php/conf.d/hardening.ini \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data

# safe fallbacks if an env var is missing, Render's values still override these
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr

ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 10000
CMD ["sh", "docker/start.sh"]
