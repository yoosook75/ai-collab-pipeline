# syntax=docker/dockerfile:1

# --- vendor: install PHP dependencies without dev packages ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# --- runtime: FrankenPHP serving Laravel on :8080 ---
FROM dunglas/frankenphp:1-php8.4 AS runtime

RUN install-php-extensions pdo_mysql intl zip opcache pcntl \
    && apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/Caddyfile.app /etc/frankenphp/Caddyfile.app
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /app
COPY --from=vendor /app /app

RUN chmod +x /usr/local/bin/app-entrypoint \
    && rm -rf /app/tests /app/docs /app/.github /app/.superpowers /app/node_modules \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

USER www-data
EXPOSE 8080
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile.app"]
