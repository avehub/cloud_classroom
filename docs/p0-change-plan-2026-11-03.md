# P0 安全变更单（2026-11-03）

## 0. 变更约束与前提

- 检查对象：`115.190.239.244`（`k.gaodekuai.cn` 源站，Ubuntu 24.04.4 / Docker Compose）
- 已确认：
  - 所有管理员均有 SSH 公钥。
  - 22 端口无法按来源 IP 限制。
  - 可接受 Redis/PHP **5–20 秒**中断。
  - 可接受内核/Docker **10–30 分钟**维护窗口。
- 执行原则：
  - 先做无重启、可回滚项；再做秒级窗口项；最后做维护窗口项。
  - 每步单独验证，失败立即回滚，不连续叠加变更。
  - 不在业务高峰做 `apt upgrade`、Docker 重启、内核重启。
- 本变更单尚未执行；执行前需明确“开始”与窗口时间。

---

## 1. 建议时间线

| 时间 | 内容 | 影响 |
|---|---|---|
| T0（现在/低峰） | 快照、备份、P0-01 nft/MPTCP、P0-04 权限收缩、P0-02 fail2ban + SSH key-only | 无业务中断 |
| T0+低峰 5–20 秒 | P0-04 Redis 密码轮换 + PHP/Redis 重建 | 5–20 秒 |
| 维护窗口（02:00–04:00） | P0-03 用户态补丁、Docker/containerd、内核 6.8.0-149.149、重启 | 10–30 分钟 |
| 完成后 30 分钟 | 健康检查、日志、监控观察 | 无 |

---

## 2. 变更前检查（Checklist）

### 2.1 备份与快照

```bash
cd /home/docker/cloud_classroom
./backup.sh
ls -lh backups/<timestamp>/
gzip -t backups/<timestamp>/mysql_all_*.sql.gz
```

- 云控制台创建系统盘/数据盘快照。
- 将最近备份复制到异地（OSS/S3/另一台主机）。
- 记录当前状态：

```bash
git rev-parse HEAD
docker compose ps
docker images --digests
uname -r
sshd -T | grep -E 'permitrootlogin|passwordauthentication|pubkeyauthentication|maxauthtries'
nft list tables
```

### 2.2 访问保障

- 保持当前 SSH 会话不关闭。
- 打开云控制台 VNC/救援终端。
- 另开一个新 SSH 窗口，确认当前公钥可登录。
- 确认所有管理员已完成公钥登录测试。

### 2.3 回滚点

- 云盘快照 ID：`<填写>`
- 最近数据库备份路径：`<填写>`
- 当前 Git HEAD：`<填写>`
- 当前内核：`<填写>`
- 当前 Docker/containerd 版本：`<填写>`

---

## 3. P0-01：CVE-2026-98432

### 3.1 流量预检（不修改）

```bash
timeout 60 tcpdump -ni any 'tcp and (ip[0] & 0x0f) > 5' -c 20
```

- 无包：正常业务不使用 IPv4 options，可加载缓解规则。
- 有包：先确认来源/目的；CIPSO/NetLabel 等场景不得直接套用。

### 3.2 加载 nftables 缓解（无重启）

```bash
cat >/tmp/cve-2026-98432.nft <<'NFT'
table ip cve_2026_98432 {
  chain prerouting {
    type filter hook prerouting priority -300; policy accept;
    meta l4proto tcp ip hdrlength > 5 counter drop
  }
}
NFT

sudo nft -c -f /tmp/cve-2026-98432.nft
sudo nft delete table ip cve_2026_98432 2>/dev/null || true
sudo nft -f /tmp/cve-2026-98432.nft
sudo nft list table ip cve_2026_98432
```

验证：

```bash
curl -fsS https://k.gaodekuai.cn/health
curl -sS -o /dev/null -w '%{http_code}\n' https://k.gaodekuai.cn/course/list
nft list table ip cve_2026_98432
```

回滚：

```bash
sudo nft delete table ip cve_2026_98432
```

说明：
- 只丢弃入站带 IPv4 options 的 TCP 包；正常 TCP options 不受影响。
- 不覆盖容器间流量；对宿主机 DNAT 的公网入口有效。
- 先运行 24 小时观察 `counter` 与业务，再做持久化。
- 持久化建议独立 systemd oneshot，启动时 `nft list table ... || nft -f ...`，不要直接改 `/etc/nftables.conf` 以免与 Docker/UFW 规则冲突。

