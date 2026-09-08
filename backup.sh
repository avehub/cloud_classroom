#!/usr/bin/env bash

# ==============================================================================
# CTC 生产环境本地持久化数据定时备份与归档脚本
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SCRIPT_DIR}/backups"
DATE_STR="$(date +%Y%m%d_%H%M%S)"
TARGET_DIR="${BACKUP_DIR}/${DATE_STR}"
KEEP_DAYS=14 # 保留天数

mkdir -p "${TARGET_DIR}"

echo "[INFO] 开始备份 CTC 数据至: ${TARGET_DIR} ..."

# 1. 备份 MySQL 数据库
echo "[INFO] 1. 正在导出 MySQL 数据库..."
if docker compose ps | grep -q ctc-mysql; then
    docker compose exec -T mysql mysqldump -u root -p"$(grep MYSQL_ROOT_PASSWORD .env | cut -d '=' -f2 | tr -d ' "\r')" --all-databases | gzip > "${TARGET_DIR}/mysql_all_${DATE_STR}.sql.gz"
    echo "[SUCCESS] MySQL 数据备份完成！"
else
    echo "[WARN] ctc-mysql 容器未在运行，跳过数据库 dump。"
fi

# 2. 备份用户上传的静态资源与 Storage
echo "[INFO] 2. 正在打包上传文件 (Storage/Upload)..."
if [ -d "${SCRIPT_DIR}/html/ctc/storage/upload" ]; then
    tar -czf "${TARGET_DIR}/storage_upload_${DATE_STR}.tar.gz" -C "${SCRIPT_DIR}/html/ctc/storage" upload
    echo "[SUCCESS] 上传文件备份完成！"
fi

# 3. 备份生产环境配置文件
echo "[INFO] 3. 正在归档环境配置..."
cp "${SCRIPT_DIR}/.env" "${TARGET_DIR}/.env.bak" 2>/dev/null || true
cp "${SCRIPT_DIR}/docker-compose.yml" "${TARGET_DIR}/docker-compose.yml.bak" 2>/dev/null || true

# 4. 清理旧备份 (保留最近 KEEP_DAYS 天)
echo "[INFO] 4. 清理 ${KEEP_DAYS} 天前的旧备份..."
find "${BACKUP_DIR}" -mindepth 1 -maxdepth 1 -type d -mtime +${KEEP_DAYS} -exec rm -rf {} +

echo "[SUCCESS] 备份任务全部完成！归档路径: ${TARGET_DIR}"
