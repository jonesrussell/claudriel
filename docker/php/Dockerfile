FROM php:8.4-fpm-alpine

ARG UID=1001
ARG GID=1001

RUN apk add --no-cache sqlite-dev shadow python3 py3-pip \
    && docker-php-ext-install pdo_sqlite \
    && usermod -u ${UID} www-data \
    && groupmod -g ${GID} www-data

WORKDIR /srv

# Set up Python agent virtualenv
COPY agent/requirements.txt /tmp/agent-requirements.txt
RUN python3 -m venv /srv/agent-venv \
    && /srv/agent-venv/bin/pip install --no-cache-dir -q -r /tmp/agent-requirements.txt \
    && rm /tmp/agent-requirements.txt

RUN ln -s /srv/data/.env /srv/.env \
    && ln -s /srv/data/storage /srv/storage \
    && ln -s /srv/data/waaseyaa.sqlite /srv/waaseyaa.sqlite
