# P0 T0 执行记录

- 执行时间：2026-11-04 10:49–10:52（服务器时间，Asia/Shanghai）
- 执行对象：`115.190.239.244`（`k.gaodekuai.cn` 源站）
- 执行范围：仅 T0 无中断/可逆项；未执行 Redis 密码轮换、root 密码轮换、补丁升级、Docker/内核重启
- 业务影响：未观察到中断；健康检查、课程列表、后台登录页均正常

## 1. 备份与回滚点

- 应用备份：`/home/docker/cloud_classroom/backups/20261104_104942/`
  - MySQL：`mysql_all_20261104_104942.sql.gz`，gzip 校验通过，非空
  - Redis：`redis_20261104_104942.rdb`
  - 上传文件：`storage_upload_20261104_104942.tar.gz`
  - 配置：`config_20261104_104942.tar.gz`
  - 清单：`manifest.txt`
- 回滚资料：`/root/security-change-20261103/`
  - `pre-state.txt`
  - `sshd_config.bak`、`sshd_config.d.bak`
  - `mysql-grants-before.txt`、`mysql-grants-after.txt`
  - `cve-2026-98432.nft`
- 云盘快照：当前环境无 `aliyun` CLI，未自动创建；需在云控制台补做。

## 2. P0-01 CVE-2026-98432 缓解

- 流量预检：45 秒内捕获到 0 个带 IPv4 options 的 TCP 包。
- 已加载 nftables 表 `ip cve_2026_98432`：
  - `meta l4proto tcp ip hdrlength > 5 counter drop`
  - 当前 counter：0 packets / 0 bytes
- 已关闭 MPTCP：`net.mptcp.enabled = 0`；`ss -lnMp` 无 MPTCP listener。
- 健康检查：`/health` 返回 ok；`/course/list` 200。
- **尚未持久化**：nft 表与 MPTCP sysctl 目前仅运行时生效；在下次重启/补丁窗口前必须持久化，否则重启后失效。

回滚：

```bash
nft delete table ip cve_2026_98432
sysctl -w net.mptcp.enabled=1
```

## 3. P0-04 MySQL 权限收缩

- 删除 `root@'%'`；保留 `root@localhost`。
- `ctc@'%'` 权限从全局 `*.*` 收缩为：
  - `GRANT USAGE ON *.*`
  - `GRANT ALL PRIVILEGES ON ctc.*`
- 远程 root 活跃连接检查：0。
- 验证：
  - `ctc` 用户连接 `ctc` 库成功。
  - `root@localhost` 连接成功。
  - `mysqldump --no-data ctc` 退出码 0。
  - 应用 `/health`、`/course/list`、`/admin/login` 正常。
- 未修改 MySQL 用户密码，未重建 MySQL，未重启容器。

回滚：
- 权限恢复参考 `mysql-grants-before.txt`；紧急恢复可重新授予全局权限，但不推荐。
- 若需恢复 `root@'%'`，需同时评估网络暴露风险。

## 4. P0-02 fail2ban 与 SSH

### fail2ban

- 已安装 `fail2ban 1.0.2-3ubuntu0.1`，服务 `enabled/active`。
- 配置：`/etc/fail2ban/jail.d/sshd.local`
  - `backend = polling`
  - `logpath = /var/log/auth.log`
  - `maxretry = 10`、`findtime = 10m`、`bantime = 1h`、`bantime.increment = true`
  - `ignoreip` 包含 localhost 与当前管理员出口 IP
- 当前 `sshd` jail：4 个恶意 IP 已封禁，`Total failed` 持续增长，说明规则生效。
- 若误封管理员：云 VNC 或 `fail2ban-client unban <IP>`。

### SSH key-only

- 新增 `/etc/ssh/sshd_config.d/00-hardening.conf`（必须使用 `00-` 前缀；`50-cloud-init.conf` 会先匹配，`99-` 会被其覆盖）。
- 生效配置：
  - `permitrootlogin without-password`
  - `passwordauthentication no`
  - `kbdinteractiveauthentication no`
  - `pubkeyauthentication yes`
  - `maxauthtries 3`
  - `maxstartups 10:30:60`
  - `logingracetime 20`
  - `x11forwarding no`
- 验证：
  - 新 SSH 会话使用公钥登录成功。
  - 强制密码登录返回 `Permission denied (publickey)`。
  - 现有会话未中断。

回滚：

```bash
rm /etc/ssh/sshd_config.d/00-hardening.conf
sshd -t
systemctl reload ssh
```

## 5. 未执行项（下一步）

1. P0-04 Redis 密码轮换 + PHP/Redis 重建（需 5–20 秒低峰窗口）。
2. P0-04 MySQL root 密码轮换（需先改 `backup.sh` 使用宿主机 `.env` 密码）。
3. P0-03 用户态补丁、Docker/containerd、内核 `6.8.0-149.149`、重启（维护窗口）。
4. 云盘快照补做。
5. nft/MPTCP 持久化（在任何重启前完成）。
6. `unattended-upgrades` 安装与配置。

## 6. 结论

T0 无中断项已全部完成并通过业务验证。当前仍有 Redis/root 密码复用、补丁滞后和内核 CVE 未修复，需按变更单继续执行后续窗口项。
