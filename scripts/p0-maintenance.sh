#!/usr/bin/env bash
set -euo pipefail
umask 077
STATE=/root/security-change-20261103
COMPOSE=/home/docker/cloud_classroom
LOG=/var/log/ctc-p0-maintenance-$(date +%Y%m%d_%H%M%S).log
exec > >(tee -a "$LOG") 2>&1
echo "START $(date -Is)"
cd "$COMPOSE"
[ -f .env ] || { echo "missing .env"; exit 1; }
[ -f "$STATE/step4-approved" ] || { echo "missing approval marker"; exit 1; }
if [ "${DRY_RUN:-0}" = "1" ]; then echo "DRY_RUN_OK"; exit 0; fi

exec 9>/run/ctc-p0-maintenance.lock
flock -n 9 || { echo "another maintenance is running"; exit 1; }

echo "KERNEL_BEFORE=$(uname -r)"
df -h /
free -m
docker compose ps
curl -fsS --max-time 10 https://k.gaodekuai.cn/health
echo

echo "BACKUP"
./backup.sh
LATEST=$(ls -dt backups/*/ | head -1)
echo "BACKUP_DIR=$LATEST"
gzip -t "$LATEST"/mysql_all_*.sql.gz
test -s "$LATEST"/mysql_all_*.sql.gz

HOLD="docker-ce docker-ce-cli containerd.io linux-image-generic linux-headers-generic linux-generic"
apt-mark hold $HOLD >/dev/null 2>&1 || true
cleanup_hold() { apt-mark unhold $HOLD >/dev/null 2>&1 || true; }
trap cleanup_hold EXIT

echo "APT UPDATE"
apt-get update
echo "APT UPGRADE (userland; Docker/kernel held)"
DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l apt-get -y \
  -o Dpkg::Options::="--force-confdef" \
  -o Dpkg::Options::="--force-confold" \
  upgrade
needrestart -b || true
cleanup_hold
trap - EXIT

echo "DOCKER/CONTAINERD"
DEBIAN_FRONTEND=noninteractive apt-get install -y docker-ce docker-ce-cli containerd.io
docker version
systemctl restart docker
for i in $(seq 1 60); do docker info >/dev/null 2>&1 && break; sleep 2; done
docker info >/dev/null || { echo "docker not ready"; exit 1; }

echo "COMPOSE UP"
docker compose up -d --no-deps php nginx mysql redis

wait_health() {
  local c=$1 max=${2:-60}
  local h="unknown"
  for i in $(seq 1 "$max"); do
    h=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$c" 2>/dev/null || echo missing)
    [ "$h" = "healthy" ] && { echo "$c healthy"; return 0; }
    sleep 2
  done
  echo "$c not healthy: $h"
  return 1
}
wait_health ctc-mysql 60
wait_health ctc-redis 60
wait_health ctc-nginx 60
R="false"
for i in $(seq 1 30); do
  R=$(docker inspect -f '{{.State.Running}}' ctc-php 2>/dev/null || echo false)
  [ "$R" = "true" ] && break
  sleep 2
done
[ "${R:-false}" = "true" ] || { echo "php not running"; exit 1; }

echo "APP CHECK AFTER DOCKER"
curl -fsS --max-time 10 https://k.gaodekuai.cn/health; echo
curl -sS -o /dev/null -w 'course_list_http=%{http_code}\n' --max-time 15 https://k.gaodekuai.cn/course/list

echo "KERNEL INSTALL"
DEBIAN_FRONTEND=noninteractive apt-get install -y linux-image-generic linux-headers-generic
dpkg-query -W -f='${Package} ${Version}\n' linux-image-generic linux-headers-generic
echo "KERNEL_AFTER_INSTALL=$(uname -r)"
if [ -f /var/run/reboot-required ]; then cat /var/run/reboot-required; else echo "reboot-required: none"; fi

echo "APP CHECK BEFORE REBOOT"
curl -fsS --max-time 10 https://k.gaodekuai.cn/health; echo
curl -sS -o /dev/null -w 'course_list_http=%{http_code}\n' --max-time 15 https://k.gaodekuai.cn/course/list

systemctl enable ctc-p0-postcheck.service
systemctl disable ctc-p0-maintenance.timer >/dev/null 2>&1 || true
touch "$STATE/step4-patching-done"
sync
sleep 2
echo "REBOOT $(date -Is)"
systemctl reboot