### 3.3 关闭未使用的 MPTCP（无重启，可逆）

```bash
ss -lnMp   # 确认无 MPTCP listener
sudo sysctl -w net.mptcp.enabled=0
ss -lnMp
```

持久化（确认无影响后）：

```bash
echo 'net.mptcp.enabled = 0' | sudo tee /etc/sysctl.d/99-disable-mptcp.conf
sudo sysctl -p /etc/sysctl.d/99-disable-mptcp.conf
```

回滚：

```bash
sudo sysctl -w net.mptcp.enabled=1
sudo rm -f /etc/sysctl.d/99-disable-mptcp.conf
```

### 3.4 内核升级

- 等 Ubuntu 发布 **`6.8.0-149.149`** 或更高版本；`6.8.0-147` 仍不修复该 CVE。
- 维护窗口执行：

```bash
sudo apt update
sudo apt install linux-image-generic linux-headers-generic
sudo reboot
```

- 重启后 `uname -r` 确认；GRUB 保留旧内核。
- 若已订阅 Ubuntu Pro，可先评估 Livepatch 是否覆盖该 CVE；不要假设一定覆盖。

**影响**：3.2/3.3 无业务中断；3.4 重启窗口 3–8 分钟。

---

## 4. P0-02：SSH 加固（22 无法限制来源）

### 4.1 安装 fail2ban（无重启）

```bash
sudo apt-get install -y fail2ban

sudo tee /etc/fail2ban/jail.d/sshd.local >/dev/null <<'EOF'
[DEFAULT]
bantime = 1h
findtime = 10m
maxretry = 10
bantime.increment = true

[sshd]
enabled = true
port = ssh
backend = systemd
ignoreip = 127.0.0.1/8 ::1
EOF

sudo systemctl enable --now fail2ban
sudo fail2ban-client status sshd
```

- 22 无法按 IP 限制，fail2ban 是主机层主要补充控制。
- 所有管理员已用公钥，正常不会触发密码失败；若误封，使用云 VNC 或：
  ```bash
  sudo fail2ban-client unban <IP>
  ```
- 可把当前管理员常用出口 IP 临时加入 `ignoreip` 作为宽限；动态 IP 不要长期依赖。

### 4.2 SSH key-only（reload，零停机）

先确认 `sshd_config` 包含 drop-in 目录：

```bash
grep -q '^Include /etc/ssh/sshd_config.d' /etc/ssh/sshd_config && echo OK
```

写入 drop-in：

```bash
sudo tee /etc/ssh/sshd_config.d/99-hardening.conf >/dev/null <<'EOF'
PermitRootLogin prohibit-password
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
MaxAuthTries 3
MaxStartups 10:30:60
LoginGraceTime 20
X11Forwarding no
EOF

sudo sshd -t
sudo systemctl reload ssh
```

- `reload` 不断开现有会话；新会话按新配置执行。
- 保留当前会话，另开新会话验证公钥登录，成功后再关闭旧会话。
- 如果所有管理员都用 root，可再加 `AllowUsers root`；否则先不加，避免误锁。
- 22 无法限制来源时，关闭密码登录是最关键的一步；81,755 次 root 密码失败将不再有效。

回滚：

```bash
sudo rm /etc/ssh/sshd_config.d/99-hardening.conf
sudo sshd -t
sudo systemctl reload ssh
```

**影响**：零停机；仅新密码登录被阻断。若公钥登录失败，立即用 VNC 回滚。

---

## 5. P0-04：数据库/Redis 凭据与权限

### 5.1 零停机收缩 MySQL 权限（立即执行）

先确认应用只使用 `ctc` 库，且没有远程 root 活跃连接：

```sql
SELECT user, host, db, command FROM information_schema.processlist WHERE user='root';
SHOW GRANTS FOR 'ctc'@'%';
```

执行：

```sql
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'ctc'@'%';
GRANT ALL PRIVILEGES ON ctc.* TO 'ctc'@'%';
FLUSH PRIVILEGES;
```

确认无远程 root 使用后：

```sql
DROP USER 'root'@'%';
```

验证：

```sql
SHOW GRANTS FOR 'ctc'@'%';
SELECT user, host FROM mysql.user;
```

- 应用仍使用 `ctc` 库，业务无感。
- `ctc` 不再拥有全局权限，不能操作其他库、创建用户、读写文件。
- `root@localhost` 保留，容器内备份/运维不受影响。

