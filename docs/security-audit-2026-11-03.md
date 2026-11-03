# 生产服务器安全检查报告

- **报告日期**：2026-11-03（服务器时间 UTC+8）
- **检查对象**：`115.190.239.244`（`k.gaodekuai.cn` 源站，主机名 `gdk-cc`）
- **系统环境**：Ubuntu 24.04.4 LTS / kernel 6.8.0-139-generic / Docker Compose
- **检查方式**：只读 SSH、HTTP/TLS 探测、容器运行时检查、代码与依赖静态分析
- **检查性质**：**仅检查，未修复、未重启、未修改生产配置**
- **总体结论**：存在 **4 项 Critical、8 项 High、9 项 Medium**。其中 CVE-2026-98432 在 Ubuntu 上尚无已发布修复内核；SSH 与补丁面、数据库凭据与权限、容器栈 EOL 是最需要优先处理的风险。

---

## 1. 执行摘要

### 1.1 最需要立即处理的事项

1. **CVE-2026-98432 未修复、未缓解**：当前内核 `6.8.0-139` 受影响；Ubuntu 24.04 状态为 “Vulnerable, work in progress in 6.8.0-149.149”，截至本报告日期没有已发布修复内核。主机未加载 nftables 缓解规则，且 `net.mptcp.enabled=1`。
2. **SSH 暴露面过大**：`PermitRootLogin yes` + `PasswordAuthentication yes`，22 端口对公网开放，未安装 fail2ban，UFW 未启用。认证日志中累计 **81,755 次 root 密码失败**，并有 **3 次 root 密码登录成功**（来源为管理员常用 IP）。
3. **补丁严重滞后**：隔离刷新 apt 索引后模拟升级，发现 **123 个可升级包，其中 84 个来自 `noble-security`**；未安装 `unattended-upgrades`。内核、OpenSSH、OpenSSL、glibc、sudo、systemd、Docker、containerd 等均有安全更新待装。
4. **数据库/Redis 凭据复用且权限过大**：`MYSQL_ROOT_PASSWORD`、`MYSQL_PASSWORD`、`REDIS_PASSWORD` 三者相同；MySQL 的 `ctc@'%'` 拥有 `ALL PRIVILEGES ON *.* WITH GRANT OPTION`，`root@'%'` 也拥有全局权限。任一凭据泄露都会导致数据库与缓存整体失守。

### 1.2 关键数字

| 指标 | 结果 |
|---|---|
| 待安装安全更新 | 84（总可升级 123） |
| root 密码失败次数 | 81,755 |
| 全部 SSH 密码失败次数 | 128,030 |
| 成功 root 密码登录 | 3 |
| Nginx 访问日志中 `.env` 探测 | 11,638 |
| Nginx 访问日志中 `.git` 探测 | 2,814 |
| 运行中容器 | 4（`ctc-xunsearch` 已退出 12 天） |
| 生产 Git HEAD | `4f64bdc`（比本地 `c20ae6e` 落后 1 个提交） |

### 1.3 已确认的良好实践

- TLS 1.2/1.3 可用，证书为 Let's Encrypt，有效期至 **2027-01-12**；`acme.sh` 已配置每 6 小时检查续期，并登记了 `docker exec ctc-nginx nginx -s reload`。
- Nginx `server_tokens off`，PHP `expose_php=Off`。
- `.env`、`.git`、`/storage/upload/` 目录列表均不能通过 Web 直接读取（404/403）。
- Docker socket 未挂载进业务容器；AppArmor 已启用；容器未使用 `privileged`，`cap_add` 为空。
- MySQL 3306、Redis 6379 在宿主机仅绑定 `127.0.0.1`；外部扫描 13306/6379/8282 均为 filtered。
- 未发现明显入侵成功迹象：认证日志中的成功登录均来自管理员常用 IP；但本次未做完整取证，不能据此排除入侵。

---

## 2. 检查范围与限制

### 2.1 已覆盖

- 宿主机 OS、内核、SSH、防火墙、补丁、账号、计划任务、证书续期。
- Docker Engine / Compose、容器镜像、容器运行时安全选项、端口发布。
- Nginx、PHP-FPM、MySQL、Redis、Xunsearch 的版本与关键安全配置。
- 应用层与本次问题相关的代码路径：客户端 IP 获取、支付回调、后台登录、上传目录。
- `composer.lock` 依赖的 OSV 漏洞匹配；前端关键库版本。

### 2.2 未覆盖 / 限制

