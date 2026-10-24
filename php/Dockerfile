FROM php:8.2-fpm-bookworm

# PHP 8.2.35 + Phalcon 5 生产运行镜像
# Phalcon 5 硬依赖 PSR 扩展，须先装 psr 再装 phalcon

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        supervisor \
        cron \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libfreetype6-dev \
        unzip \
    && docker-php-ext-configure gd \
        --with-jpeg \
        --with-webp \
        --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo_mysql \
        bcmath \
        fileinfo \
        opcache \
        pcntl \
        posix \
    && pecl install psr redis igbinary \
    && docker-php-ext-enable psr redis igbinary \
    && pecl install phalcon \
    && docker-php-ext-enable phalcon \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