### 5.2 MySQL root 密码轮换（无应用停机）

先修改 `backup.sh`：让宿主机 `.env` 的 `MYSQL_ROOT_PASSWORD` 通过 `docker exec -e MYSQL_PWD=...` 传入，而不是依赖容器创建时的旧环境变量。修改后先手动跑一次备份验证。

```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY '<NEW_ROOT_PASSWORD>';
```

更新 `.env` 的 `MYSQL_ROOT_PASSWORD`，验证：

```bash
docker exec -e MYSQL_PWD='<NEW_ROOT_PASSWORD>' ctc-mysql mysql -uroot -e 'SELECT 1'
./backup.sh
```

- 应用使用 `ctc` 用户，不受 root 密码影响。
- MySQL healthcheck 使用 `MYSQL_USER`，不是 root，不受影响。
- 不需要重建 MySQL 容器。

### 5.3 Redis 密码轮换（低峰 5–20 秒）

```bash
# 1) 运行时切换
docker exec -e REDISCLI_AUTH='<OLD_REDIS_PASSWORD>' ctc-redis \
  redis-cli --no-auth-warning CONFIG SET requirepass '<NEW_REDIS_PASSWORD>'

# 2) 更新 .env 的 REDIS_PASSWORD

# 3) 重建 Redis + PHP（healthcheck 和 PHP 使用新密码）
docker compose up -d --no-deps --force-recreate redis php
```

验证：

```bash
docker exec -e REDISCLI_AUTH='<NEW_REDIS_PASSWORD>' ctc-redis redis-cli --no-auth-warning ping
docker compose ps
curl -fsS https://k.gaodekuai.cn/health
```

回滚：

```bash
docker exec -e REDISCLI_AUTH='<NEW_REDIS_PASSWORD>' ctc-redis \
  redis-cli --no-auth-warning CONFIG SET requirepass '<OLD_REDIS_PASSWORD>'
# 恢复 .env 旧密码
docker compose up -d --no-deps --force-recreate redis php
```

- 现有 Redis 连接切换后仍保持认证；新连接需要新密码。
- 必须重建 Redis，否则 healthcheck 仍使用旧密码，容器会变 unhealthy。
- 必须重建 PHP，否则 PHP 进程仍持有旧环境变量。

### 5.4 应用数据库账号密码（后续 P1，不在本次 P0 内）

当前不改 `MYSQL_USER`/`MYSQL_PASSWORD`：
- MySQL healthcheck 使用创建时的 `MYSQL_USER`/`MYSQL_PASSWORD`；
- 只改 `.env` 不重建 MySQL，healthcheck 会失败；
- 重建 MySQL 需要 10–30 秒停机。

通过 5.2/5.3 已打破 root/app/Redis 三者复用；`ctc` 密码仍为旧值但已无全局权限。后续维护窗口再轮换 `ctc` 密码，或切换到 `ctc_app` 并同步更新 healthcheck。

### 5.5 收尾

- `.env` 权限改为 `600`。
- 备份加密并异地保存；配置归档中的 `.env` 必须加密。
- 不要现在轮换 `APP_KEY`，它涉及 VIP 兑换码重新加密。

**影响**：5.1/5.2 无应用停机；5.3 5–20 秒；5.4 后续维护窗口。

---

## 6. P0-03：84 个安全补丁与内核/Docker

### 6.1 维护窗口

- 建议 02:00–04:00（Asia/Shanghai）。
- 预计总时长 10–30 分钟。
- 冻结发布，通知业务方，保留回滚负责人。

### 6.2 用户态安全更新

```bash
sudo apt update
sudo apt-get -s upgrade
needrestart -b
```

执行：

```bash
sudo apt-get -y \
  -o Dpkg::Options::="--force-confdef" \
  -o Dpkg::Options::="--force-confold" \
  upgrade
```

查看需要重启的服务：

```bash
needrestart -b
```

按顺序重启：`rsyslog`/`cron` → `ssh`（优先 reload）→ 其他非关键服务 → `docker`。保留 SSH 会话，优先 `reload`。

### 6.3 Docker/containerd

```bash
sudo apt-get install -y docker-ce docker-ce-cli containerd.io
sudo systemctl restart docker
docker compose up -d
docker compose ps
```

- 预计容器中断 1–3 分钟。
- 可选：先在预发验证 `"live-restore": true`，它能在 Docker daemon 重启时保持容器运行，但不能保证跨版本升级完全不重启容器。