- **未做认证后的应用渗透测试**：未测试 SQL 注入、越权/IDOR、文件上传绕过、支付业务逻辑、API 鉴权等。
- **未做容器镜像 CVE 扫描**：未运行 Trivy/Grype 等镜像扫描；仅检查了镜像版本与 EOL 状态。
- **`composer audit` 未完成**：容器内 Composer 使用腾讯镜像，返回 HTTP 567；改用 OSV 对 `composer.lock` 做匹配，结果见 P2-05。
- **未做密码强度破解**：无法判断 root、数据库、Redis 密码的真实熵；仅确认了复用关系。
- **云安全组/WAF 规则不可见**：只能从外部做有限端口探测，无法确认云侧 ACL 是否长期正确。
- **`47.95.3.78` 未审计**：该地址承载 `gaodekuai.cn`/`www.gaodekuai.cn`，SSH 返回 `Permission denied`，且从响应看是另一套站点；若它也是生产入口，应单独审计。
- **未做完整日志取证**：仅统计了 `auth.log` 与 Nginx access log，未对全量日志、进程、文件完整性做入侵排查。

---

## 3. CVE-2026-98432 专项

### 3.1 漏洞要点

- 类型：Linux 内核 IPv4 TCP 连接建立路径中的 **use-after-free**。
- 触发条件：向监听中的 IPv4 TCP 端口发送携带 IPv4 options 的 SYN；客户端重传 SYN 与另一 CPU 构建 SYN-ACK 并发时可触发。
- 影响：CVSS 9.8（Critical），网络可达、无需权限、无需交互；可导致内核内存破坏。公开分析提到在启用 MPTCP 监听时可达 root 命令执行。
- 影响范围：Linux 4.4 及以上；IPv6 主问题不受影响。
- 上游修复：`e3f33b0a1d89 ("ipv4: free inet_opt and ireq_opt after an RCU grace period")`。

### 3.2 本机状态（2026-11-03）

| 检查项 | 结果 | 判断 |
|---|---|---|
| 内核 | `6.8.0-139-generic` | 受影响 |
| Ubuntu 24.04 状态 | `Vulnerable, work in progress in 6.8.0-149.149` | **无已发布修复** |
| 可升级内核 | `6.8.0-147.147` | 仍低于修复目标 6.8.0-149.149 |
| `net.mptcp.enabled` | `1` | 已启用 |
| `net.ipv4.tcp_migrate_req` | `0` | 默认关闭 |
| MPTCP 监听 | `ss -lnMp` 为空 | 未发现 MPTCP listener |
| nftables 缓解表 | `nft list tables` 无 `cve_2026_98432` | **未加载** |
| 外部暴露 | 22/80/443 开放 | IPv4 TCP 可达 |

### 3.3 结论

1. **当前主机属于受影响状态**。虽然未发现 MPTCP listener，公开演示的“直接 root RCE”路径可能不成立，但基础 UAF 仍可通过普通 IPv4 TCP options 触发，不能视为安全。
2. **AlmaLinux 的修复版本不适用于 Ubuntu**。AlmaLinux 的 `kernel-4.18.0-553.182.1.el8_10`、`5.14.0-687.64.1.el9_8`、`6.12.0-211.72.1.el10_2` 仅用于 AlmaLinux；Ubuntu 需等待 `6.8.0-149.149` 或更高版本。
3. **在 Ubuntu 修复内核发布前，应先做运行时缓解**：
   - 用 nftables 丢弃入站携带 IPv4 options 的 TCP 包（AlmaLinux/Ubuntu CVE 页均给出该思路）。
   - 若业务不使用 MPTCP，关闭 `net.mptcp.enabled` 并持久化。
   - 缓解规则不覆盖容器与容器之间的流量；对经宿主机 DNAT 的公网入口有效，但仍应尽快升级内核。
4. **不要仅升级到 6.8.0-147 就认为已修复**；它仍低于 Ubuntu 的修复目标版本。

> 建议的缓解命令见第 5 节 P0-01。本次未执行。

---

## 4. 系统性风险清单

### 4.1 Critical（P0）

#### P0-01 CVE-2026-98432：内核受影响，且无修复/无缓解

- **证据**：`uname -r` = `6.8.0-139-generic`；Ubuntu CVE 页 noble 状态为 “Vulnerable, work in progress in 6.8.0-149.149”；`nft list tables` 无 CVE 表；`net.mptcp.enabled=1`。
- **影响**：远程 IPv4 TCP 可达即可触发内核 UAF；CVSS 9.8。即使 MPTCP RCE 路径当前不成立，仍有内核崩溃/内存破坏风险。
- **建议**：立即加载 nftables 缓解；关闭未使用的 MPTCP；跟踪 Ubuntu `6.8.0-149.149`，发布后在维护窗口升级并重启；升级后 `uname -r` 复核。

