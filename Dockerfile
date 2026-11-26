# syntax=docker/dockerfile:1@sha256:4edf897a3ffa55b89f906fc8cc78afdb3f1834cc9c7083565e611a8a7d5fe99e
# Pinned base inputs; update deliberately with a reviewed release rebuild.
FROM php:8.5-fpm-bookworm@sha256:dd198c828c224e479bdaa184cdcf04a28c73d186bf5a885493bf9cc78d7196ef AS php-base

# Optional trusted CA bundle for managed build proxies, never retained.
RUN --mount=type=secret,id=proxy_ca,target=/etc/ssl/certs/ca-certificates.crt,mode=0444 \
    sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::https::CaInfo=/etc/ssl/certs/ca-certificates.crt update \
    && apt-get -o Acquire::https::CaInfo=/etc/ssl/certs/ca-certificates.crt install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install -j2 pdo_pgsql \
    && curl --cacert /etc/ssl/certs/ca-certificates.crt --fail --silent --show-error --location \
        https://codeload.github.com/phpredis/phpredis/tar.gz/refs/tags/6.3.0 --output /tmp/redis.tar.gz \
    && echo "cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad  /tmp/redis.tar.gz" | sha256sum -c - \
    && mkdir /tmp/redis && tar -xzf /tmp/redis.tar.gz -C /tmp/redis --strip-components=1 \
    && cd /tmp/redis && phpize && ./configure && make -j2 && make install \
    && docker-php-ext-enable redis \
    && cd / && rm -rf /tmp/redis /tmp/redis.tar.gz /var/lib/apt/lists/* \
    && php -r 'foreach (["redis", "pdo_pgsql", "mbstring", "dom", "curl", "Zend OPcache"] as $extension) { if (!extension_loaded($extension)) { exit(1); } }'

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer-bin
FROM php-base AS development
RUN --mount=type=secret,id=proxy_ca,target=/etc/ssl/certs/ca-certificates.crt,mode=0444 \
    apt-get -o Acquire::https::CaInfo=/etc/ssl/certs/ca-certificates.crt update \
    && apt-get -o Acquire::https::CaInfo=/etc/ssl/certs/ca-certificates.crt install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
WORKDIR /app

FROM development AS builder
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=proxy_ca,target=/etc/ssl/certs/ca-certificates.crt,mode=0444 \
    COMPOSER_CAFILE=/etc/ssl/certs/ca-certificates.crt composer install \
        --no-dev --no-interaction --no-progress --no-scripts --prefer-dist
# .dockerignore prevents local vendor, caches, credentials and docs contamination.
COPY . .
RUN mkdir -p bootstrap/cache storage/api-docs storage/logs \
    && composer dump-autoload --no-dev --optimize --strict-psr --no-interaction \
    && composer check-platform-reqs --no-dev \
    && php artisan l5-swagger:generate \
    && php .docker/verify-openapi.php \
    && test ! -f bootstrap/cache/config.php

FROM php-base AS production
RUN --mount=type=secret,id=proxy_ca,target=/etc/ssl/certs/ca-certificates.crt,mode=0444 \
    apt-get -o Acquire::https::CaInfo=/etc/ssl/certs/ca-certificates.crt update \
    && apt-get -o Acquire::https::CaInfo=/etc/ssl/certs/ca-certificates.crt install -y --no-install-recommends nginx supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && apt-mark manual libpq5 libxml2 libonig5 libcurl4 \
    && apt-get purge -y --auto-remove autoconf dpkg-dev file g++ gcc libc6-dev make pkg-config re2c libpq-dev \
    && rm -f /usr/local/etc/php-fpm.d/*.conf
COPY --chmod=0644 .docker/php/production.ini /usr/local/etc/php/conf.d/zz-ulaxys-production.ini
COPY --chmod=0644 .docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-ulaxys.conf
COPY --chmod=0644 .docker/nginx.conf /etc/nginx/nginx.conf
COPY --chmod=0644 .docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=0755 .docker/entrypoint.sh /usr/local/bin/service-entrypoint
COPY --chmod=0644 .docker/runtime-check.php /usr/local/bin/service-runtime-check.php
COPY --chmod=0644 .docker/supervisor-events.py /usr/local/bin/service-supervisor-events.py
# Explicit runtime roots prevent build helpers or unrelated future files leaking in.
COPY --from=builder /app/app /app/app
COPY --from=builder /app/bootstrap /app/bootstrap
COPY --from=builder /app/config /app/config
COPY --from=builder /app/database /app/database
COPY --from=builder /app/public /app/public
COPY --from=builder /app/resources /app/resources
COPY --from=builder /app/routes /app/routes
COPY --from=builder /app/storage /app/storage
COPY --from=builder /app/vendor /app/vendor
COPY --from=builder /app/artisan /app/composer.json /app/composer.lock /app/
WORKDIR /app
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
        /var/run/nginx /var/lib/nginx \
    && find /app -type d -exec chmod 755 {} + \
    && find /app -type f -exec chmod 644 {} + \
    && chown -R www-data:www-data storage/framework storage/logs bootstrap/cache \
        /var/run/nginx /var/lib/nginx \
    && chmod -R 700 storage/framework storage/logs bootstrap/cache \
    && chmod 755 /usr/local/bin/service-entrypoint \
    && test -s storage/api-docs/api-docs.json
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=info \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter \
    LOG_DEPRECATIONS_CHANNEL=stderr PGCONNECT_TIMEOUT=5
USER www-data
EXPOSE 8080
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "/usr/local/bin/service-runtime-check.php", "--health"]
ENTRYPOINT ["/usr/local/bin/service-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
