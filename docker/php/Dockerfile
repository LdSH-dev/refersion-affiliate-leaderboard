FROM php:8.4-cli-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install bcmath zip pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Non-root user mirroring the host UID/GID so bind-mounted files stay owned by the developer.
# -o allows a non-unique GID (macOS "staff" is 20, which already exists in Debian).
RUN groupadd -o -g "${GID}" app \
    && useradd -m -o -u "${UID}" -g "${GID}" -s /bin/bash app

ENV COMPOSER_HOME=/home/app/.composer

USER app
WORKDIR /var/www/html