#### P0-02 SSH：root 密码登录 + 公网 22 + 无 fail2ban

- **证据**：`sshd -T` 显示 `permitrootlogin yes`、`passwordauthentication yes`、`maxauthtries 6`；`ufw status` 为 `inactive`；`fail2ban` 未安装；`auth.log` 中 root 密码失败 81,755 次，成功密码登录 3 次。
- **影响**：互联网上的持续暴力破解；一旦 root 密码强度不足或与其他系统复用，直接获得 root。3 次成功密码登录说明密码通道确实在使用，不能简单假设“只用密钥”。
- **建议**：改为 `PermitRootLogin prohibit-password`、`PasswordAuthentication no`；确认所有管理员已配置密钥；在云安全组限制 22 到办公/VPN IP；安装 fail2ban 或云 WAF 暴力破解防护；启用 UFW 默认拒绝（保留 80/443/必要管理端口）。

#### P0-03 84 个安全更新未安装，且无自动更新

- **证据**：隔离 apt 索引刷新后 `apt-get -s upgrade` 显示 123 个可升级包，其中 84 个来自 `noble-security`；`unattended-upgrades` 未安装；`/var/lib/apt/lists` 最后更新为 2026-09-07。
- **典型待升级**：`linux-image-generic 6.8.0-139 → 6.8.0-147`、`openssh-server .19 → .20`、`openssl 3.0.13-0ubuntu3.15 → .16`、`libc6 8.8 → 8.9`、`sudo .2 → .4`、`systemd .17 → .18`、`docker-ce 29.8.0 → 29.9.2`、`containerd.io 2.3.4 → 2.4.2`、`git`、`curl`、`libxml2` 等。
- **影响**：大量已知漏洞长期未修；内核/SSH/OpenSSL/glibc 都是高价值攻击面。
- **建议**：先在维护窗口做快照/备份，执行 `apt update && apt upgrade`，重启；安装并启用 `unattended-upgrades`（至少安全更新）；对内核与 Docker 的升级单独安排回归。

#### P0-04 数据库/Redis 凭据复用 + 数据库账号权限过大

- **证据**：
  - `MYSQL_ROOT_PASSWORD`、`MYSQL_PASSWORD`、`REDIS_PASSWORD` 三者值相同（仅比较相等性，未读取/输出明文）。
  - `SHOW GRANTS FOR ctc@'%'` 返回 `GRANT ALL PRIVILEGES ON *.* TO 'ctc'@'%' WITH GRANT OPTION` 以及 `GRANT ALL PRIVILEGES ON ctc.* ...`。
  - `SHOW GRANTS FOR root@'%'` 返回 `GRANT ALL PRIVILEGES ON *.* ... WITH GRANT OPTION`。
  - MySQL `bind_address=*`，`local_infile=1`，`require_secure_transport=0`。
- **影响**：应用数据库账号可读写所有库、创建用户、授权、读写文件；任一 `.env`/备份/容器信息泄露都会同时暴露 MySQL root 与 Redis。SQL 注入或应用 RCE 的影响被放大到全库。
- **建议**：为 root、应用、Redis 分别生成高强度随机密码；撤销 `ctc@'%'` 的全局权限，仅保留 `ctc.*` 所需的最小权限；删除或限制 `root@'%'`；关闭 `local_infile`；将 3306/6379 的访问限制在必要容器；升级 MySQL/Redis 后使用更强认证。

### 4.2 High（P1）

#### P1-01 PHP-FPM 错误显示开启、错误日志关闭

- **证据**：`php-fpm -i` 显示 `display_errors=On`、`display_startup_errors=On`、`log_errors=Off`、`error_reporting` 无显式值、`open_basedir` 为空、`disable_functions` 为空、`allow_url_fopen=On`。
- **影响**：未捕获异常/启动错误可能把路径、SQL、配置信息直接返回给用户；同时错误没有写入日志，故障与攻击排查困难。若发生 RCE，`open_basedir`/`disable_functions` 为空会扩大横向影响。
- **建议**：生产设置 `display_errors=Off`、`display_startup_errors=Off`、`log_errors=On`、`error_log=/var/log/php-fpm/error.log`；按业务测试结果配置 `open_basedir` 与 `disable_functions`；保留 `expose_php=Off`。

#### P1-02 HTTP 明文站点无跳转，缺少 HSTS 与安全响应头

