# hadolint global ignore=DL3008

FROM debian:13.7-slim AS php-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ca-certificates \
        gnupg \
        xz-utils \
    && curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ trixie main" > /etc/apt/sources.list.d/sury.list \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash-completion \
        procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

ARG PHP_VERSION
# Minimal patch version of each PHP branch, to bump after a security release: it
# invalidates the build cache (local and registry) of the PHP packages, which would
# keep the vulnerable version otherwise, and the build fails until Sury publishes it
ARG PHP_MIN_VERSIONS="8.5.12"

# Extensions of the production server (docs/01-installation.md)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        "php${PHP_VERSION}-amqp" \
        "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-bz2" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-gd" \
        "php${PHP_VERSION}-gmp" \
        "php${PHP_VERSION}-iconv" \
        "php${PHP_VERSION}-imagick" \
        "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-memcached" \
        "php${PHP_VERSION}-mysql" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-zip" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && for min in ${PHP_MIN_VERSIONS}; do \
        if [ "${min%.*}" = "${PHP_VERSION}" ] && dpkg --compare-versions "$(php -r 'echo PHP_VERSION;')" lt "${min}"; then \
            echo "PHP ${min} is required, packages.sury.org does not have it yet." >&2; \
            exit 1; \
        fi; \
    done

# Binaries called by the application: Chromium (screenshots of the shared links, see
# Utils/WebScreenshotUtils.php), ImageMagick with its delegates, Ghostscript to read the
# PDF (the policy of Debian 13 does not block them, unlike the one of
# docs/01-installation.md) and rsvg-convert the SVG, and the image optimizers of the
# post-processors of Liip (app/config/config.yml)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        chromium \
        ghostscript \
        imagemagick \
        jpegoptim \
        librsvg2-bin \
        pngquant \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# Every PHP setting of every image: each stage enables its own ones with phpenmod
COPY php/mods-available/ /etc/php/${PHP_VERSION}/mods-available/

ENV PHP_VERSION=${PHP_VERSION}
ENV HOME=/home/app
ENV COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www

FROM php-base AS frontend

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        "php${PHP_VERSION}-fpm" \
        runit \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && rm -r "/etc/php/${PHP_VERSION}/fpm/pool.d/"

RUN useradd -s /bin/false nginx

