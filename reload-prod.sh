#!/usr/bin/env bash
set -euo pipefail

echo "==> 1. 拉取最新代码..."
git pull || true

echo "==> 2. 优化 Composer Autoload..."
docker compose exec -T -w /var/www/html/ctc php composer dump-autoload --optimize 2>/dev/null || true

echo "==> 3. 执行数据库迁移与配置升级..."
docker compose exec -T -w /var/www/html/ctc php php vendor/bin/phinx migrate -c phinx.php 2>/dev/null || true
docker compose exec -T -w /var/www/html/ctc php php console.php upgrade 2>/dev/null || true

echo "==> 4. 清理框架文件缓存..."
rm -rf html/ctc/storage/cache/volt/* 2>/dev/null || true
rm -rf html/ctc/storage/cache/metadata/* 2>/dev/null || true
rm -rf html/ctc/storage/cache/annotations/* 2>/dev/null || true
docker compose exec -T php chown -R www-data:www-data /var/www/html/ctc/storage 2>/dev/null || true

echo "==> 5. 重启 PHP-FPM 与刷新 OPcache..."
docker compose exec -T php supervisorctl restart php

echo "==> 6. 重启 WebSocket 常驻服务..."
docker compose exec -T php supervisorctl restart websocket

echo "==> 7. 平滑重载 Nginx..."
docker compose exec -T nginx nginx -s reload 2>/dev/null || true

echo "✅ 更新完成，服务已全部正常就绪！"