- **证据**：`curl http://k.gaodekuai.cn/` 返回 200，且无 `Location` 跳转；HTTPS 响应无 `Strict-Transport-Security`、`X-Frame-Options`、`X-Content-Type-Options`、`Referrer-Policy`、CSP。生产 `default.conf` 只有一个 `listen 80; listen 443 ssl http2;` 的 server 块。
- **影响**：用户可通过明文 HTTP 访问站点；登录/会话流量可被降级或窃听；缺少点击劫持、MIME 嗅探、Referrer 泄露等基础防护。
- **建议**：80 端口统一 301 到 HTTPS；增加 HSTS（先 `max-age=31536000`，确认全站 HTTPS 后再考虑 `includeSubDomains`/`preload`）；增加 `X-Frame-Options: SAMEORIGIN`、`X-Content-Type-Options: nosniff`、`Referrer-Policy`；CSP 先以 Report-Only 上线。

#### P1-03 容器栈大量 EOL / 接近 EOL

- **证据**：Nginx **1.18.0**、MySQL **5.7.44**、Redis **5.0.14**、PHP **8.2.35**、Xunsearch 镜像 14 个月前构建。
- **影响**：
  - Nginx 1.18 已停止维护，存在 HTTP/2 Rapid Reset（CVE-2023-44487）等已知风险，且无法获得后续修复。
  - MySQL 5.7 已于 2023-10 结束扩展支持，无安全补丁。
  - Redis 5.0 已停止维护，缺少 ACL 等新安全能力。
  - PHP 8.2 安全支持将于 **2026-12-31** 结束，距今不足 2 个月。
- **建议**：制定升级路线：Nginx 1.28/1.29（或发行版维护版本）、MySQL 8.0/8.4、Redis 7.x、PHP 8.3/8.4；先在预发环境做兼容性回归，再切生产。升级前保留回退镜像与数据库备份。

#### P1-04 生产部署漂移：落后 1 个提交，phpinfo 仍在，Nginx 配置与仓库不一致

- **证据**：生产 HEAD `4f64bdc`，本地 HEAD `c20ae6e`（`fix(security): remove admin phpinfo endpoint`）；生产 `IndexController.php` 仍有 `@Get("/phpinfo")` 和 `echo phpinfo();`；生产 `nginx/conf.d/default.conf` 缺少仓库版本中的 HTTPS 跳转与安全响应头。
- **影响**：已开发的安全修复未上线；生产配置与代码库不一致，后续排障/回滚容易误判。`/admin/phpinfo` 仅 root 管理员可访问，但会泄露环境、扩展、路径等信息。
- **建议**：按发布流程部署 `c20ae6e`；同步 Nginx 配置并 `nginx -t`；把“生产 HEAD 与目标 commit 一致”纳入发布检查；不要把 `.bak` 配置留在 `conf.d` 可加载范围（当前 `.bak` 不会被 `*.conf` 加载，但仍建议归档）。

#### P1-05 备份不可靠：无定时、无异地/加密，配置包含 `.env`

- **证据**：root crontab 为空；`/etc/cron.d` 仅有 `e2scrub_all`、`sysstat`；docker 用户 crontab 仅有 acme.sh；`backups/` 中最近数据库备份为 2026-10-24；`config_*.tar.gz` 内含 `.env` 明文。
- **影响**：数据库/上传/配置没有可保证的 RPO；主机故障、勒索软件或误删会同时丢失生产数据与备份；`.env` 含数据库、Redis、短信、火山引擎等凭据，备份泄露等于凭据泄露。
- **建议**：每日自动备份 MySQL/Redis/上传/配置；备份加密（age/GPG）；至少一份异地（OSS/S3/另一地域）；定期做恢复演练；不要把 `.env` 明文放进普通配置归档，或对归档单独加密并限制权限。

#### P1-06 客户端 IP 可伪造：Nginx 透传客户端 `X-Forwarded-For` / `X-Real-IP`

- **证据**：生产 Nginx `fastcgi_param HTTP_X_FORWARDED_FOR $http_x_forwarded_for;`、`HTTP_X_REAL_IP $http_x_real_ip;`（直接使用客户端请求头）；应用 `Request::getClientAddress()` 默认信任这两个头；`PlaybackController`/`PlayTicket` 也优先取 `X-Forwarded-For`。
- **影响**：攻击者可伪造来源 IP，绕过基于 IP 的限流（VIP 兑换、播放票据等）、污染后台审计日志 `user_ip`、绕过 IP 白名单类逻辑。当前 `PLAY_TOKEN_BIND_IP=false`，播放票据 IP 绑定未开启，降低了直接影响，但限流与审计仍可被绕过。
- **建议**：Nginx 作为边缘时使用 `$remote_addr`，或仅在可信代理后使用 `$proxy_add_x_forwarded_for`；应用仅在 `REMOTE_ADDR` 属于可信代理时解析转发头；对 `X-Real-IP` 同理。

