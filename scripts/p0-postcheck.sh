#!/usr/bin/env bash
set -u
STATE=/root/security-change-20261103
COMPOSE=/home/docker/cloud_classroom
LOG=/var/log/ctc-p0-postcheck-$(date +%Y%m%d_%H%M%S).log
exec > >(tee -a "$LOG") 2>&1
echo "POSTCHECK START $(date -Is)"
sleep 30
cd "$COMPOSE" || { echo "missing compose dir"; touch "$STATE/step4-postcheck-failed"; exit 1; }

for i in $(seq 1 60); do docker info >/dev/null 2>&1 && break; sleep 5; done
if ! docker info >/dev/null 2>&1; then echo "docker not ready"; touch "$STATE/step4-postcheck-failed"; exit 1; fi

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
wait_health ctc-mysql 90 || { touch "$STATE/step4-postcheck-failed"; exit 1; }
wait_health ctc-redis 60 || { touch "$STATE/step4-postcheck-failed"; exit 1; }
wait_health ctc-nginx 60 || { touch "$STATE/step4-postcheck-failed"; exit 1; }

OK=0
for i in $(seq 1 30); do
  if curl -fsS --max-time 10 https://k.gaodekuai.cn/health >/dev/null 2>&1; then OK=1; break; fi
  sleep 5
done
CODE=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 https://k.gaodekuai.cn/course/list || echo 000)
echo "health_ok=$OK course_list_http=$CODE kernel=$(uname -r)"
if [ "$OK" = "1" ] && [ "$CODE" = "200" ]; then
  touch "$STATE/step4-postcheck-ok"
  systemctl disable ctc-p0-postcheck.service >/dev/null 2>&1 || true
  echo "POSTCHECK OK"
else
  touch "$STATE/step4-postcheck-failed"
  echo "POSTCHECK FAILED"
  exit 1
fi
