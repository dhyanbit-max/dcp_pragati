# DCP Pragati web app: one image used by the app (php-fpm), worker and scheduler services,
# plus an nginx image that carries the built public assets. See docs/16-deployment-and-operations.md.

# ---- Vendor views for Tailwind to scan (Livewire + pagination) -------------
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs --no-interaction --prefer-dist

# ---- Front-end assets (Vite + Tailwind) -----------------------------------
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /build/vendor/livewire/livewire/src ./vendor/livewire/livewire/src
COPY --from=vendor /build/vendor/laravel/framework/src/Illuminate/Pagination/resources ./vendor/laravel/framework/src/Illuminate/Pagination/resources
RUN npm run build

# ---- PHP runtime -----------------------------------------------------------
FROM php:8.3-fpm-alpine AS app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql gd intl zip bcmath pcntl opcache exif \
    && apk add --no-cache fcgi \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-dcp.ini
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-dcp.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dev dependencies (Pest, Pint) are included unless INSTALL_DEV=false (production builds).
ARG INSTALL_DEV=true
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist \
        $( [ "$INSTALL_DEV" = "true" ] || echo "--no-dev" )

COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /build/public/build ./public/build

RUN composer dump-autoload --optimize --no-interaction \
    && touch .env \
    && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]

# ---- nginx in front of php-fpm ---------------------------------------------
FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