#### P1-07 无主机级审计/IDS/WAF，登录与扫描无限速

- **证据**：`fail2ban`、`auditd` 未安装；UFW 未启用；Nginx access log 中 `.env` 探测 11,638 次、`.git` 2,814 次、`phpmyadmin` 148 次；后台登录 `Session::login()` 未发现验证码/失败锁定/限速逻辑（仅有 CSRF 与 Referer 校验）；前台密码登录模板未见验证码（验证码登录是另一种方式）。
- **影响**：暴力破解、 credential stuffing、漏洞扫描会持续消耗资源；发生入侵时缺少审计线索。
- **建议**：安装 auditd 或启用云审计；部署 fail2ban/WAF/IDS；对 `/admin/login`、`/password/login`、`/wxpay/notify` 等增加 `limit_req`；后台登录增加失败锁定/验证码/MFA；集中收集 Nginx/PHP/SSH 日志并设置告警。

#### P1-08 Xunsearch 容器已退出 12 天，搜索功能不可用

- **证据**：`docker ps -a` 显示 `ctc-xunsearch Exited (0) 12 days ago`；日志末尾为 `ENTRY-POINT: stop signal received`，`restart=always` 不会在正常退出后重启。
- **影响**：课程/文章/问答搜索可能不可用；虽然这不是直接安全漏洞，但属于生产可用性风险，也会影响故障排查。
- **建议**：确认退出原因（人为停止、OOM、升级脚本）；启动并加 healthcheck/监控告警；若继续使用，升级到受维护的搜索组件或至少固定镜像并做漏洞扫描。

### 4.3 Medium（P2）

#### P2-01 Docker 运行时加固不足

- **证据**：容器 `privileged=false`、`cap_add=[]`、无 `docker.sock` 挂载（良好）；但 `Config.User` 为空（默认 root）、`ReadonlyRootfs=false`、`SecurityOpt=[]`（无 `no-new-privileges`）、`PidsLimit=nil`、无 `cap_drop`。
- **影响**：容器内应用 RCE 后，攻击者以 root 身份在容器内操作，横向到同网络 MySQL/Redis 更容易；缺少 `no-new-privileges` 会放大 setuid 类提权。
- **建议**：为 PHP/Nginx 增加 `security_opt: ["no-new-privileges:true"]`；`cap_drop: ["ALL"]` 后按需添加；尽量只读根文件系统 + tmpfs；为应用容器使用非 root 用户；设置 `pids_limit`、`mem_limit`、`cpus`。

#### P2-02 密钥长度与存放方式

- **证据**：`APP_KEY` 与 `PLAY_TOKEN_SECRET` 均为 16 字符；`.env` 权限 664（`docker:docker`）；备份配置归档含 `.env`；微信支付设置存于数据库且 `pay.wxpay enabled=1`。
- **影响**：16 字符密钥若为随机 base64ish，熵约 96 bit，低于推荐的 256 bit；密钥/支付配置与备份同机存放，泄露路径多。
- **建议**：使用 32 字节以上随机密钥；`APP_KEY` 轮换需按现有 VIP 兑换码重加密流程处理；限制 `.env` 为 600 且仅部署用户可读；备份加密；数据库中的支付密钥至少通过最小权限与审计保护。

#### P2-03 `storage`/上传目录 777，PHP path-info 未关闭

- **证据**：`html/ctc/storage`、`storage/upload`、`storage/log`、`storage/cache` 均为 `777 www-data:www-data`；上传目录当前未发现 `.php/.phtml/.phar` 文件；PHP-FPM `cgi.fix_pathinfo=On`，`security.limit_extensions` 未显式设置（默认 `.php .phar`）。
- **影响**：777 属不必要的写权限；若上传目录出现可执行内容，path-info 配置可能扩大风险。当前 Nginx 对 `/storage/upload/` 使用 `^~` alias，PHP 不会在该路径执行，但不应依赖单一配置。
- **建议**：收紧为 `755`/`775`（按实际属主），上传目录禁止执行；设置 `cgi.fix_pathinfo=0`、`security.limit_extensions=.php`；上传文件做类型/内容校验并随机化文件名。

