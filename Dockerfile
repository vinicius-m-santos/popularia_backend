# syntax=docker/dockerfile:1
# Imagem do backend: FrankenPHP (Caddy + PHP 8.4), a mesma base usada pelo API Platform.
# Alvo "dev": código montado como volume e ferramentas de desenvolvimento.
# Alvo "prod": código copiado, dependências sem dev e cache aquecido.

FROM dunglas/frankenphp:1.13-php8.4 AS base

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip qpdf coreutils util-linux \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    opcache \
    zip \
    apcu

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY docker/Caddyfile /etc/frankenphp/Caddyfile

# ---------------------------------------------------------------------------
FROM base AS dev

ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php/app.ini docker/php/app.dev.ini $PHP_INI_DIR/conf.d/

# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/app.ini docker/php/app.prod.ini $PHP_INI_DIR/conf.d/

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN rm -f .env.local .env.*.local \
    && chmod +x docker/start.sh \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && composer dump-env prod \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var /data/caddy /config/caddy

USER www-data
