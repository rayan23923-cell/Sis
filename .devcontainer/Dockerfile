# PHP 8.3 with the extensions SIS needs beyond the base image: gd (PDF documents) and zip (Excel import/export).
FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

RUN rm -f /etc/apt/sources.list.d/yarn.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip \
    # xdebug slows every request a lot and is not needed for a preview
    && rm -f /usr/local/etc/php/conf.d/*xdebug*.ini \
    && rm -rf /var/lib/apt/lists/*