#### P2-04 前端依赖过旧

- **证据**：`public/static/lib/jquery.min.js` 为 **jQuery 1.11.3（2015）**，用于后台登录页；KindEditor 代码版权至 2016；Layui 为 2.13.3（较新）。
- **影响**：jQuery 1.11.x 存在已知 XSS/原型污染类问题（如 CVE-2019-11358、CVE-2020-11022/11023 等）；后台登录页若拼接不可信数据，存在脚本注入风险。
- **建议**：升级 jQuery 到 3.7.x；评估 KindEditor 是否仍有必要，改用维护中的编辑器；对第三方静态资源使用 SRI/本地固定版本。

#### P2-05 `yansongda/pay` v2.10.6 被 OSV 标记，但该版本未见公告所述函数

- **证据**：`composer.lock` 中 `yansongda/pay` 为 `v2.10.6`；OSV 返回 `GHSA-q938-ghwv-8gvc`（WeChat Pay 回调在 Host 为 localhost 时跳过签名校验）。但容器内 `vendor/yansongda/pay/src` 没有 `Functions.php`/`verify_wechat_sign`；实际 `Wechat::verify()` 使用 `Support::generateSign($data) === $data['sign']` 的 MD5 校验，未发现 `localhost` 分支。
- **影响**：该具体 Host-header 绕过在 v2.10.6 的当前代码路径中**未确认可利用**；但依赖已过旧，且微信支付已启用（`pay.wxpay enabled=1`），支付回调仍是高价值目标。
- **建议**：升级到 `yansongda/pay` 3.7.20+（注意 v2→v3 API 破坏性变更），或在升级前对 `Wxpay::notify()` 增加独立的签名/金额/订单状态校验与告警；不要把该 OSV 结果直接当作已确认漏洞，也不要忽略。

#### P2-06 MySQL 认证与网络加固不足

- **证据**：`root@'%'`、`ctc@'%'` 均为 `mysql_native_password`；`local_infile=1`；`require_secure_transport=0`；`secure_file_priv=/var/lib/mysql-files/`（良好）。
- **影响**：native password 使用 SHA1 派生，离线破解弱于 `caching_sha2_password`；`local_infile` 在 SQL 注入时可被用于读取客户端文件；容器网络内数据库流量未加密。
- **建议**：升级 MySQL 8.0/8.4 后改用 `caching_sha2_password`；关闭 `local_infile`（确认业务不需要后）；限制 `ctc` 来源为应用容器网段；评估 `require_secure_transport` 与 TLS。

#### P2-07 8282 端口发布依赖云安全组，UFW 未启用

- **证据**：`ctc-php` 将 `8282:8282` 发布到 `0.0.0.0`；外部扫描 8282 为 filtered；UFW `inactive`；Nginx 已提供 `/wss` 反代。
- **影响**：当前云安全组挡住了 8282，但主机层没有第二道防线；安全组误改/迁移时会直接暴露 WebSocket 服务（无 TLS、无 Nginx 层防护）。
- **建议**：若 8282 只供 Nginx 内网使用，改为 `127.0.0.1:8282:8282` 或只在 Docker 网络暴露；保留云安全组限制；启用 UFW 作为纵深防御。

#### P2-08 后台/前台登录缺少明显限速与锁定

- **证据**：后台 `Session::login()` 仅做账号校验、CSRF、Referer；未发现验证码、失败计数、锁定；前台密码登录同样无验证码（验证码登录是独立入口）。
- **影响**：结合无 fail2ban/WAF，后台与前台密码登录可被慢速暴力破解/撞库。
- **建议**：增加失败次数限制、账号/IP 双维度限速、验证码或 MFA；对 `/admin/login` 与 `/password/login` 配置 Nginx `limit_req`；监控失败登录告警。

#### P2-09 缺少镜像漏洞扫描与供应链固定

- **证据**：镜像使用 `nginx:1.18`、`mysql:5.7`、`redis:5.0`、`php:8.2-fpm-bookworm`、`hightman/xunsearch:latest` 等浮动/过期标签；Docker daemon 配置了 4 个第三方 registry mirror（daocloud、dockerproxy、hub.rat.dev、chenby.cn）。
- **影响**：无法保证镜像内容与上游一致，第三方 mirror 存在供应链风险；EOL 基础镜像持续带入未修复漏洞。
- **建议**：镜像按 digest 固定；CI 中执行 Trivy/Grype 扫描并设阈值；优先使用官方/受信 registry；对第三方 mirror 做可用性与完整性评估。

---

## 5. 修复优先级建议（本次未执行）

