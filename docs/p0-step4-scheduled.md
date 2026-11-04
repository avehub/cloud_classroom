# P0 Step 4 已排期：2026-11-05 02:00 CST

## 1. 排期

- 方案：**B**
- 服务器时间：**2026-11-05 02:00（Asia/Shanghai，CST）**
- 纽约时间：**2026-11-04 13:00（EST）**
- 触发单元：`ctc-p0-maintenance.timer` → `ctc-p0-maintenance.service`
- 当前状态：
  - timer：`enabled/active`，`14h left`
  - postcheck：`disabled/inactive`（由维护脚本在重启前启用）
  - 当前业务健康：`/health` ok，容器全部 healthy/running

## 2. 执行内容

`/usr/local/sbin/ctc-p0-maintenance.sh`：

1. 检查审批标记 `/root/security-change-20261103/step4-approved`。
2. 执行 `./backup.sh`，校验 MySQL gzip 非空。
3. 暂 hold Docker/containerd/kernel 包，执行用户态 `apt-get upgrade`。
4. `needrestart -b` 记录需重启服务；不自动重启全部服务，随后统一重启。
5. 升级 Docker/containerd 到当前仓库候选（当前 `29.9.3` / `2.4.2`），重启 Docker。
6. 启动 `php/nginx/mysql/redis`，等待 healthcheck，检查 `/health` 与 `/course/list`。
7. 安装 `linux-image-generic linux-headers-generic`：
   - 若窗口前 `6.8.0-149.149` 已进入仓库，则安装 149；
   - 否则安装当前候选 `6.8.0-147.147`，CVE-2026-98432 仍由 nftables 缓解。
8. 再次检查 `/health` 与 `/course/list`。
9. 启用 `ctc-p0-postcheck.service`，写入 `step4-patching-done`，重启。

`/usr/local/sbin/ctc-p0-postcheck.sh`（重启后自动运行）：

1. 等待 Docker 就绪。
2. 启动 `php/nginx/mysql/redis`。
3. 等待 MySQL/Redis/Nginx healthy。
4. 检查 `/health` 与 `/course/list`。
5. 成功写 `step4-postcheck-ok` 并禁用 postcheck；失败写 `step4-postcheck-failed` 并保留服务。

## 3. 日志与标记

```text
/var/log/ctc-p0-maintenance-*.log
/var/log/ctc-p0-postcheck-*.log

/root/security-change-20261103/step4-approved
/root/security-change-20261103/step4-patching-done
/root/security-change-20261103/step4-postcheck-ok
/root/security-change-20261103/step4-postcheck-failed
```

## 4. 取消 / 中止

窗口前取消：

```bash
systemctl disable --now ctc-p0-maintenance.timer
systemctl list-timers --all | grep ctc-p0 || true
```

如果已经进入 `apt`/`dpkg` 阶段，不要中途停止服务或强杀进程；等待脚本完成或失败退出，再按日志处理。

## 5. 窗口后检查

```bash
systemctl status ctc-p0-maintenance.service --no-pager
systemctl status ctc-p0-postcheck.service --no-pager
systemctl list-timers --all | grep ctc-p0 || true
uname -r
docker compose -f /home/docker/cloud_classroom/docker-compose.yml ps
curl -fsS https://k.gaodekuai.cn/health
```

## 6. 回滚

- 云盘快照：按快照回滚系统盘。
- 内核：GRUB 选择旧内核 `6.8.0-139-generic` 启动。
- Docker/containerd：降级到 `29.8.0` / `2.3.4`，重启 Docker，`docker compose up -d --no-deps php nginx mysql redis`。
- 数据库/Redis/配置：使用 `/home/docker/cloud_classroom/backups/20261104_*` 或维护脚本新建的备份。
- 若应用异常但容器正常：先看 `/var/log/ctc-p0-maintenance-*.log`、`/var/log/ctc-p0-postcheck-*.log`、`docker logs ctc-php`，不要直接回滚数据库。

## 7. 说明

- 当前未重启、未安装补丁；只完成排期与脚本准备。
- Timer 使用 `Persistent=false`：若 02:00 时主机不可用，任务不会在恢复后自动补跑，需要重新排期。
- 脚本只启动 `php/nginx/mysql/redis`，不会顺带启动已退出的 `ctc-xunsearch`。
- 维护脚本与 postcheck 脚本副本已提交到仓库 `scripts/` 目录。
