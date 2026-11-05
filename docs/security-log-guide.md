# 服务登录与暴力破解日志查询手册

适用对象：生产服务器（示例：`<server-ip>` / `<domain>`，Ubuntu 24.04）
用途：在服务器或云控制台查看 SSH 登录、暴力破解、后台登录、数据库连接失败等安全数据。

> 以下命令中的 `${PROJECT_DIR:-/opt/cloud_classroom}` 和 `${SITE_URL:-http://127.0.0.1}` 请按实际部署替换；不要把真实 IP、域名、`.env` 或密码提交到仓库。

## 1. 最快入口

| 想查什么 | 去哪里 |
|---|---|
| SSH 成功/失败登录 | 服务器 `/var/log/auth.log`、`last`、`lastb` |
| 当前被封 IP | `fail2ban-client status sshd` |
| fail2ban 封禁历史 | `/var/log/fail2ban.log` |
| 后台管理员操作 | MySQL `kg_audit` 表 |
| 用户在线会话 | MySQL `kg_user_session` 表 |
| Web 扫描/攻击 | `nginx/log/ctc.access.log` |
| MySQL 连接失败 | `mysql/log/error.log` |
| 火山云主机安全告警 | 火山引擎控制台 → 云安全中心/主机安全 → 登录审计/暴力破解 |
| 火山云网络攻击 | 火山引擎控制台 → 云防火墙 → 入侵防御/流量日志 |
| 火山云账号/API 操作 | 火山引擎控制台 → 操作审计/访问控制（若已开通） |

建议确认：
- 主机安全、云监控、fail2ban 是否正常运行
- `ssh.socket` 是否处于 `enabled/active`（socket 激活方式下 `ssh.service` 显示 `disabled` 属正常）
- `auth.log`、`journald` 的保留周期是否满足审计要求
- Nginx access log 是否配置 logrotate 或日志服务采集

## 2. SSH 登录与暴力破解

### 2.1 当前在线会话

```bash
who
last -a | head -20
```

### 2.2 成功登录

```bash
grep -h 'Accepted' /var/log/auth.log /var/log/auth.log.1 2>/dev/null | tail -30
journalctl -u ssh --since today | grep 'Accepted'
```

字段含义：
- `Accepted publickey`：公钥登录
- `Accepted password`：密码登录（当前已关闭，历史记录仍可查）
- `from x.x.x.x`：来源 IP
- `SHA256:...`：使用的公钥指纹

### 2.3 失败登录 / 暴力破解

```bash
grep -h 'Failed password' /var/log/auth.log | tail -30
grep -h 'Failed password' /var/log/auth.log | grep -oE 'from [0-9.]+' | sort | uniq -c | sort -rn | head -20
lastb -a | head -30
```

按天统计：

```bash
grep -h 'Failed password' /var/log/auth.log | awk '{print $1" "$2}' | sort | uniq -c
```

查 root 被尝试次数：

```bash
grep -h 'Failed password for root' /var/log/auth.log | wc -l
```

查 invalid user：

```bash
grep -h 'Invalid user' /var/log/auth.log | tail -30
```

### 2.4 journald 方式

```bash
journalctl _COMM=sshd --since '7 days ago' --no-pager | grep -E 'Accepted|Failed|Invalid'
journalctl -u ssh --since '24 hours ago' --no-pager | tail -100
```

### 2.5 历史轮转日志

```bash
zgrep 'Failed password' /var/log/auth.log.*.gz | tail -30
```

## 3. fail2ban

### 3.1 当前状态

```bash
fail2ban-client status
fail2ban-client status sshd
```

输出重点：
- `Currently failed`：当前失败计数
- `Currently banned`：当前封禁数量
- `Banned IP list`：被封 IP

### 3.2 手动封禁/解封

```bash
fail2ban-client set sshd banip <IP>
fail2ban-client set sshd unbanip <IP>
```

### 3.3 封禁历史

```bash
tail -100 /var/log/fail2ban.log
zgrep 'Ban ' /var/log/fail2ban.log*.gz 2>/dev/null | tail -30
```

### 3.4 配置

```bash
cat /etc/fail2ban/jail.d/sshd.local
```

当前策略：`maxretry=10`、`findtime=10m`、`bantime=1h`、`bantime.increment=true`；如需临时放行管理员出口 IP，可将其加入 `ignoreip`；不要把真实 IP 写入仓库。

## 4. 业务后台登录 / 管理员操作

### 4.1 后台操作审计表 `kg_audit`

`kg_audit` 记录的是**后台管理员成功执行的 POST 操作**，不是失败登录：
- 用户、IP、路由、路径、请求参数（敏感字段已脱敏）、时间。

```bash
cd ${PROJECT_DIR:-/opt/cloud_classroom}
ROOTPW=$(grep -E '^MYSQL_ROOT_PASSWORD=' .env | cut -d= -f2- | tr -d '"')
docker exec -e MYSQL_PWD="$ROOTPW" ctc-mysql mysql -uroot -D ctc -e "
SELECT id,user_id,user_name,user_ip,req_route,req_path,
       FROM_UNIXTIME(create_time) AS t
FROM kg_audit
ORDER BY id DESC
LIMIT 30;"
```