### 5.1 今天/立即（P0）

1. **CVE-2026-98432 运行时缓解**（先在维护窗口 `nft -c` 校验，再加载）：
   ```bash
   sudo nft -c -f - <<'NFT'
   add table ip cve_2026_98432
   delete table ip cve_2026_98432
   table ip cve_2026_98432 {
     chain prerouting {
       type filter hook prerouting priority -300; policy accept;
       meta l4proto tcp ip hdrlength > 5 counter drop
     }
   }
   NFT
   ```
   加载后 `nft list table ip cve_2026_98432` 查看计数；注意该规则不覆盖容器间流量，且会丢弃依赖 IPv4 options 的流量。
2. **关闭未使用的 MPTCP**（先确认 `ss -lnMp` 无业务监听）：
   ```bash
   echo 'net.mptcp.enabled = 0' | sudo tee /etc/sysctl.d/99-disable-mptcp.conf
   sudo sysctl -w net.mptcp.enabled=0
   ```
3. **SSH 收敛**：云安全组限制 22；`PermitRootLogin prohibit-password`；`PasswordAuthentication no`；安装 fail2ban；确认所有管理员密钥可用后再关闭密码。
4. **补丁**：备份/快照后 `apt update && apt upgrade`，重启；安装并启用 `unattended-upgrades`。注意 6.8.0-147 仍不修复 CVE-2026-98432，需继续跟踪 6.8.0-149.149。
5. **凭据与权限**：分离 MySQL root/应用/Redis 密码；撤销 `ctc@'%'` 全局权限；删除/限制 `root@'%'`；关闭 `local_infile`。

### 5.2 本周（P1）

1. PHP-FPM 关闭 `display_errors`、开启 `log_errors`，配置 `open_basedir`/`disable_functions`（先预发验证）。
2. Nginx 增加 80→443 跳转、HSTS、XFO、XCTO、Referrer-Policy；CSP 先 Report-Only。
3. 部署 `c20ae6e`，移除 `/admin/phpinfo`；同步 Nginx 配置并 `nginx -t`。
4. 建立每日加密备份 + 异地副本 + 恢复演练。
5. 修复 XFF/X-Real-IP 信任链；Nginx 使用 `$remote_addr`/`$proxy_add_x_forwarded_for`，应用只信任可信代理。
6. 恢复 Xunsearch 并加监控；或明确下线搜索功能。
7. 后台/前台登录增加限速、失败锁定、验证码/MFA。

### 5.3 本月（P2）

1. 升级 Nginx/MySQL/Redis/PHP 到受支持版本；PHP 8.2 在 2026-12-31 前完成迁移。
2. Docker 加固：`no-new-privileges`、`cap_drop`、非 root、只读根文件系统、pids/内存限制。
3. 镜像固定 digest + Trivy/Grype 扫描；评估第三方 registry mirror。
4. 升级 jQuery/KindEditor；复核 `yansongda/pay` 并制定 v3 升级计划。
5. 收紧 `storage`/上传目录权限；关闭 `cgi.fix_pathinfo`，设置 `security.limit_extensions`。
6. 启用 auditd/集中日志/告警；对 SSH、后台登录、WAF 拦截做告警。

---

## 6. 已验证的良好实践（保留）

| 项目 | 状态 |
|---|---|
| TLS 1.2/1.3、证书有效期至 2027-01-12 | 正常 |
| acme.sh 续期（每 6 小时）+ reloadcmd | 已配置，下次续期 2026-12-13 |
| `server_tokens off`、`expose_php=Off` | 正常 |
| `.env`、`.git`、`/storage/upload/` 不可直接读取 | 404/403 |
| Docker socket 未挂载 | 正常 |
| AppArmor | enabled |
| 容器未 privileged、cap_add 为空 | 正常 |
| MySQL/Redis 仅绑定 127.0.0.1 | 正常 |
| 13306/6379/8282 外部 filtered | 正常（依赖云安全组） |
| 磁盘 17%、内存充足 | 正常 |
| 未发现明显入侵成功迹象 | 仅限本次日志抽样，非完整取证 |

---

## 7. 附录：关键证据（脱敏）

### 7.1 主机与内核

```text
Ubuntu 24.04.4 LTS
Linux gdk-cc 6.8.0-139-generic #139-Ubuntu SMP PREEMPT_DYNAMIC Sat Aug 1 03:52:05 UTC 2026 x86_64
net.mptcp.enabled = 1
net.ipv4.tcp_migrate_req = 0
ss -lnMp -> 空
nft list tables -> ip nat / ip filter / ip6 nat / ip6 filter / ip raw（无 cve_2026_98432）
```

