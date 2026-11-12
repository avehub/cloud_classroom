FROM php:8.2.35-fpm-bookworm

# PHP 8.2.35 + Phalcon 5.23.0 生产运行镜像
# Phalcon 5 硬依赖 PSR 扩展，须先装 psr 再装 phalcon
#
# 版本锁定说明：
#   - 基础镜像钉到 patch 版本（保留 tag 形式而非 digest，以便本地 arm64 开发与
#     生产 amd64 共用同一份 Dockerfile；本次验证解析到 amd64 的
#     sha256:f15b9649b74a4af4621c960b2d9861a998e6eaf652a28089ceb4b3f238b371d4）
#   - pecl 扩展全部钉版本，避免重建时静默升级运行时（历史上 igbinary 曾漂到 3.2.17RC1）
#   - 运行时基线记录在 php/runtime-versions.lock，reload-prod.sh 每次发布后自动比对
#
# ⚠️ 改动本文件任意一层都会触发扩展重新编译：本地 Apple Silicon（Rosetta 模拟 amd64）
#    实测约 9.3 分钟；生产 2C / 可用内存不足 2G，请勿在生产直接重建，
#    改为异地构建后导入：
#      docker buildx build --platform linux/amd64 -t ctc/php:8.2 --load php/
#      docker save ctc/php:8.2 | gzip > ctc-php-8.2-amd64.tar.gz
#      # 传到服务器后：gunzip -c ... | docker load && docker compose up -d php

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
    && pecl install psr-1.2.0 redis-6.3.0 igbinary-3.2.16 \
    && docker-php-ext-enable psr redis igbinary \
    && pecl install phalcon-5.23.0 \
    && docker-php-ext-enable phalcon \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------------------
# 时区：容器内 cron 按系统时区决定触发时刻，必须与应用时区(APP_TIMEZONE)一致，
# 否则 daily(3, x) 之类的任务会整体偏移 8 小时。基础镜像已自带 tzdata。
#
# ⚠️ 这两行必须留在文件末尾：放在 apt/pecl 层之前会让扩展全部重新编译。
# ---------------------------------------------------------------------------
ENV TZ=Asia/Shanghai

RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo "${TZ}" > /etc/timezone