按 IP 查：

```sql
SELECT id,user_name,user_ip,req_route,req_path,FROM_UNIXTIME(create_time)
FROM kg_audit
WHERE user_ip='<IP>'
ORDER BY id DESC
LIMIT 50;
```

按后台账号查：

```sql
SELECT id,user_name,user_ip,req_route,req_path,FROM_UNIXTIME(create_time)
FROM kg_audit
WHERE user_name='<管理员账号>'
ORDER BY id DESC
LIMIT 50;
```

### 4.2 用户会话表 `kg_user_session`

```sql
SELECT user_id,client_type,client_ip,
       FROM_UNIXTIME(create_time) AS login_time,
       FROM_UNIXTIME(expire_time) AS expire_time
FROM kg_user_session
ORDER BY id DESC
LIMIT 30;
```

说明：
- `kg_user_session` 记录会话，不记录失败登录。
- 失败的后台登录目前没有独立业务审计表；只能在 Nginx access log 看到 `POST /admin/login` 的状态码，无法看到账号。
- 如需“后台登录失败也入库/告警”，需要后续加应用日志（当前未实现）。

### 4.3 后台登录请求

```bash
cd ${PROJECT_DIR:-/opt/cloud_classroom}
grep 'POST /admin/login' nginx/log/ctc.access.log | tail -30
```

统计登录请求来源 IP：

```bash
grep 'POST /admin/login' nginx/log/ctc.access.log | awk '{print $1}' | sort | uniq -c | sort -rn | head -20
```

## 5. Web 扫描 / 攻击探测

```bash
cd ${PROJECT_DIR:-/opt/cloud_classroom}
grep -E '\.env|\.git|wp-login|phpmyadmin|xmlrpc|/vendor/' nginx/log/ctc.access.log | tail -50
```

按 IP 统计扫描量：

```bash
grep -E '\.env|\.git|wp-login|phpmyadmin' nginx/log/ctc.access.log \
  | awk '{print $1}' | sort | uniq -c | sort -rn | head -20
```

查 5xx/403/404 高峰：

```bash
awk '{print $9}' nginx/log/ctc.access.log | sort | uniq -c | sort -rn | head
```

## 6. MySQL / Redis

### 6.1 MySQL 连接失败

```bash
tail -200 ${PROJECT_DIR:-/opt/cloud_classroom}/mysql/log/error.log | grep -iE 'access denied|aborted connection|error'
```

### 6.2 Redis

```bash
tail -100 ${PROJECT_DIR:-/opt/cloud_classroom}/redis/log/redis.log
```

说明：Redis 5 默认不记录每次 AUTH 失败；如需审计，需要升级 Redis 6+ 使用 ACL，或通过代理/日志服务采集。

## 7. 火山引擎控制台

本机已有：
- `elkeid-agent`：主机安全 agent
- `cloud-monitor-agent`：云监控 agent

建议查看：
1. **云安全中心 / 主机安全**
   - 登录审计 / 暴力破解 / 异常登录
   - 安全告警 / 入侵检测
   - 基线检查
2. **云防火墙**
   - 入侵防御
   - 流量日志 / 攻击日志
3. **日志服务 TLS**
   - 采集 `/var/log/auth.log`
   - 采集 `/var/log/fail2ban.log`
   - 采集 `${PROJECT_DIR:-/opt/cloud_classroom}/nginx/log/ctc.access.log`
   - 配置“登录失败/封禁/5xx”告警
4. **云监控**
   - 主机 CPU/内存/网络告警
   - 端口/进程存活监控
5. **操作审计 / 访问控制**
   - 控制台登录、API 调用、子账号操作（若已开通）

控制台菜单名称可能随版本调整；优先搜索“登录审计”“暴力破解”“主机安全”“云防火墙”“日志服务”。

## 8. 建议的日常巡检命令

```bash
# SSH 失败 Top IP
grep -h 'Failed password' /var/log/auth.log | grep -oE 'from [0-9.]+' | sort | uniq -c | sort -rn | head

# 当前封禁
fail2ban-client status sshd

# 最近成功登录
last -a | head -10

# 后台登录请求
grep 'POST /admin/login' ${PROJECT_DIR:-/opt/cloud_classroom}/nginx/log/ctc.access.log | tail -20

# Web 扫描
grep -E '\.env|\.git|wp-login|phpmyadmin' ${PROJECT_DIR:-/opt/cloud_classroom}/nginx/log/ctc.access.log | tail -20

# 应用健康
curl -fsS ${SITE_URL:-http://127.0.0.1}/health
```

## 9. 注意

- 不要在聊天、工单或截图中粘贴 `.env`、数据库密码、Redis 密码、私钥。
- `kg_audit` 只记录后台成功 POST，不记录失败登录；需要失败登录审计要单独开发。
- `auth.log` 约 4 周后轮转删除；长期留存请接入火山日志服务 TLS 或定期归档。
- `nginx/ctc.access.log` 目前未独立轮转，建议加 logrotate 或 TLS 采集，避免磁盘增长。
