#!/usr/bin/env bash

# ==============================================================================
# CTC 生产环境持久化数据备份与发版回退点归档脚本
#
# 覆盖：MySQL、Redis、上传文件、.env、compose 与 nginx/php 配置、版本清单
# 要求：在 docker-compose.yml 所在目录执行；容器名默认 ctc-mysql/ctc-redis/ctc-php
# ==============================================================================

set -euo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

BACKUP_DIR="${SCRIPT_DIR}/backups"
DATE_STR="$(date +%Y%m%d_%H%M%S)"
TARGET_DIR="${BACKUP_DIR}/${DATE_STR}"
KEEP_DAYS="${KEEP_DAYS:-14}"

MYSQL_CONTAINER="${MYSQL_CONTAINER:-ctc-mysql}"
REDIS_CONTAINER="${REDIS_CONTAINER:-ctc-redis}"
PHP_CONTAINER="${PHP_CONTAINER:-ctc-php}"

if [ ! -f "${SCRIPT_DIR}/.env" ]; then
    echo "[ERROR] .env not found: ${SCRIPT_DIR}/.env"
    exit 1
fi

mkdir -p "${TARGET_DIR}"

get_env() {
    local key="$1"
    awk -F= -v k="${key}" '$1 == k { sub(/^[^=]*=/, ""); gsub(/^"|"$/, ""); print; exit }' "${SCRIPT_DIR}/.env"
}

container_running() {
    local name="$1"
    docker inspect "${name}" >/dev/null 2>&1 || return 1
    [ "$(docker inspect -f '{{.State.Running}}' "${name}")" = "true" ]
}

echo "[INFO] 开始备份 CTC 数据至: ${TARGET_DIR}"

# ------------------------------------------------------------------------------
# 1. MySQL 全库备份
# ------------------------------------------------------------------------------
echo "[INFO] 1. 正在导出 MySQL 数据库..."
if container_running "${MYSQL_CONTAINER}"; then
    MYSQL_DUMP="${TARGET_DIR}/mysql_all_${DATE_STR}.sql.gz"

    docker exec -i "${MYSQL_CONTAINER}" sh -c '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump \
            -uroot \
            --single-transaction \
            --quick \
            --routines \
            --triggers \
            --events \
            --hex-blob \
            --default-character-set=utf8mb4 \
            --all-databases
    ' | gzip > "${MYSQL_DUMP}"

    test -s "${MYSQL_DUMP}" || { echo "[ERROR] MySQL dump empty"; exit 1; }
    gzip -t "${MYSQL_DUMP}"
    echo "[SUCCESS] MySQL 备份完成: $(du -h "${MYSQL_DUMP}" | awk '{print $1}')"
else
    echo "[ERROR] ${MYSQL_CONTAINER} 未运行，停止备份"
    exit 1
fi

# ------------------------------------------------------------------------------
# 2. Redis 快照
# ------------------------------------------------------------------------------
echo "[INFO] 2. 正在生成 Redis 快照..."
if container_running "${REDIS_CONTAINER}"; then
    REDIS_PASSWORD="$(get_env REDIS_PASSWORD)"

    redis_cli() {
        docker exec -e REDISCLI_AUTH="${REDIS_PASSWORD}" "${REDIS_CONTAINER}" \
            redis-cli --no-auth-warning "$@"
    }

    redis_cli BGSAVE >/dev/null

    for _ in $(seq 1 30); do
        progress="$(redis_cli INFO persistence 2>/dev/null | tr -d '\r' | awk -F: '/rdb_bgsave_in_progress/{print $2}')"
        [ "${progress:-1}" = "0" ] && break
        sleep 1
    done

    docker cp "${REDIS_CONTAINER}:/data/dump.rdb" "${TARGET_DIR}/redis_${DATE_STR}.rdb"
    test -s "${TARGET_DIR}/redis_${DATE_STR}.rdb"
    echo "[SUCCESS] Redis 快照完成"
else
    echo "[WARN] ${REDIS_CONTAINER} 未运行，跳过 Redis 快照"
fi

# ------------------------------------------------------------------------------
# 3. 上传文件
# ------------------------------------------------------------------------------
echo "[INFO] 3. 正在打包上传文件..."
if [ -d "${SCRIPT_DIR}/html/ctc/storage/upload" ]; then
    tar -czf "${TARGET_DIR}/storage_upload_${DATE_STR}.tar.gz" \
        -C "${SCRIPT_DIR}/html/ctc/storage" upload
    echo "[SUCCESS] 上传文件备份完成"
else
    echo "[WARN] upload 目录不存在，跳过"
fi

# ------------------------------------------------------------------------------
# 4. 配置归档
# ------------------------------------------------------------------------------
echo "[INFO] 4. 正在归档配置..."
CONFIG_FILES=()
for path in .env docker-compose.yml docker-compose.php82.yml nginx/conf.d php/conf.d php/supervisor/conf.d redis/redis.conf; do
    [ -e "${SCRIPT_DIR}/${path}" ] && CONFIG_FILES+=("${path}")
done

if [ ${#CONFIG_FILES[@]} -gt 0 ]; then
    tar -czf "${TARGET_DIR}/config_${DATE_STR}.tar.gz" -C "${SCRIPT_DIR}" "${CONFIG_FILES[@]}"
    echo "[SUCCESS] 配置归档完成"
fi

# ------------------------------------------------------------------------------
# 5. 发布清单
# ------------------------------------------------------------------------------
echo "[INFO] 5. 正在生成发布清单..."
MIGRATION_VERSION="unknown"
if container_running "${MYSQL_CONTAINER}"; then
    MIGRATION_VERSION="$(docker exec -i "${MYSQL_CONTAINER}" sh -c '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e \
        "SELECT MAX(version) FROM \`${MYSQL_DATABASE}\`.kg_migration" 2>/dev/null
    ' || echo unknown)"
fi

PHP_VERSION="unknown"
if container_running "${PHP_CONTAINER}"; then
    PHP_VERSION="$(docker exec "${PHP_CONTAINER}" php -v 2>/dev/null | head -1 || echo unknown)"
fi

{
    echo "backup_time=$(date -Iseconds)"
    echo "host=$(hostname)"
    echo "script_dir=${SCRIPT_DIR}"
    echo "git_commit=$(git -C "${SCRIPT_DIR}" rev-parse HEAD 2>/dev/null || echo unknown)"
    echo "git_branch=$(git -C "${SCRIPT_DIR}" branch --show-current 2>/dev/null || echo unknown)"
    echo "php=${PHP_VERSION}"
    echo "max_migration=${MIGRATION_VERSION}"
    echo "mysql_container=${MYSQL_CONTAINER}"
    echo "redis_container=${REDIS_CONTAINER}"
} > "${TARGET_DIR}/manifest.txt"

chmod -R u+rwX,go-rwx "${TARGET_DIR}"

# ------------------------------------------------------------------------------
# 6. 清理旧备份
# ------------------------------------------------------------------------------
echo "[INFO] 6. 清理 ${KEEP_DAYS} 天前的旧备份..."
find "${BACKUP_DIR}" -mindepth 1 -maxdepth 1 -type d -mtime +"${KEEP_DAYS}" -exec rm -rf {} +

echo "[SUCCESS] 备份完成: ${TARGET_DIR}"
ls -lh "${TARGET_DIR}"
