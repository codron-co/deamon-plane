# syntax=docker/dockerfile:1
#
# Deamon Plane — Coolify Docker Compose runtime.
# Build pack: Docker Compose → docker-compose.coolify.yml (not Nixpacks / Dockerfile-only).
# Laravel (composer.json) is added in Dalga 1 / Task 0. First Coolify deploy succeeds after that.

FROM composer:2.8 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

COPY . .

ARG COOLIFY_BRANCH=
ARG SOURCE_COMMIT=
RUN mkdir -p .deamon \
    && BRANCH="${COOLIFY_BRANCH}" \
    && if [ -z "${BRANCH}" ] && [ -n "${SOURCE_COMMIT}" ]; then BRANCH="$(printf '%.7s' "${SOURCE_COMMIT}")"; fi \
    && i=0 \
    && while [ "$i" -lt 2 ]; do \
         case "${BRANCH}" in \
           \"*\") BRANCH="${BRANCH#\"}"; BRANCH="${BRANCH%\"}" ;; \
           \'*\') BRANCH="${BRANCH#\'}"; BRANCH="${BRANCH%\'}" ;; \
           *) break ;; \
         esac; \
         i=$((i + 1)); \
       done \
    && printf '%s' "${BRANCH}" > .deamon/git-branch

RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php \
    && composer dump-autoload --optimize --classmap-authoritative --no-scripts

FROM php:8.2-fpm-bookworm AS runtime

LABEL org.opencontainers.image.title="Deamon Plane"
LABEL org.opencontainers.image.description="Internal ops control plane (Laravel) for Coolify Compose"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /var/www/html

COPY docker/nginx/default.conf /etc/nginx/conf.d/plane.conf
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf 2>/dev/null || true

COPY docker/php/conf.d/plane.ini /usr/local/etc/php/conf.d/99-plane.ini
COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

COPY --from=vendor /app /var/www/html

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    NGINX_PORT=8080 \
    WAIT_FOR_DB=true \
    WAIT_FOR_REDIS=true \
    RUN_DEPLOY_TASKS=true

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/entrypoint.sh"]
