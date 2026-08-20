FROM php:8.2-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        unzip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
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
    && pecl install igbinary redis \
    && docker-php-ext-enable igbinary redis \
    && rm -rf /tmp/pear /var/lib/apt/lists/*

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
    'log_errors=On' \
    'opcache.enable=1' \
    'opcache.enable_cli=1' \
    'opcache.validate_timestamps=1' \
    'opcache.revalidate_freq=0' \
    'opcache.enable_file_override=0' \
    'opcache.save_comments=1' \
    > /usr/local/etc/php/conf.d/99-v2board.ini

WORKDIR /www
