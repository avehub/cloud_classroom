#!/usr/bin/env bash
set -euo pipefail

# ⚠️ 不要在这里设置 umask 077。
# git pull 用当前 umask 决定新写入文件的权限，077 会把 PHP 源码写成 0600，
# 而 php-fpm 与容器内 cron 都以 www-data 运行，结果是：
#   - 页面 500 / 302（读不到 BasicInfo 等类文件）
#   - 定时任务报 "handler class cannot be loaded"
# 且因为 opcache.validate_timestamps=0，故障往往要等到 fpm 重启后才暴露。
# .env 的权限由下面的显式 chmod 600 保证，不依赖 umask。

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
      exit;
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
chmod 600 .env 2>/dev/null || true
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

# cron 只执行 /etc/cron.d 下属主为 root 的文件；属主不对时调度器会静默失效（无任何日志），
# 表现为点播时长兜底同步、发货、订单关闭、退款、索引同步等全部停摆。
# 历史上一次针对代码目录的递归 chown 就把这个文件带走了，故在预检阶段强制校验。
CRON_FILE="php/cron.d/course"
if [ -f "${CRON_FILE}" ] && [ "$(uname -s)" = "Linux" ]; then
  CRON_UID="$(stat -c '%u' "${CRON_FILE}" 2>/dev/null || echo unknown)"
  if [ "${CRON_UID}" != "0" ]; then
    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
      sudo chown root:root "${CRON_FILE}"
      sudo chmod 644 "${CRON_FILE}"
      info "已把 ${CRON_FILE} 属主修正为 root（否则容器内 cron 会忽略它）。"
    else
      die "${CRON_FILE} 属主不是 root，容器内 cron 会静默忽略，定时任务将全部失效。请执行：sudo chown root:root ${CRON_FILE} && sudo chmod 644 ${CRON_FILE}"
    fi
  fi
fi

docker compose config >/dev/null

if [ "${PREFLIGHT_ONLY:-0}" = "1" ]; then
  info "preflight OK"
  exit 0
fi

# ---------------------------------------------------------------------------
# 增量判断：只有相关输入变化时才执行重建镜像 / 重装依赖等重活
# ---------------------------------------------------------------------------
LAST_DEPLOY_FILE="${SCRIPT_DIR}/.last-deployed-commit"
OLD_HEAD="$(cat "${LAST_DEPLOY_FILE}" 2>/dev/null || true)"

if [ -n "${OLD_HEAD}" ] && ! git cat-file -e "${OLD_HEAD}^{commit}" 2>/dev/null; then
  warn ".last-deployed-commit (${OLD_HEAD}) 不在本仓库中，本次按全量发布处理。"
  OLD_HEAD=""
fi

echo "==> 1. 拉取最新代码..."
if [ "${SKIP_GIT_PULL:-0}" = "1" ]; then
  info "SKIP_GIT_PULL=1，跳过 git pull（仅本地验证）"
else
  git pull --ff-only --prune
fi

NEW_HEAD="$(git rev-parse HEAD)"

FULL_RUN=0
if [ -z "${OLD_HEAD}" ]; then
  OLD_HEAD="${NEW_HEAD}"
  FULL_RUN=1
  info "首次记录部署版本，本次执行全量步骤（含镜像构建与依赖安装）。"
fi

# 指定路径在 上次部署..本次 之间是否有变化；全量发布时恒为真
changed() {
  [ "${FULL_RUN}" = "1" ] && return 0
  [ -n "$(git diff --name-only "${OLD_HEAD}" "${NEW_HEAD}" -- "$@")" ]
}

echo "==> 2. 代码权限归一（php-fpm / cron 以 www-data 运行，必须可读）..."
chmod -R a+rX html/ctc/app html/ctc/bootstrap html/ctc/config html/ctc/db \
              html/ctc/websocket html/ctc/public 2>/dev/null || true
