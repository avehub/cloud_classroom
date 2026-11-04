# P0 T2/T3 执行与 T4 准备记录

- 执行时间：2026-11-04 11:05–11:09（服务器时间，Asia/Shanghai）
- 执行对象：`115.190.239.244`（`k.gaodekuai.cn` 源站）
- 执行范围：
  - T2：Redis 密码轮换 + Redis/PHP 重建
  - T3：MySQL root/`ctc` 密码轮换 + backup.sh 修正 + MySQL/PHP 重建
  - T4：仅准备（nft/MPTCP 持久化、apt 索引刷新、补丁/内核版本预演）；未安装补丁、未重启
- 业务影响：未观察到中断；健康检查、课程列表、后台登录均正常

## 1. T2：Redis 密码轮换

- 备份 `.env` 到 `/root/security-change-20261103/.env.before-step2`（600）。
- 生成新的 Redis 密码（64 位 hex），保存到 `/root/security-change-20261103/redis-new.pass`（600）。
- 更新 `.env` 的 `REDIS_PASSWORD`。
- 重建 Redis 与 PHP，reload Nginx。
- 验证：
  - 新 Redis 密码 `PONG`。
  - 旧 Redis 密码返回 `NOAUTH Authentication required.`，已失效。
  - Redis `DBSIZE` 重建前 826、重建后 826；AOF 正常，会话数据未丢。
  - `/health` ok、`/course/list` 200、`/admin/login` 200。
  - Redis/PHP 容器 healthy/running。
- 回滚：
  - 恢复 `.env.before-step2`，重建 Redis/PHP。
  - 旧密码在 `redis-old.pass`（600）。
  - 当前 PHP 容器镜像已提交为 `ctc/php:pre-p0-20261104`，可回退。

## 2. T3：MySQL root 与 ctc 密码轮换

- 修改 `backup.sh`：
  - 不再依赖 MySQL 容器创建时的 `MYSQL_ROOT_PASSWORD` 环境变量。
  - 改为 `docker exec -e MYSQL_PWD="$(get_env MYSQL_ROOT_PASSWORD)" ...`，从宿主机 `.env` 读取。
  - `bash -n` 本地/线上均通过；旧密码下完整备份成功。
- 在 `backup.sh` 检查输出中出现了旧 `.env` root 值，因此同时轮换：
  - `root@localhost`
  - `ctc@'%'`
  - 旧值已验证失效：旧 root 登录 exit 1，旧 ctc 登录 exit 1。
- 新密码：
  - 分别生成 64 位 hex，保存到 `/root/security-change-20261103/mysql-new-root.pass`、`mysql-new-ctc.pass`（600）。
  - 更新 `.env` 的 `MYSQL_ROOT_PASSWORD`、`MYSQL_PASSWORD`。
  - `MYSQL_ROOT_PASSWORD` 与 `MYSQL_PASSWORD` 已不相等；`MYSQL_PASSWORD` 与 `REDIS_PASSWORD` 也不相等。
- 重建 MySQL 与 PHP，reload Nginx。
- 验证：
  - 新 root / 新 ctc 登录成功。
  - `root@'%'` 仍不存在；`ctc@'%'` 仍仅 `ctc.*`。
  - `/health` ok、`/course/list` 200、`/admin/login` 200。
  - 新密码下完整 `./backup.sh` 成功，MySQL gzip 校验通过、非空。
  - 容器全部 healthy/running。
- 回滚：
  - `.env.before-step3`（600）。
  - 旧密码在 `mysql-old-root.pass`（600）。
  - 如需恢复旧密码，使用新 root 执行 `ALTER USER ... IDENTIFIED BY '<old>'`，并恢复 `.env`。
- 注意：线上 `backup.sh` 为热修复文件，已通过语法检查和完整备份验证；本地已提交同一修改。下次发布前需同步，避免 `git pull` 冲突。

## 3. T4 准备：补丁/内核

- nftables 缓解已持久化：
  - `/etc/cve-2026-98432.nft`
  - `/etc/systemd/system/cve-2026-98432.service`
  - `enabled/active`，重启后自动加载。
- MPTCP 已持久化：
  - `/etc/sysctl.d/99-disable-mptcp.conf`
  - `net.mptcp.enabled=0`。
- apt 索引已刷新：
  - 可升级 123 个，其中 `noble-security` 84 个。
  - 内核候选：`6.8.0-147.147`；**`6.8.0-149.149` 尚未发布**。
  - Docker 候选：`29.9.3`；containerd 当前 `2.3.4`。
  - 当前关键版本：OpenSSH `9.6p1-3ubuntu13.19`、OpenSSL `3.0.13-0ubuntu3.15`、glibc `2.39-0ubuntu8.8`、sudo `1.9.15p5-3ubuntu5.24.04.2`、systemd `255.4-1ubuntu8.17`、Docker `29.8.0`。
- 预演清单已保存：
  - `/root/security-change-20261103/apt-upgradable.txt`
  - `/root/security-change-20261103/needrestart-before.txt`
  - `/root/security-change-20261103/upgrade-candidates.txt`
  - `/root/security-change-20261103/key-package-versions.txt`
- **未执行**：`apt upgrade`、Docker/containerd 升级、内核安装、重启、`unattended-upgrades` 安装。
- 原因：当前服务器时间约 11:09，属于业务时段；且内核 149 尚未进入仓库。补丁/重启需放到维护窗口。

## 4. 当前状态

- CVE-2026-98432 运行时缓解已生效且已持久化。
- MPTCP 已关闭并持久化。
- SSH 纯密钥、fail2ban 正常。
- MySQL `ctc` 权限已收缩，`root@'%'` 已删除。
- Redis、MySQL root、MySQL ctc 密码均已轮换，旧值失效。
- 84 个安全更新、Docker/containerd、内核补丁仍待维护窗口执行。
- `.env` 权限仍为 664；建议 P1 收紧到 600。

## 5. 下一步

1. 确认维护窗口（建议 02:00–04:00 Asia/Shanghai）。
2. 在窗口内执行：
   - 用户态安全更新 + `needrestart`；
   - Docker/containerd 升级；
   - 内核 `6.8.0-147.147`（当前可用）或等待 `6.8.0-149.149`；
   - 重启；
   - 安装/配置 `unattended-upgrades`。
3. 若必须等 149：可先只做用户态 + Docker，内核 149 发布后单独重启；或接受 147 先修其他内核 CVE，149 再重启一次。
