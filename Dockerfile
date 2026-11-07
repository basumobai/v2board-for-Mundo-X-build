FROM php:8.4.27-cli-alpine3.24

# Fail the build if a stale or substituted base image is used.
RUN php -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4 || PHP_VERSION_ID < 80427) { fwrite(STDERR, "PHP security baseline not met\n"); exit(1); }'

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_ROOT_VERSION=dev-master

RUN apk upgrade --no-cache \
    && apk add --no-cache \
        ca-certificates git unzip icu-libs icu-data-full libcurl freetype \
        libjpeg-turbo oniguruma libpng libzip \
    && apk add --no-cache --virtual .v2board-build-deps \
        $PHPIZE_DEPS linux-headers curl-dev freetype-dev icu-dev \
        libjpeg-turbo-dev oniguruma-dev libpng-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        posix \
        sockets \
        zip \
    && pecl install igbinary-3.2.17RC1 redis-6.3.0 \
    && docker-php-ext-enable igbinary redis \
    && apk del --no-network .v2board-build-deps \
    && rm -rf /tmp/pear /root/.pearrc

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN printf '%s\n' \
    'memory_limit=512M' \
    'max_execution_time=300' \
    'upload_max_filesize=32M' \
    'post_max_size=32M' \
    'date.timezone=Asia/Shanghai' \
    'display_errors=Off' \
    'display_startup_errors=Off' \
    'expose_php=Off' \
    'session.upload_progress.enabled=0' \
    'log_errors=On' \
    'opcache.enable=1' \
    'opcache.enable_cli=1' \
    'opcache.validate_timestamps=1' \
    'opcache.revalidate_freq=0' \
    'opcache.enable_file_override=0' \
    'opcache.save_comments=1' \
    > /usr/local/etc/php/conf.d/99-v2board.ini

WORKDIR /www
