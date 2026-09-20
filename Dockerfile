FROM node:20-bookworm-slim AS frontend-build

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY . .
RUN npm run build:production

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gzip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libwebp-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        pdo_sqlite \
        simplexml \
    && a2enmod headers remoteip rewrite setenvif \
    && rm -rf /var/lib/apt/lists/*

# Runtime configuration lives outside the public document root.
COPY --from=frontend-build /src/docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY --from=frontend-build /src/docker/container-entrypoint.sh /usr/local/bin/meteonexa-container-entrypoint
COPY --from=frontend-build /src/docker/worker-loop.sh /usr/local/bin/meteonexa-worker-loop

# Production image allowlist. Frontend assets come from the esbuild/fingerprint
# build stage; source modules, QA suites, CI metadata, docs and Node tooling are
# never copied into the PHP runtime image.
COPY --from=frontend-build /src/.htaccess /src/index.html /src/privacy.html /src/cookie-policy.html /src/offline.html /src/maintenance.html \
     /src/asset-manifest.json /src/robots.txt /src/sitemap.xml /var/www/html/
COPY --from=frontend-build /src/js/asset-manifest.js /src/js/sw.js /src/js/maintenance.js /var/www/html/js/
COPY --from=frontend-build /src/css/maintenance.css /var/www/html/css/
COPY --from=frontend-build /src/api /var/www/html/api
COPY --from=frontend-build /src/assets /var/www/html/assets
COPY --from=frontend-build /src/dist /var/www/html/dist
COPY --from=frontend-build /src/install/.htaccess /src/install/index.php /src/install/InstallerException.php /var/www/html/install/
COPY --from=frontend-build /src/diagnostics/index.php /var/www/html/diagnostics/index.php
COPY --from=frontend-build /src/qa/index.php /var/www/html/qa/index.php

RUN chmod +x /usr/local/bin/meteonexa-container-entrypoint /usr/local/bin/meteonexa-worker-loop \
    && mkdir -p /var/lib/meteonexa /var/run/apache2 /var/lock/apache2 \
    && chown -R www-data:www-data /var/lib/meteonexa /var/run/apache2 /var/lock/apache2 /var/www/html \
    && find /var/www/html -type d -exec chmod 0755 {} \; \
    && find /var/www/html -type f -exec chmod 0644 {} \;

WORKDIR /var/www/html
USER www-data:www-data
ENTRYPOINT ["/usr/local/bin/meteonexa-container-entrypoint"]
CMD ["apache2-foreground"]