### 7.2 补丁

```text
可升级总数: 123
noble-security: 84
linux-image-generic: 6.8.0-139.139 -> 6.8.0-147.147
openssh-server: 1:9.6p1-3ubuntu13.19 -> 1:9.6p1-3ubuntu13.20
openssl: 3.0.13-0ubuntu3.15 -> 3.0.13-0ubuntu3.16
libc6: 2.39-0ubuntu8.8 -> 2.39-0ubuntu8.9
sudo: 1.9.15p5-3ubuntu5.24.04.2 -> 1.9.15p5-3ubuntu5.24.04.4
systemd: 255.4-1ubuntu8.17 -> 255.4-1ubuntu8.18
docker-ce: 5:29.8.0 -> 5:29.9.2
containerd.io: 2.3.4 -> 2.4.2
unattended-upgrades: not installed
```

### 7.3 SSH / 认证

```text
permitrootlogin yes
passwordauthentication yes
maxauthtries 6
ufw: inactive
fail2ban: not installed
auditd: inactive
failed password for root: 81,755
failed password total: 128,030
accepted password for root: 3
accepted total: 206（其中 password 3；其余为 publickey；来源为管理员常用 IP）
```

### 7.4 运行时版本

```text
nginx: 1.18.0
php: 8.2.35
mysql: 5.7.44
redis: 5.0.14
composer: 2.10.3
docker: 29.8.0
containerd: 2.3.4
xunsearch: ctc/xunsearch:latest（14 个月前构建，容器已退出 12 天）
```

### 7.5 PHP-FPM

```text
display_errors=On
display_startup_errors=On
log_errors=Off
error_reporting=（无显式值）
open_basedir=（空）
disable_functions=（空）
allow_url_fopen=On
cgi.fix_pathinfo=On
security.limit_extensions=（未显式设置，默认 .php .phar）
session.cookie_httponly=On
session.cookie_secure=Off（应用在 HTTPS 请求中另设 Secure）
```

### 7.6 Nginx / HTTP

```text
http://k.gaodekuai.cn/ -> 200，无 Location 跳转
https://k.gaodekuai.cn/ -> 404（HEAD），GET / 为 200
响应头缺少 HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy
TLS 1.2 / 1.3 可用；证书 CN=k.gaodekuai.cn，Let's Encrypt YE2，2026-10-14 至 2027-01-12
Host: localhost 访问 /health 返回 200（单 server 块成为默认 server）
```

### 7.7 容器与端口

```text
ctc-php       Up 6 days    0.0.0.0:8282->8282/tcp
ctc-nginx     Up 5 days    0.0.0.0:80->80/tcp, 0.0.0.0:443->443/tcp
ctc-redis     Up 8 days    127.0.0.1:6379->6379/tcp
ctc-mysql     Up 11 days   127.0.0.1:13306->3306/tcp
ctc-xunsearch Exited (0) 12 days ago
外部扫描：22/80/443 OPEN；8282/13306/6379 filtered
```

### 7.8 数据库 / Redis

```text
MySQL 5.7.44-log
root@'%'  : ALL PRIVILEGES ON *.* WITH GRANT OPTION
ctc@'%'   : ALL PRIVILEGES ON *.* WITH GRANT OPTION
            ALL PRIVILEGES ON ctc.*
bind_address=*
local_infile=1
require_secure_transport=0
secure_file_priv=/var/lib/mysql-files/
MYSQL_ROOT_PASSWORD == MYSQL_PASSWORD == REDIS_PASSWORD（仅比较相等性）
```

### 7.9 备份 / 证书 / 日志

```text
root crontab: 空
/etc/cron.d: e2scrub_all, sysstat
docker crontab: acme.sh --cron（0,6,12,18 点）
最近数据库备份: 2026-10-24
配置归档包含: .env
Nginx access log: .env 11,638；.git 2,814；phpmyadmin 148；wp-login 35；xmlrpc 38
```

---

## 8. 结论

本次检查确认生产服务器存在多项系统性风险，且 **CVE-2026-98432 在 Ubuntu 24.04 上仍处于“受影响、修复开发中”状态**。在没有 Ubuntu 修复内核的情况下，应优先加载 nftables 缓解、关闭未使用的 MPTCP，并同时处理 SSH、补丁、数据库凭据与权限这三个可被直接利用或放大的问题。

本次未修改生产环境。建议在变更窗口按第 5 节顺序执行，并在每次变更后保留回退点与验证记录。
