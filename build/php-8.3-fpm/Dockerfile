FROM composer:2 AS composer-source
FROM php:8.3.12-fpm-alpine3.20
COPY --from=composer-source /usr/bin/composer /usr/bin/composer
RUN apk add --update linux-headers && \
    apk add --no-cache $PHPIZE_DEPS \
    git unzip && \
    pecl install xdebug redis && \
    docker-php-ext-install pdo_mysql && \
    docker-php-ext-enable xdebug redis && \
    apk del --no-cache $PHPIZE_DEPS
COPY ./etc/Xdebug.cnf /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
WORKDIR /var/www/html
CMD ["php-fpm"]