#!/usr/bin/env bash

# ==============================================================================
# PHP 7.3 → PHP 8.2 切流数据同步脚本
#
# 用途：nginx upstream 切换到 ctc-php82 前后执行，清理新旧格式不兼容的数据。
# 回滚时（切回 ctc-php）同样需要执行一次。
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

REDIS_PASSWORD="$(grep REDIS_PASSWORD .env | cut -d '=' -f2 | tr -d ' "\r')"
REDIS_CLI="docker compose exec -T redis redis-cli -a ${REDIS_PASSWORD} --no-auth-warning"

echo "[INFO] 1. 清理业务缓存（igbinary 旧格式与 Phalcon 5 serializer 不互通）..."
CACHE_INDEX=$(${REDIS_CLI} CONFIG GET databases | tail -1 >/dev/null 2>&1; echo "")
for INDEX in 0 1 2 3; do
    ${REDIS_CLI} -n ${INDEX} FLUSHDB >/dev/null && echo "[INFO]    redis db${INDEX} 已清空"
done

echo "[INFO] 2. 清理 Session（_SESSION_:* 前缀，强制全员重登一次）..."
for INDEX in 0 1 2 3; do
    KEYS=$(${REDIS_CLI} -n ${INDEX} --scan --pattern '_SESSION_:*' | head -100000)
    if [ -n "${KEYS}" ]; then
        echo "${KEYS}" | xargs ${REDIS_CLI} -n ${INDEX} DEL >/dev/null
        echo "[INFO]    db${INDEX} session key 已清理"
    fi
done

echo "[INFO] 3. 清理 Phalcon 元数据 / 注解 / Volt 编译缓存..."
rm -rf html/ctc/storage/cache/metadata/* html/ctc/storage/cache/annotations/* html/ctc/storage/cache/volt/*
echo "[SUCCESS] 缓存目录已清空，请求时自动重建"

echo ""
echo "[NEXT] 执行切流："
echo "  灰度观察: docker compose -f docker-compose.yml -f docker-compose.php82.yml up -d"
echo "           访问 http://127.0.0.1:8082/ 验证"
echo "  正式切流: 将 nginx/conf.d/default.conf 的 fastcgi_pass php:9000 改为 php82:9000 后 docker compose restart nginx"
echo "  回    滚: 改回 php:9000，docker compose restart nginx，并再次执行本脚本"