chmod a+r html/ctc/*.php 2>/dev/null || true

echo "==> 3. 构建 PHP 镜像（仅在 php/Dockerfile 变化时）..."
# html/、php/conf.d/*、php/fpm/*、php/cron.d/*、php/supervisor/conf.d 全是 bind mount，
# 改业务代码或这些配置都不需要重建镜像；而重建会重跑 pecl（Phalcon 未锁版本），
# 在 2C 小内存机器上耗时 10 分钟以上且可能失败，因此按需触发。
if [ "${SKIP_PHP_BUILD:-0}" = "1" ]; then
  warn "SKIP_PHP_BUILD=1，已跳过镜像构建；php/Dockerfile 的变更本次不会生效。"
elif [ "${REBUILD_PHP:-0}" = "1" ] || changed php/Dockerfile; then
  ROLLBACK_TAG="ctc/php:rollback-$(date +%Y%m%d%H%M%S)"
  if docker tag ctc/php:8.2 "${ROLLBACK_TAG}" 2>/dev/null; then
    info "当前镜像已备份为 ${ROLLBACK_TAG}（回退：docker tag ${ROLLBACK_TAG} ctc/php:8.2 && docker compose up -d php）"
  else
    warn "未能给现有 ctc/php:8.2 打回退标签（首次构建？），继续。"
  fi
  docker compose build php
else
  info "php/Dockerfile 未变化，跳过镜像构建（强制重建：REBUILD_PHP=1 ./reload-prod.sh）"
fi

echo "==> 4. 启动/重建 PHP 服务..."
docker compose up -d php

echo "==> 4.1 校验 PHP 运行时版本基线..."
# pecl 扩展与基础镜像都未锁版本：重建镜像可能悄悄换掉 Phalcon/redis/igbinary。
# 这里把实际版本与 php/runtime-versions.lock 比对，让漂移从"静默"变成"可见"。
RUNTIME_LOCK="php/runtime-versions.lock"
RUNTIME_ACTUAL="$(docker compose exec -T php php -r 'printf("php=%s phalcon=%s redis=%s igbinary=%s psr=%s", PHP_VERSION, phpversion("phalcon"), phpversion("redis"), phpversion("igbinary"), phpversion("psr"));' 2>/dev/null | tr -d '\r\n' || true)"

if [ -z "${RUNTIME_ACTUAL}" ]; then
  warn "未能读取容器内扩展版本，跳过基线校验。"
else
  info "运行时：${RUNTIME_ACTUAL}"
  if [ -f "${RUNTIME_LOCK}" ]; then
    RUNTIME_EXPECTED="$(grep -v '^[[:space:]]*#' "${RUNTIME_LOCK}" | grep -v '^[[:space:]]*$' | head -1 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    if [ -n "${RUNTIME_EXPECTED}" ] && [ "${RUNTIME_ACTUAL}" != "${RUNTIME_EXPECTED}" ]; then
      warn "运行时版本与基线不一致（镜像重建可能升级了扩展）："
      warn "  基线: ${RUNTIME_EXPECTED}"
      warn "  实际: ${RUNTIME_ACTUAL}"
      warn "确认新版本可用后，请更新 ${RUNTIME_LOCK} 并提交。"
    fi
  else
    warn "缺少 ${RUNTIME_LOCK}，无法比对运行时基线。"
  fi
fi

echo "==> 5. Composer 依赖（仅在 composer.json/lock 变化时安装）..."
if [ "${SKIP_COMPOSER:-0}" = "1" ]; then
  warn "SKIP_COMPOSER=1，已跳过 composer install；依赖变更本次不会生效。"
elif [ "${FORCE_COMPOSER:-0}" = "1" ] || changed html/ctc/composer.json html/ctc/composer.lock; then
  # vendor/ 为 root 属主，composer 必须以 root 运行
  docker compose exec -T -w /var/www/html/ctc php \
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
else
  info "composer.json / composer.lock 未变化，跳过 composer install（强制执行：FORCE_COMPOSER=1）"
fi

echo "==> 6. 数据库迁移与配置升级..."
# 以 www-data 身份运行，避免在 storage/ 里留下 root 属主文件（那会导致 fpm 之后写不了日志/缓存）
docker compose exec -T -u www-data -w /var/www/html/ctc php \
  php vendor/bin/phinx migrate -c phinx.php
# console.php upgrade 负责 console 迁移 + 重置 app_info/setting/annotation/metadata/volt/nav 缓存，
# 失败必须中止：此时 fpm 还没重启，线上仍是旧代码，可安全排查后重跑。
docker compose exec -T -u www-data -w /var/www/html/ctc php \
  php console.php upgrade \
  || die "console.php upgrade 失败：迁移或缓存重置未完成，已中止发布（fpm 未重启，线上仍是旧代码）。"

echo "==> 7. 清理模板编译缓存..."
# metadata / annotation 走 Redis 适配器，由上一步 console.php upgrade 清理；
# storage/cache/metadata、storage/cache/annotations 目录里只有 .gitignore，删它们没有意义。
rm -rf html/ctc/storage/cache/volt/* 2>/dev/null || true

echo "==> 8. 重启 PHP-FPM / OPcache 与 WebSocket..."
# opcache.validate_timestamps=0：不重启 fpm，新代码不会生效。
# WorkerMan 是常驻进程，同样必须重启才会加载新代码（会断开在线长连接）。
docker compose exec -T php supervisorctl restart php
docker compose exec -T php supervisorctl restart websocket

echo "==> 9. 重载 Nginx（零停机）..."
docker compose up -d nginx
docker compose exec -T nginx nginx -t
# php 容器重建后 upstream（fastcgi_pass php:9000）需要重新解析，reload 即可，
# 不要用 restart —— 那会让整站（含静态资源）短暂中断。
docker compose exec -T nginx nginx -s reload

echo "==> 10. 重建首页缓存..."
docker compose exec -T -u www-data -w /var/www/html/ctc php \
  php console.php maintain rebuild_index_course_cache || warn "首页缓存重建返回非零，请检查日志。"

echo "==> 11. 健康检查..."
SITE_DOMAIN="$(get_env SITE_DOMAIN)"
if ! docker compose exec -T nginx \
  curl -fsS -H "Host: ${SITE_DOMAIN}" http://127.0.0.1/health >/dev/null; then
  die "健康检查失败，请检查 nginx/php 日志。"
fi

echo "==> 12. 记录部署版本与容器状态..."
echo "${NEW_HEAD}" > "${LAST_DEPLOY_FILE}"
chmod 600 "${LAST_DEPLOY_FILE}" 2>/dev/null || true
docker compose ps

echo "✅ 更新完成（${NEW_HEAD:0:7}），PHP 8.2.35 / Phalcon 5 已就绪"