### 6.4 内核

等 `6.8.0-149.149` 进入 `noble-security`：

```bash
sudo apt install linux-image-generic linux-headers-generic
sudo reboot
```

重启后：

```bash
uname -r
docker compose ps
curl -fsS https://k.gaodekuai.cn/health
```

- 预计重启 3–8 分钟。
- GRUB 保留旧内核；不要 `apt autoremove` 删除旧内核。

### 6.5 自动安全更新

```bash
sudo apt-get install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades
```

建议 `Automatic-Reboot "false"`，内核重启仍走维护窗口。

### 6.6 注意

- `apt upgrade` 不会升级容器里的 MySQL 5.7/Redis 5.0/Nginx 1.18；这些是 P1 镜像升级，不在本次 P0。
- 不要把 MySQL 5.7 原地升级到 8.0 当作补丁操作。
- 单机无法做到内核/Docker 绝对零停机；已接受 10–30 分钟窗口。若未来要求零停机，需要临时双机/SLB/数据库复制切换。

---

## 7. 变更后验证清单

### 7.1 基础

```bash
curl -fsS https://k.gaodekuai.cn/health
curl -sS -o /dev/null -w '%{http_code}\n' https://k.gaodekuai.cn/course/list
curl -sS -o /dev/null -w '%{http_code}\n' https://k.gaodekuai.cn/admin/login
docker compose ps
```

### 7.2 安全项

```bash
nft list table ip cve_2026_98432
sysctl net.mptcp.enabled
sshd -T | grep -E 'permitrootlogin|passwordauthentication|pubkeyauthentication|maxauthtries'
fail2ban-client status sshd
uname -r
```

### 7.3 数据库/Redis

```sql
SHOW GRANTS FOR 'ctc'@'%';
SELECT user, host FROM mysql.user;
```

```bash
docker exec -e REDISCLI_AUTH='<NEW_REDIS_PASSWORD>' ctc-redis redis-cli --no-auth-warning ping
```

### 7.4 业务烟测

- 前台首页、课程列表、课程详情。
- 登录/退出、后台登录。
- 播放票据/点播预览。
- Redis 会话是否正常。
- 支付回调日志（不要为此创建真实支付；观察日志或使用沙箱测试单）。
- 定时任务/队列消费者。
- Xunsearch 若已恢复，搜索功能。

### 7.5 观察 30 分钟

- Nginx 5xx、PHP-FPM 错误日志。
- MySQL 连接数、慢查询。
- Redis 连接/内存。
- SSH 失败次数、fail2ban 封禁。
- nft counter 是否只命中异常流量。
- 容器 healthcheck。

---

## 8. 回滚矩阵

| 变更 | 回滚 |
|---|---|
| nftables 缓解 | `nft delete table ip cve_2026_98432` |
| MPTCP 关闭 | `sysctl -w net.mptcp.enabled=1`，删除 sysctl 文件 |
| SSH key-only | 删除 `99-hardening.conf`，`sshd -t && systemctl reload ssh` |
| MySQL 权限收缩 | 如需紧急恢复：`GRANT ALL PRIVILEGES ON *.* TO 'ctc'@'%' WITH GRANT OPTION`（不推荐，仅应急），或从快照恢复 |
| MySQL root 密码 | `ALTER USER 'root'@'localhost' IDENTIFIED BY '<OLD>'`，恢复 `.env` |
| Redis 密码 | `CONFIG SET requirepass '<OLD>'`，恢复 `.env`，重建 Redis+PHP |
| 用户态补丁 | 云盘快照回滚；必要时降级包 |
| Docker/containerd | 降级到旧版本；`docker compose up -d`；保留旧镜像 |
| 内核 | GRUB 选择旧内核重启 |

---

## 9. Go / No-Go

执行前必须全部满足：

- [ ] `backup.sh` 成功且 `gzip -t` 通过。
- [ ] 云盘快照完成。
- [ ] 当前 SSH 会话保持，VNC 可用。
- [ ] 所有管理员公钥登录测试通过。
- [ ] `sshd -t` 通过。
- [ ] `nft -c` 通过。
- [ ] `apt-get -s upgrade` 已审核。
- [ ] 维护窗口已通知业务方。
- [ ] 回滚负责人/联系人明确。
- [ ] 当前没有正在进行的发布或数据库迁移。

满足后按第 1 节时间线执行；任何一步验证失败，立即停止并回滚，不继续后续步骤。
