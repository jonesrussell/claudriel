FROM php:8.4-fpm-alpine

ARG UID=1001
ARG GID=1001

RUN apk add --no-cache sqlite-dev shadow \
    && docker-php-ext-install pdo_sqlite \
    && usermod -u ${UID} www-data \
    && groupmod -g ${GID} www-data

WORKDIR /srv
RUN ln -s /srv/data/.env /srv/.env \
    && ln -s /srv/data/storage /srv/storage \
    && ln -s /srv/data/waaseyaa.sqlite /srv/waaseyaa.sqlite
