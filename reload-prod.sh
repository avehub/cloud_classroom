#!/usr/bin/env bash
set -euo pipefail

echo "==> 1. 拉取最新代码..."
git pull --ff-only

echo "==> 2. 构建 PHP 8.2.35 镜像..."
# Dockerfile 已切到 php:8.2-fpm-bookworm + Phalcon 5
docker compose build php

echo "==> 3. 启动/重建 PHP 服务..."
# 先启动 php，保证后续 composer/phinx 在 8.2 容器内执行
docker compose up -d php

echo "==> 4. 安装/更新 Composer 依赖..."
docker compose exec -T -w /var/www/html/ctc php \
  composer install --no-dev --prefer-dist --optimize-autoloader

echo "==> 5. 执行数据库迁移与配置升级..."
docker compose exec -T -w /var/www/html/ctc php \
  php vendor/bin/phinx migrate -c phinx.php
docker compose exec -T -w /var/www/html/ctc php \
  php console.php upgrade 2>/dev/null || true

echo "==> 6. 清理框架缓存..."
rm -rf html/ctc/storage/cache/volt/* 2>/dev/null || true
rm -rf html/ctc/storage/cache/metadata/* 2>/dev/null || true
rm -rf html/ctc/storage/cache/annotations/* 2>/dev/null || true
docker compose exec -T php chown -R www-data:www-data /var/www/html/ctc/storage 2>/dev/null || true

echo "==> 7. 重启 PHP-FPM / OPcache 与 WebSocket..."
docker compose exec -T php supervisorctl restart php
docker compose exec -T php supervisorctl restart websocket

echo "==> 8. 平滑重载 Nginx..."
docker compose up -d nginx
docker compose exec -T nginx nginx -t
docker compose exec -T nginx nginx -s reload

echo "==> 9. 重建首页缓存..."
docker compose exec -T -w /var/www/html/ctc php \
  php console.php maintain rebuild_index_course_cache 2>/dev/null || true

echo "✅ 更新完成，PHP 8.2.35 / Phalcon 5 已就绪"
