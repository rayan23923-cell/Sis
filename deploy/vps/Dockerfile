# syntax=docker/dockerfile:1
# Images for the VPS staging (https://sis.azoclinic.duckdns.org), built from the repo root:
#   app  - PHP-FPM with the application, vendor/ and the built Vite assets (also runs the queue and scheduler)
#   web  - nginx serving public/ and handing PHP to `app`
# Same shape as FileNest's deploy/vps/Dockerfile; the images are multi-arch (the AWS server is x86_64).
FROM php:8.4-fpm-alpine AS base
RUN apk add --no-cache icu-libs libzip libpng freetype libjpeg-turbo mariadb-client \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath opcache pcntl gd \
    && apk del .build

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction --no-scripts

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM base AS app
COPY deploy/vps/php/php.ini /usr/local/etc/php/conf.d/zz-sis.ini
COPY deploy/vps/php/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY deploy/vps/entrypoint.sh /usr/local/bin/sis-entrypoint
RUN chmod +x /usr/local/bin/sis-entrypoint \
    && rm -rf tests deploy docs node_modules database/*.sqlite bootstrap/cache/*.php public/hot public/storage .env \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["sis-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY deploy/vps/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
