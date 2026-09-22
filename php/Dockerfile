FROM xiaochong0302/php:ctc

# 重编译 GD，补齐 JPEG/WebP/FreeType 支持（基础镜像的 GD 仅支持 PNG/GIF/BMP）
# Debian buster 已 EOL，软件包移至 debian-archive
RUN sed -i 's|mirrors.cloud.tencent.com/debian|mirrors.cloud.tencent.com/debian-archive/debian|g' /etc/apt/sources.list \
    && apt-get -o Acquire::Check-Valid-Until=false update \
    && apt-get install -y --no-install-recommends \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd \
        --with-jpeg-dir=/usr/include \
        --with-png-dir=/usr/include \
        --with-webp-dir=/usr/include \
        --with-freetype-dir=/usr/include \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*
