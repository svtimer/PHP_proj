FROM composer:2 AS composer-source
FROM php:8.3-fpm
COPY --from=composer-source /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y \
    git unzip && \
    pecl install xdebug && \
    docker-php-ext-install pdo_mysql && \
    docker-php-ext-enable xdebug && \
    apt-get clean && rm -rf /var/lib/apt/lists/*
COPY ./etc/Xdebug.cnf /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
WORKDIR /var/www/html
CMD ["php-fpm"]