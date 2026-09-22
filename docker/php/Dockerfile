# syntax=docker/dockerfile:1
FROM php:8.4-cli-alpine@sha256:1f044d6de9f2338df85f32719dfd2938e284f848a91b5ccee6a3d375ca81caa5 AS extensions
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install -j2 pdo_pgsql

FROM php:8.4-cli-alpine@sha256:1f044d6de9f2338df85f32719dfd2938e284f848a91b5ccee6a3d375ca81caa5 AS base
RUN apk add --no-cache libpq \
    && addgroup -g 10001 app \
    && adduser -D -u 10001 -G app app
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/docker-php-ext-pdo_pgsql.ini /usr/local/etc/php/conf.d/
WORKDIR /app
RUN chown app:app /app
USER app

FROM base AS tooling
COPY --from=composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_HOME=/tmp/composer
ENTRYPOINT ["composer"]

FROM tooling AS dependencies
COPY --chown=app:app backend/composer.json backend/composer.lock ./
RUN composer validate --strict \
    && composer install --no-interaction --prefer-dist --no-progress --no-scripts \
    && composer check-platform-reqs

FROM base AS local
COPY --from=dependencies --chown=app:app /app/vendor /app/vendor
COPY --chown=app:app backend/ /app/
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
CMD ["php", "artisan", "--version"]
