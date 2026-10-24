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
# docs/01-installation.md) and rsvg-convert the SVG, and the image optimizers of
# ps/image-optimizer
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        chromium \
        ghostscript \
        imagemagick \
        jpegoptim \
        librsvg2-bin \
        optipng \
        pngquant \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# The "less" filter of Assetic runs this node with less@3.13.1 (parameters "node" and
# "node_paths"): in dev, the Assetic controller compiles the stylesheets on each change
ARG NODEJS_VERSION=10.24.1
RUN arch="$(dpkg --print-architecture)" \
    && case "${arch}" in amd64) arch=x64 ;; esac \
    && curl -sSL "https://nodejs.org/dist/v${NODEJS_VERSION}/node-v${NODEJS_VERSION}-linux-${arch}.tar.xz" \
        | tar -xJ -C /usr/local --strip-components=1 --no-same-owner --exclude=CHANGELOG.md --exclude=LICENSE --exclude=README.md \
    && ln -s /usr/local/bin/node /usr/bin/node \
    && npm install -g less@3.13.1 \
    && rm -rf /root/.npm /tmp/*

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

# Java runs the closure compiler and yuicompressor of Assetic (app/Resources/java), only
# used without debug ("?closure" and "?yui_css" filters), e.g. "assetic:dump --env=prod"
RUN mkdir -p /usr/share/man/man1 \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        "default-jre-headless" \
        "git" \
        "unzip" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN phpenmod app-default

# Composer
COPY --from=composer/composer:2.2 /usr/bin/composer /usr/bin/composer

# We can't rely on \u: the container runs as the host's raw UID, which has no
# /etc/passwd entry, so bash would print "I have no name!" instead.
RUN cat >> /etc/bash.bashrc <<'EOF'
. /etc/bash_completion

PS1='\[\e[01;33m\]builder \[\e[00;32m\]\w\[\e[0m\] '
EOF
