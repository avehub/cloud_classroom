#!/usr/bin/env bash

# ==============================================================================
# CTC 生产环境首次部署与自愈脚本 (Linux / Docker Compose)
# ==============================================================================

set -euo pipefail
umask 077

info()    { echo -e "\033[34m[INFO] $1\033[0m"; }
success() { echo -e "\033[32m[SUCCESS] $1\033[0m"; }
warn()    { echo -e "\033[33m[WARN] $1\033[0m" >&2; }
error()   { echo -e "\033[31m[ERROR] $1\033[0m" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

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

info "====== 1. 检查运行环境 ======"

command -v docker >/dev/null 2>&1 || error "未检测到 Docker，请先安装 Docker。"
docker compose version >/dev/null 2>&1 || error "未检测到 Docker Compose v2 插件。"

if [ "$(uname -s)" = "Linux" ] && [ "${EUID}" -eq 0 ]; then
    warn "当前为 root；建议使用仓库目录属主用户执行，避免 .git 属主混乱。"
fi

if [ ! -f .env ]; then
    if [ -f .env.default ]; then
        cp .env.default .env
        chmod 600 .env
        error "已从 .env.default 生成 .env；请先填写真实域名、APP_KEY、数据库和 Redis 密码后再运行。"
    else
        error "缺失 .env.default 模板，无法继续。"
    fi
fi
chmod 600 .env 2>/dev/null || true

APP_KEY="$(get_env APP_KEY)"
SITE_URL="$(get_env SITE_URL)"
SITE_DOMAIN="$(get_env SITE_DOMAIN)"
MYSQL_ROOT_PASSWORD="$(get_env MYSQL_ROOT_PASSWORD)"
MYSQL_PASSWORD="$(get_env MYSQL_PASSWORD)"
REDIS_PASSWORD="$(get_env REDIS_PASSWORD)"

[ -n "${APP_KEY}" ] || error "APP_KEY 未配置。"
if [ "${#APP_KEY}" -lt 32 ]; then
    warn "APP_KEY 长度不足 32；建议在确认加密数据兼容方案后轮换。"
fi
if is_placeholder "${SITE_URL}"; then error "SITE_URL 仍是占位符。"; fi
[[ "${SITE_URL}" == http://* || "${SITE_URL}" == https://* ]] || error "SITE_URL 必须以 http:// 或 https:// 开头。"
if is_placeholder "${SITE_DOMAIN}"; then error "SITE_DOMAIN 仍是占位符。"; fi
if is_placeholder "${MYSQL_ROOT_PASSWORD}"; then error "MYSQL_ROOT_PASSWORD 未配置。"; fi
if is_placeholder "${MYSQL_PASSWORD}"; then error "MYSQL_PASSWORD 未配置。"; fi
if is_placeholder "${REDIS_PASSWORD}"; then error "REDIS_PASSWORD 未配置。"; fi

if [ ! -f nginx/conf.d/default.conf ]; then
    error "缺少 nginx/conf.d/default.conf；请从 nginx/conf.d/default.secure.conf.sample 或 default.local.conf.sample 复制，并修改 server_name 与证书路径。"
fi

docker compose config >/dev/null

if [ "${PREFLIGHT_ONLY:-0}" = "1" ]; then
    info "preflight OK"
    exit 0
fi

info "====== 2. 初始化持久化目录结构 ======"
mkdir -p mysql/data mysql/log
mkdir -p redis/data redis/log
mkdir -p xunsearch/data
mkdir -p nginx/log nginx/ssl
mkdir -p php/log php/supervisor/log
mkdir -p html/ctc/storage/cache html/ctc/storage/cache/annotations html/ctc/storage/cache/volt html/ctc/storage/cache/metadata
mkdir -p html/ctc/storage/log html/ctc/storage/tmp html/ctc/storage/upload

touch mysql/data/.gitkeep mysql/log/.gitkeep
touch redis/data/.gitkeep redis/log/.gitkeep xunsearch/data/.gitkeep
touch nginx/log/.gitkeep php/log/.gitkeep php/supervisor/log/.gitkeep

info "====== 3. 修复目录权限 (适配容器内部 UID/GID) ======"

# MySQL/Redis: 999；PHP-FPM: www-data(33)；Nginx master 以 root 写日志。
fix_permissions() {
    local run=("$@")
    "${run[@]}" chown -R 999:999 mysql/data mysql/log redis/data redis/log 2>/dev/null || true
    "${run[@]}" chown -R 33:33 html/ctc/storage 2>/dev/null || true
    "${run[@]}" chmod -R u+rwX,go-rwx mysql/data mysql/log redis/data redis/log 2>/dev/null || true
    "${run[@]}" chmod -R u+rwX,go+rX html/ctc/storage 2>/dev/null || true
    "${run[@]}" chmod -R u+rwX,go+rX nginx/log php/log php/supervisor/log 2>/dev/null || true
}

if [ "$(uname -s)" = "Linux" ]; then
    if [ "${EUID}" -eq 0 ]; then
        fix_permissions
        success "已修复持久化目录属主与权限。"
    elif command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        fix_permissions sudo
        success "已通过 sudo 修复持久化目录属主与权限。"
    else
        warn "当前用户无 sudo 或 sudo 需要密码；未能修复 storage/log 属主，请手动处理，避免使用 777。"
    fi
fi

info "====== 4. 构建并启动 Docker 容器集群 ======"
docker compose build --pull
docker compose up -d

info "====== 5. 等待数据库健康就绪 ======"
RETRIES=30
until docker compose exec -T mysql mysqladmin ping -h localhost --silent >/dev/null 2>&1 || [ "${RETRIES}" -eq 0 ]; do
    echo "等待 MySQL 启动中... (剩余重试 ${RETRIES} 次)"
    sleep 3
    RETRIES=$((RETRIES - 1))
done
if [ "${RETRIES}" -eq 0 ]; then
    error "MySQL 服务启动超时，请检查 mysql/log 及 docker compose logs mysql。"
fi
success "MySQL 服务已就绪。"

info "====== 6. 安装 PHP 依赖、执行迁移与升级 ======"
docker compose exec -T -w /var/www/html/ctc php \
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
success "Composer 依赖安装完成。"

docker compose exec -T -w /var/www/html/ctc php \
    php vendor/bin/phinx migrate -c phinx.php
docker compose exec -T -w /var/www/html/ctc php \
    php console.php upgrade || warn "console.php upgrade 返回非零，请检查日志。"

info "====== 7. 重建全文检索索引 (XunSearch) ======"
docker compose exec -T -w /var/www/html/ctc php \
    php console.php course_index rebuild || warn "课程索引重建失败。"
docker compose exec -T -w /var/www/html/ctc php \
    php console.php article_index rebuild || warn "专栏索引重建失败。"
docker compose exec -T -w /var/www/html/ctc php \
    php console.php question_index rebuild || warn "问答索引重建失败。"

info "====== 8. 健康检查与运行状态 ======"
if ! docker compose exec -T nginx \
    curl -fsS -H "Host: ${SITE_DOMAIN}" http://127.0.0.1/health >/dev/null; then
    error "健康检查失败，请检查 nginx/php 日志。"
fi

docker compose ps

success "========================================================"
success "   🎉 CTC 生产环境已成功部署并启动！"
success "   - Nginx:  80 / 443"
success "   - WS:     8282"
success "   - MySQL:  127.0.0.1:13306 (仅本机/容器网络)"
success "========================================================"
