FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install opcache

RUN git config --system --add safe.directory /app

COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
