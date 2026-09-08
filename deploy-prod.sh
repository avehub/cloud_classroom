#!/usr/bin/env bash

# ==============================================================================
# CTC 生产环境一键部署与运维自愈脚本 (Ubuntu 24.04 / docker:docker)
# ==============================================================================

set -euo pipefail

# 颜色输出函数
info()    { echo -e "\033[34m[INFO] $1\033[0m"; }
success() { echo -e "\033[32m[SUCCESS] $1\033[0m"; }
warn()    { echo -e "\033[33m[WARN] $1\033[0m"; }
error()   { echo -e "\033[31m[ERROR] $1\033[0m"; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

info "====== 1. 检查运行环境 ======"

# 检查 docker 和 docker compose 命令
if ! command -v docker &>/dev/null; then
    error "未检测到 Docker，请先安装 Docker！"
fi

if ! docker compose version &>/dev/null; then
    error "未检测到 Docker Compose 插件 (docker compose)，请先安装！"
fi

# 检查 .env 配置文件
if [ ! -f .env ]; then
    if [ -f .env.default ]; then
        warn "未检测到 .env 文件，正从 .env.default 自动生成..."
        cp .env.default .env
        warn "请根据生产环境实际情况编辑修改 .env 文件中的密码与密钥！"
    else
        error "缺失 .env.default 模板，无法继续！"
    fi
fi

info "====== 2. 初始化持久化目录结构 ======"
mkdir -p mysql/data mysql/log
mkdir -p redis/data redis/log
mkdir -p xunsearch/data
mkdir -p nginx/log nginx/ssl
mkdir -p php/log php/supervisor/log
mkdir -p html/ctc/storage/cache html/ctc/storage/cache/annotations html/ctc/storage/cache/volt html/ctc/storage/cache/metadata
mkdir -p html/ctc/storage/log html/ctc/storage/tmp html/ctc/storage/upload

# 添加 .gitkeep 保持目录结构
touch mysql/data/.gitkeep mysql/log/.gitkeep
touch redis/data/.gitkeep redis/log/.gitkeep xunsearch/data/.gitkeep
touch nginx/log/.gitkeep php/log/.gitkeep php/supervisor/log/.gitkeep

info "====== 3. 修复目录权限 (适配容器内部 UID/GID) ======"

# Linux 下容器默认 UID:
# MySQL 容器内用户 UID: 999
# Redis 容器内用户 UID: 999 (或 root)
# Web (PHP-FPM/Nginx) UID: 33 (www-data)
if [ "$(uname -s)" = "Linux" ]; then
    if [ "${EUID}" -eq 0 ]; then
        chown -R 999:999 mysql/data mysql/log || true
        chown -R 999:999 redis/data redis/log || true
        chmod -R 777 redis/log || true
        chown -R 33:33 html/ctc/storage || true
        chmod -R 777 html/ctc/storage || true
        chmod -R 777 nginx/log || true
        success "已通过 root 权限修复数据及日志目录属主！"
    else
        warn "当前非 root 用户执行，尝试使用 sudo 修正容器持久化目录权限..."
        if command -v sudo &>/dev/null; then
            sudo chown -R 999:999 mysql/data mysql/log 2>/dev/null || true
            sudo chown -R 999:999 redis/data redis/log 2>/dev/null || true
            sudo chmod -R 777 redis/log 2>/dev/null || true
            sudo chown -R 33:33 html/ctc/storage 2>/dev/null || true
            sudo chmod -R 777 html/ctc/storage 2>/dev/null || true
            sudo chmod -R 777 nginx/log 2>/dev/null || true
            success "数据目录权限已修复！"
        else
            warn "未能获取 sudo 权限，建议执行：sudo chmod -R 777 redis/log html/ctc/storage"
        fi
    fi
fi

info "====== 4. 构建并启动 Docker 容器集群 ======"
docker compose build --pull
docker compose up -d

info "====== 5. 等待数据库健康就绪 ======"
RETRIES=30
until docker compose exec -T mysql mysqladmin ping -h localhost --silent &>/dev/null || [ $RETRIES -eq 0 ]; do
    echo "等待 MySQL 启动中... (剩余重试 $RETRIES 次)"
    sleep 3
    RETRIES=$((RETRIES-1))
done

if [ $RETRIES -eq 0 ]; then
    error "MySQL 服务启动超时，请检查 mysql/log 及 docker compose logs mysql！"
fi
success "MySQL 服务已就绪！"

info "====== 6. 检查并自动安装 PHP 依赖与数据库迁移 ======"
# 检查 vendor 目录是否存在，若不存在则自动执行 composer install
if [ ! -d "html/ctc/vendor" ] || [ ! -f "html/ctc/vendor/autoload.php" ]; then
    info "未检测到 vendor 依赖，正在容器内配置国内镜像并安装 Composer 依赖..."
    docker compose exec -T -w /var/www/html/ctc php composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/ || true
    docker compose exec -T -w /var/www/html/ctc php composer install --no-dev --prefer-dist --optimize-autoloader
    success "Composer 依赖安装完成！"
else
    docker compose exec -T -w /var/www/html/ctc php composer dump-autoload --optimize || true
fi

info "执行数据库结构迁移 (Phinx)..."
docker compose exec -T -w /var/www/html/ctc php php vendor/bin/phinx migrate -c phinx.php || true

info "执行系统升级与默认配置同步..."
docker compose exec -T -w /var/www/html/ctc php php console.php upgrade || true

info "====== 7. 重建全文检索索引 (XunSearch) ======"
docker compose exec -T --user www-data php bash -c "cd /var/www/html/ctc && php console.php course_index rebuild" || true
docker compose exec -T --user www-data php bash -c "cd /var/www/html/ctc && php console.php article_index rebuild" || true
docker compose exec -T --user www-data php bash -c "cd /var/www/html/ctc && php console.php question_index rebuild" || true

info "====== 8. 验证服务运行状态 ======"
docker compose ps

success "========================================================"
success "   🎉 CTC 生产环境已成功部署并启动！"
success "   - Nginx:  80 / 443"
success "   - WS:     8282"
success "   - MySQL:  127.0.0.1:13306 (仅本机/容器网络)"
success "========================================================"