# The container runs as the host user so the socket directory must be writable by anyone
COPY php/fpm/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
COPY nginx/nginx.conf /etc/nginx/nginx.conf
COPY nginx/conf.d/ /etc/nginx/conf.d/
RUN mkdir -p /var/run/php && chmod 1777 /var/run/php
RUN rm -rf /etc/service/
COPY runit/ /etc/service/
RUN mkdir -p /etc/service/nginx/supervise /etc/service/php-fpm/supervise \
    && chmod 777 /etc/service/*/supervise/

RUN phpenmod app-default \
    && phpenmod -s fpm app-fpm

EXPOSE 8080

CMD ["runsvdir", "-P", "/etc/service"]

FROM php-base AS worker

RUN phpenmod app-default

FROM php-base AS builder

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        "git" \
        "unzip" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# Node builds the CSS and JS of the site with Vite (package.json, vite.config.js, castor
# app:assets:build): in the builder only, the other images serve what it writes in web/build/
ARG NODEJS_VERSION=24.22.1
RUN arch="$(dpkg --print-architecture)" \
    && case "${arch}" in amd64) arch=x64 ;; esac \
    && curl -sSL "https://nodejs.org/dist/v${NODEJS_VERSION}/node-v${NODEJS_VERSION}-linux-${arch}.tar.xz" \
        | tar -xJ -C /usr/local --strip-components=1 --no-same-owner --exclude=CHANGELOG.md --exclude=LICENSE --exclude=README.md \
    && rm -rf /tmp/*

RUN phpenmod app-default

# Composer
COPY --from=composer/composer:2.2 /usr/bin/composer /usr/bin/composer

# We can't rely on \u: the container runs as the host's raw UID, which has no
# /etc/passwd entry, so bash would print "I have no name!" instead.
RUN cat >> /etc/bash.bashrc <<'EOF'
. /etc/bash_completion

PS1='\[\e[01;33m\]builder \[\e[00;32m\]\w\[\e[0m\] '
EOF

# ------------------------- Production stages -------------------------
#
# From docker-starter: two images, "php" and "nginx", with the code of the site (no bind mount), its
# dependencies without the dev packages, its CSS and JS, its cache warmed. "php" runs php-fpm as the
# user 1000, and with another command the workers of Messenger (bin/console messenger:consume ...)
# and the crontab (supercronic prod/crontab). "nginx" serves web/, and passes the rest to php-fpm
# through its unix socket /var/run/php/php-fpm.sock, in a volume shared by the two containers.
#
# Nothing secret in them: app/config/parameters.yml (prod/parameters.yml) reads its values in the
# environment of the containers, at runtime, and the DKIM key is mounted in keys/.
#
# The repository is the additional build context "app" (its .dockerignore applies), see
# docker-compose.prod.yml:
#   docker buildx build --build-context app=../../../.. --target php .

FROM builder AS app-build

ENV SYMFONY_ENV=prod

# Dependencies first, for the cache of the layers. "app" is a build context, not a stage. Most of
# the locked packages only declare PHP 7: "php+" ignores their upper bound (castor app:install)
# hadolint ignore=DL3022
COPY --from=app composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader --ignore-platform-req=php+

# hadolint ignore=DL3022
COPY --from=app package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# hadolint ignore=DL3022
COPY --from=app . .
COPY prod/parameters.yml app/config/parameters.yml

# The scripts of composer.json without the ones of the dev: parameters.yml is the one above, and the
# assets of the bundles are copies, the image "nginx" has no vendor/. The warmers of the cache create
# services (the entity manager...) without calling them: placeholders for the variables they read,
# which the cache does not keep (the version of MariaDB only gives Doctrine its platform). var/ is
# put aside for the image "php", where it is the only directory of the code that PHP writes in.
RUN composer dump-autoload --no-dev --optimize \
    && npx vite build \
    && rm -rf node_modules \
    && bin/console assets:install web
RUN env \
        DATABASE_HOST=placeholder DATABASE_USER=placeholder DATABASE_PASSWORD=placeholder \
        DATABASE_SERVER_VERSION=10.11.19-MariaDB \
        ELASTICSEARCH_HOST=placeholder MAILER_DSN=null://null MEMCACHED_HOST=placeholder \
        MERCURE_JWT_SECRET=placeholder MERCURE_PUBLIC_URL=https://placeholder MERCURE_URL=https://placeholder \
        RABBITMQ_HOST=placeholder RABBITMQ_PASSWORD=placeholder RABBITMQ_USER=placeholder \
        ROUTER_DEFAULT_URI=https://placeholder SECRET=placeholder \
        WEBPUSH_PRIVATE_KEY=placeholder WEBPUSH_PUBLIC_KEY=placeholder \
        bin/console cache:warmup \
    && rm -rf var/logs/* \
    && mv var /var/www-var

FROM php-base AS php

ENV SYMFONY_ENV=prod
# The sitemaps (ladb:cron:sitemaps) go to their volume, served by the image "nginx"
ENV SITEMAPS_DIR=/var/www/sitemaps

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        "php${PHP_VERSION}-fpm" \
        libfcgi-bin \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && rm -r "/etc/php/${PHP_VERSION}/fpm/pool.d/"

# supercronic runs the crontab without root, and writes the output of the commands in the log of
# the container
ARG SUPERCRONIC_VERSION=0.2.51
RUN arch="$(dpkg --print-architecture)" \
    && case "${arch}" in \
        amd64) sha256=cc232108374e8e2b537045f2d5eaa1229f619db314c3ba6a14d38b35dc0aa123 ;; \
        arm64) sha256=4ae22576a78223d8ccdf5f3b3660a5cbb5288416bf0ca96afc4a36d195932b94 ;; \
        *) echo "No supercronic for ${arch}." >&2; exit 1 ;; \
    esac \
    && curl -sSLo /usr/local/bin/supercronic "https://github.com/aptible/supercronic/releases/download/v${SUPERCRONIC_VERSION}/supercronic-linux-${arch}" \
    && echo "${sha256}  /usr/local/bin/supercronic" | sha256sum -c - \
    && chmod +x /usr/local/bin/supercronic
COPY prod/crontab /etc/lairdubois/crontab

# Flags of Chromium given by the deployment, in CHROMIUM_EXTRA_FLAGS: the /usr/bin/chromium of Debian
# reads /etc/chromium.d/
# hadolint ignore=SC2016
RUN echo 'CHROMIUM_FLAGS="$CHROMIUM_FLAGS ${CHROMIUM_EXTRA_FLAGS:-}"' > /etc/chromium.d/lairdubois

# The php-fpm pool of the dev "frontend" stage, plus the production settings
COPY php/fpm/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
COPY prod/app-prod.ini /etc/php/${PHP_VERSION}/mods-available/app-prod.ini
RUN phpenmod app-default \
    && phpenmod -s fpm app-fpm \
    && phpenmod app-prod

RUN groupadd -g 1000 app \
    && useradd -m -d /home/app -u 1000 -g 1000 -s /bin/false app \
    && mkdir -p /var/run/php \
    && chown app:app /var/run/php

# The code belongs to root: PHP only writes in var/ (cache, logs) and in the volumes of
# docker-compose.prod.yml, mounted on these directories
COPY --from=app-build /var/www /var/www
COPY --from=app-build --chown=app:app /var/www-var /var/www/var
RUN mkdir -p downloads keys sitemaps uploads web/media \
    && chown app:app downloads sitemaps uploads web/media

USER 1000:1000

HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
    CMD ["sh", "-c", "SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect /var/run/php/php-fpm.sock || exit 1"]

CMD ["sh", "-c", "exec /usr/sbin/php-fpm${PHP_VERSION}"]

FROM nginx:1.29-alpine AS nginx

COPY prod/nginx.conf /etc/nginx/conf.d/default.conf

COPY --from=app-build /var/www/web /var/www/web

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
    CMD ["wget", "-q", "-O", "/dev/null", "http://127.0.0.1:8080/ping"]
