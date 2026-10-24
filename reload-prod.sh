#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

echo "==> 1. 拉取最新代码..."
if [ "${SKIP_GIT_PULL:-0}" = "1" ]; then
  echo "[INFO] SKIP_GIT_PULL=1，跳过 git pull（仅本地验证）"
else
  git pull --ff-only
fi

echo "==> 2. 构建 PHP 8.2.35 镜像..."
# Dockerfile 已切到 php:8.2-fpm-bookworm + Phalcon 5
docker compose build php

echo "==> 3. 启动/重建 PHP 服务..."
# 先启动 php，保证后续 composer/phinx 在 8.2 容器内执行
docker compose up -d php

echo "==> 4. 首次 PHP 8.2 切换：清理 Phalcon 3 缓存..."
CUTOVER_MARKER="${PWD}/.php82-cutover-done"
if [ ! -f "${CUTOVER_MARKER}" ]; then
  REDIS_PASSWORD="$(awk -F= '/^REDIS_PASSWORD=/{sub(/^[^=]*=/,""); gsub(/["\r]/,""); print}' .env)"
  REDIS_INDEX="$(awk -F= '/^REDIS_INDEX=/{sub(/^[^=]*=/,""); gsub(/["\r]/,""); print}' .env)"
  docker compose exec -T -e REDISCLI_AUTH="${REDIS_PASSWORD}" redis \
    redis-cli --no-auth-warning -n "${REDIS_INDEX:-0}" FLUSHDB
  touch "${CUTOVER_MARKER}"
  echo "[INFO] 已清理 Redis DB${REDIS_INDEX:-0}（旧 Phalcon 3 缓存），后续 reload 不再自动清理"
fi

echo "==> 5. 安装/更新 Composer 依赖..."
docker compose exec -T -w /var/www/html/ctc php \
  composer install --no-dev --prefer-dist --optimize-autoloader

echo "==> 6. 执行数据库迁移与配置升级..."
docker compose exec -T -w /var/www/html/ctc php \
  php vendor/bin/phinx migrate -c phinx.php
docker compose exec -T -w /var/www/html/ctc php \
  php console.php upgrade 2>/dev/null || true

echo "==> 7. 清理框架缓存..."
rm -rf html/ctc/storage/cache/volt/* 2>/dev/null || true
rm -rf html/ctc/storage/cache/metadata/* 2>/dev/null || true
rm -rf html/ctc/storage/cache/annotations/* 2>/dev/null || true
docker compose exec -T php chown -R www-data:www-data /var/www/html/ctc/storage 2>/dev/null || true

echo "==> 8. 重启 PHP-FPM / OPcache 与 WebSocket..."
docker compose exec -T php supervisorctl restart php
docker compose exec -T php supervisorctl restart websocket

echo "==> 9. 重载 Nginx（PHP 容器重建后需重新解析 upstream）..."
docker compose up -d nginx
docker compose exec -T nginx nginx -t
docker compose restart nginx

echo "==> 10. 重建首页缓存..."
docker compose exec -T -w /var/www/html/ctc php \
  php console.php maintain rebuild_index_course_cache 2>/dev/null || true

echo "✅ 更新完成，PHP 8.2.35 / Phalcon 5 已就绪"
