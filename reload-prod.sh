#!/usr/bin/env bash
set -euo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

info() { echo "[INFO] $1"; }
warn() { echo "[WARN] $1" >&2; }
die()  { echo "[ERROR] $1" >&2; exit 1; }

get_env() {
  awk -F= -v k="$1" '
    $1 == k {
      sub(/^[^=]*=/, "");
      gsub(/\r/, "");
      gsub(/^[ \t]+|[ \t]+$/, "");
      gsub(/^"|"$/, "");
      print;
      exit
    }
  ' .env
}

is_placeholder() {
  case "$1" in
    ""|*change_me*|*yourdomain.com*|*your_domain.com*|*class.example.com*)
      return 0
      ;;
  esac
  return 1
}

# 不要用 root 拉取代码，否则 .git 会混入 root 属主文件，导致后续普通用户无法写入。
if [ "${EUID}" -eq 0 ] && [ "${ALLOW_ROOT:-0}" != "1" ]; then
  die "请使用仓库目录的属主用户执行；如确需 root，请显式设置 ALLOW_ROOT=1。"
fi

[ -f .env ] || die "缺少 .env，请先执行：cp .env.default .env 并填写生产配置。"
command -v docker >/dev/null 2>&1 || die "未安装 Docker。"
docker compose version >/dev/null 2>&1 || die "未安装 Docker Compose v2 插件。"

if [ "${ALLOW_PLACEHOLDER_ENV:-0}" != "1" ]; then
  APP_KEY="$(get_env APP_KEY)"
  SITE_URL="$(get_env SITE_URL)"
  SITE_DOMAIN="$(get_env SITE_DOMAIN)"
  MYSQL_ROOT_PASSWORD="$(get_env MYSQL_ROOT_PASSWORD)"
  MYSQL_PASSWORD="$(get_env MYSQL_PASSWORD)"
  REDIS_PASSWORD="$(get_env REDIS_PASSWORD)"

  [ -n "${APP_KEY}" ] || die "APP_KEY 未配置，请使用 openssl rand -hex 32 生成。"
  if [ "${#APP_KEY}" -lt 32 ]; then
    warn "APP_KEY 长度不足 32；建议在确认加密数据兼容方案后轮换。"
  fi
  if is_placeholder "${SITE_URL}"; then die "SITE_URL 仍是占位符，请填写真实站点地址。"; fi
  [[ "${SITE_URL}" == http://* || "${SITE_URL}" == https://* ]] || die "SITE_URL 必须以 http:// 或 https:// 开头。"
  if is_placeholder "${SITE_DOMAIN}"; then die "SITE_DOMAIN 仍是占位符，请填写真实域名。"; fi
  if is_placeholder "${MYSQL_ROOT_PASSWORD}"; then die "MYSQL_ROOT_PASSWORD 未配置。"; fi
  if is_placeholder "${MYSQL_PASSWORD}"; then die "MYSQL_PASSWORD 未配置。"; fi
  if is_placeholder "${REDIS_PASSWORD}"; then die "REDIS_PASSWORD 未配置。"; fi
else
  warn "ALLOW_PLACEHOLDER_ENV=1，跳过生产配置占位符检查。"
fi

docker compose config >/dev/null

if [ "${PREFLIGHT_ONLY:-0}" = "1" ]; then
  info "preflight OK"
  exit 0
fi

echo "==> 1. 拉取最新代码..."
if [ "${SKIP_GIT_PULL:-0}" = "1" ]; then
  info "SKIP_GIT_PULL=1，跳过 git pull（仅本地验证）"
else
  git pull --ff-only --prune
fi

echo "==> 2. 构建 PHP 8.2 镜像..."
docker compose build php

echo "==> 3. 启动/重建 PHP 服务..."
docker compose up -d php

echo "==> 4. 首次 PHP 8.2 切换：清理 Phalcon 3 缓存..."
CUTOVER_MARKER="${SCRIPT_DIR}/.phalcon5-cutover-done"
if [ ! -f "${CUTOVER_MARKER}" ]; then
  REDIS_PASSWORD="$(get_env REDIS_PASSWORD)"
  REDIS_INDEX="$(get_env REDIS_INDEX)"
  docker compose exec -T -e REDISCLI_AUTH="${REDIS_PASSWORD}" redis \
    redis-cli --no-auth-warning -n "${REDIS_INDEX:-0}" FLUSHDB
  touch "${CUTOVER_MARKER}"
  info "已清理 Redis DB${REDIS_INDEX:-0}（旧 Phalcon 3 缓存），后续 reload 不再自动清理"
fi

echo "==> 5. 安装/更新 Composer 依赖..."
docker compose exec -T -w /var/www/html/ctc php \
  composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

echo "==> 6. 执行数据库迁移与配置升级..."
docker compose exec -T -w /var/www/html/ctc php \
  php vendor/bin/phinx migrate -c phinx.php
docker compose exec -T -w /var/www/html/ctc php \
  php console.php upgrade || warn "console.php upgrade 返回非零，请检查日志。"

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
  php console.php maintain rebuild_index_course_cache || warn "首页缓存重建返回非零，请检查日志。"

echo "==> 11. 健康检查..."
SITE_DOMAIN="$(get_env SITE_DOMAIN)"
if ! docker compose exec -T nginx \
  curl -fsS -H "Host: ${SITE_DOMAIN}" http://127.0.0.1/health >/dev/null; then
  die "健康检查失败，请检查 nginx/php 日志。"
fi

echo "==> 12. 容器状态..."
docker compose ps

echo "✅ 更新完成，PHP 8.2.35 / Phalcon 5 已就绪"
