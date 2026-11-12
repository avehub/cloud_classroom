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

# ---------------------------------------------------------------------------
# 时区：容器内 cron 按系统时区决定触发时刻，必须与应用时区(APP_TIMEZONE)一致，
# 否则 daily(3, x) 之类的任务会整体偏移 8 小时。基础镜像已自带 tzdata。
#
# ⚠️ 这两行必须留在文件末尾：上面任何一层（尤其 apt-get / pecl install）被改动，
# 都会让 Phalcon 等扩展重新编译（生产 2C 小内存机器上耗时 10 分钟以上且可能失败）。
# 需要给 pecl 扩展钉版本（phalcon/redis/igbinary/psr 目前均未锁版本，
# 现网 igbinary 就漂移到了 3.2.17RC1）时，请单独安排维护窗口重建镜像。
# ---------------------------------------------------------------------------
ENV TZ=Asia/Shanghai

RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo "${TZ}" > /etc/timezone
