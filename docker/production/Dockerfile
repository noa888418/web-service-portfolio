# syntax=docker/dockerfile:1
FROM php:8.4.26-fpm-alpine3.24@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a AS extensions
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev oniguruma-dev \
    && docker-php-ext-install -j2 pdo_pgsql opcache mbstring

FROM php:8.4.26-fpm-alpine3.24@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a AS php-base
RUN apk add --no-cache libpq oniguruma 'nghttp2-libs>=1.70.0-r0' \
    && addgroup -g 10001 app && adduser -D -u 10001 -G app app \
    && rm -rf /usr/src/php* /usr/local/include/php /usr/local/lib/php/build \
    && rm -f /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/docker-php-ext-* /usr/local/bin/docker-php-source
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
WORKDIR /app

FROM php-base AS dependencies
COPY --from=composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b /usr/bin/composer /usr/local/bin/composer
COPY backend/composer.json backend/composer.lock ./
COPY backend/app ./app
RUN composer validate --strict && composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && composer check-platform-reqs --no-dev

FROM php-base AS php
COPY --from=dependencies /app/vendor ./vendor
COPY backend/app ./app
COPY backend/bootstrap/app.php backend/bootstrap/providers.php ./bootstrap/
COPY backend/config ./config
COPY backend/routes ./routes
COPY backend/database/migrations ./database/migrations
COPY backend/public/index.php ./public/index.php
COPY backend/artisan backend/composer.json backend/composer.lock ./
COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/production/fpm.conf /usr/local/etc/php-fpm.conf
COPY --chmod=755 docker/production/php-entrypoint.sh /usr/local/bin/run-php
RUN mkdir -p storage bootstrap/cache && chown -R app:app storage bootstrap/cache
USER 10001:10001
ENV APP_ENV=production APP_DEBUG=false
ENTRYPOINT ["/usr/local/bin/run-php"]
CMD ["php-fpm", "--nodaemonize", "--fpm-config", "/usr/local/etc/php-fpm.conf"]

FROM node:24.21.0-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json frontend/.npmrc ./
RUN npm ci --no-fund
COPY frontend/src ./src
COPY frontend/index.html frontend/tsconfig.json frontend/vite.config.ts ./
COPY docker/production/npm-inventory.mjs /build-inventory.mjs
RUN npm run build && node /build-inventory.mjs

FROM nginx:1.30.5-alpine-slim@sha256:32463212baf0e7d91aded2e9b843a4f2b9e017804b8c9d5bae7b51dcef64389c AS nginx
RUN apk add --no-cache 'pcre2>=10.49-r0'
COPY --from=frontend /build/dist /srv/www
# Retain dependency metadata outside the web root for SBOM/vulnerability inventory.
COPY frontend/package.json frontend/package-lock.json /usr/share/portfolio-inventory/
COPY --from=frontend /inventory /usr/share/portfolio-inventory/
COPY docker/production/nginx.conf /etc/nginx/portfolio.conf.template
COPY docker/production/fastcgi.conf /etc/nginx/portfolio-fastcgi.conf
COPY docker/production/tls.conf /etc/nginx/portfolio-tls.conf
COPY --chmod=755 docker/production/nginx-entrypoint.sh /usr/local/bin/run-nginx
USER 10001:10001
ENV PHP_UPSTREAM=php:9000
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/run-nginx"]
