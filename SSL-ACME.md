# SSL 证书手册（acme.sh + Let's Encrypt + 阿里云 DNS 校验）

本文记录生产服务器 **115.190.239.244（主机名 `gdk-cc`，Ubuntu 24.04）** 上 HTTPS 证书的真实布局、
签发方式与自动续期机制。域名从 `www.gaodekuai.cn` 迁移到 `k.gaodekuai.cn` 后按本手册维护。

---

## 1. 现状总览

| 项目 | 值 |
|---|---|
| acme.sh 版本 | v3.1.5 |
| acme.sh 安装目录 | `/home/docker/.acme.sh`（属主 `docker`，**不是 root**） |
| CA | Let's Encrypt（`DEFAULT_ACME_SERVER` 已写入 `account.conf`） |
| 域名校验方式 | `dns_ali`（阿里云 DNS API，密钥已保存在 `account.conf` 的 `SAVED_Ali_Key` / `SAVED_Ali_Secret`） |
| 证书算法 | ECC（`--keylength ec-256`，目录后缀 `_ecc`） |
| 定时任务 | `crontab -u docker -l` → `12 0,6,12,18 * * * acme.sh --cron` |
| Web 服务 | 容器 `ctc-nginx`（docker compose），80/443 由宿主机 docker-proxy 发布 |
| 证书落地目录 | `nginx/ssl/`（compose 挂载为容器内 `/etc/nginx/ssl`） |

> `nginx/ssl/` 与 `nginx/conf.d/*.conf` 都在 `.gitignore` 中，证书私钥与站点配置**不会**进入版本库；
> 版本库里只有 `nginx/conf.d/*.sample` 模板与本手册。

### 已签发的证书

| 主域名 | 用途 | 落地路径 | 续期后动作 |
|---|---|---|---|
| `gaodekuai.cn`（SAN: `www.gaodekuai.cn`） | 旧域名，保留续期 | `nginx/ssl/gaodekuai.cn.{crt,key}` | `docker exec ctc-nginx nginx -s reload` |
| `k.gaodekuai.cn` | **当前站点域名** | `nginx/ssl/k.gaodekuai.cn.{crt,key}` | `docker exec ctc-nginx nginx -s reload` |
| `study.gaodekuai.cn` | 火山云点播加速域名（证书需上传到火山控制台，本机不监听） | `/home/docker/certs/study/{fullchain,privkey}.pem` | 无（需手工上传到火山云） |

---

## 2. 为新域名签发证书（可复制执行）

全部命令以 `docker` 用户身份执行（acme.sh 与 cron 都属于该用户，用 root 执行会另建一套目录）。

```bash
# 1) 签发（DNS 校验，无需停站、无需开放 80 端口）
su - docker -c '/home/docker/.acme.sh/acme.sh --issue --dns dns_ali \
  -d k.gaodekuai.cn \
  --keylength ec-256 \
  --server letsencrypt'

# 2) 安装到项目目录，并登记"续期后自动 reload nginx"
su - docker -c '/home/docker/.acme.sh/acme.sh --install-cert -d k.gaodekuai.cn --ecc \
  --key-file       /home/docker/cloud_classroom/nginx/ssl/k.gaodekuai.cn.key \
  --fullchain-file /home/docker/cloud_classroom/nginx/ssl/k.gaodekuai.cn.crt \
  --reloadcmd      "docker exec ctc-nginx nginx -s reload"'
```

要点：

- 用 `--fullchain-file`（含中间证书），不要用 `--cert-file`，否则部分客户端报证书链不完整。
- `--reloadcmd` 必须登记。它会被 base64 存进 `~/.acme.sh/<域名>_ecc/<域名>.conf` 的 `Le_ReloadCmd`，
  **以后每次自动续期都会自动执行**，不需要人工介入。
- `nginx/ssl/` 是 compose 的 bind mount，新文件写进去容器内立即可见，无需重建容器。

### 3) 让站点用上新证书

编辑 `nginx/conf.d/default.conf`（服务器本地文件，不进 git）：

```nginx
server {
    listen 80;
    listen 443 ssl http2;
    server_name k.gaodekuai.cn;

    ssl_certificate     /etc/nginx/ssl/k.gaodekuai.cn.crt;
    ssl_certificate_key /etc/nginx/ssl/k.gaodekuai.cn.key;
    ...
}
```

改完必须先测试再重载：

```bash
docker exec ctc-nginx nginx -t && docker exec ctc-nginx nginx -s reload
```

---

## 3. 自动续期机制

无需新增任何 cron。现有任务已经覆盖所有证书：

```
12 0,6,12,18 * * * "/home/docker/.acme.sh"/acme.sh --cron --home "/home/docker/.acme.sh" > /dev/null
```

- 每天 0/6/12/18 点的第 12 分钟各跑一次；acme.sh 默认在到期前 30 天续期，多跑几次是为了容错。
- 新版 acme.sh 支持 Let's Encrypt 的 ARI（RFC 8555 续期信息），实际续期日会落在 CA 建议窗口内，
  因此 `--list` 显示的日期可能与传统"到期前 30 天"不同，属正常。
- 续期成功后 acme.sh 自动把新证书覆盖到 `--install-cert` 登记的路径，并执行登记的 `--reloadcmd`。

### 验证续期链路（安全，不会真的换证书）

```bash
# 看清单与下次续期时间
su - docker -c '/home/docker/.acme.sh/acme.sh --list'

# 空跑一次定时任务，未到期的证书应显示 "Skipping. Next renewal time is: ..."
su - docker -c '/home/docker/.acme.sh/acme.sh --cron --home /home/docker/.acme.sh'

# 确认 reloadcmd 已登记（输出应为 docker exec ctc-nginx nginx -s reload）
grep Le_ReloadCmd /home/docker/.acme.sh/k.gaodekuai.cn_ecc/k.gaodekuai.cn.conf \
  | sed 's/.*START_//; s/__ACME.*//' | base64 -d; echo

# 真正强制续期（会消耗 Let's Encrypt 每周重复证书配额，非必要不执行）
su - docker -c '/home/docker/.acme.sh/acme.sh --renew -d k.gaodekuai.cn --ecc --force'
```

### 线上证书巡检

```bash
# 有效期与 SAN
echo | openssl s_client -connect k.gaodekuai.cn:443 -servername k.gaodekuai.cn 2>/dev/null \
  | openssl x509 -noout -subject -dates -ext subjectAltName

# 证书链是否受信（Verify return code: 0 (ok) 为正常）
echo | openssl s_client -connect k.gaodekuai.cn:443 -servername k.gaodekuai.cn 2>/dev/null \
  | grep "Verify return code"

# 端到端
curl -svo /dev/null https://k.gaodekuai.cn/ 2>&1 | grep -E "subject:|expire date|verify ok|HTTP/"
```

---

## 4. WebSocket（wss）必须同步配置

站点跑在 HTTPS 下时，代码会强制下发 `wss://` 地址：

```php
// app/Http/Home/Controllers/Controller.php  getWebsocketInfo()
// app/Http/Api/Controllers/PublicController.php  socketInfoAction()
if ($this->request->isSecure()) {
    list($domain) = explode(':', $websocket->connect_address);
    $websocket->connect_url = sprintf('wss://%s/wss', $domain);   // 走 nginx 反代
} else {
    $websocket->connect_url = sprintf('ws://%s', $websocket->connect_address);  // 直连 8282
}
```

所以 **启用 HTTPS 的 server 块里必须有 `/wss` 反代**，否则前端连不上（表现为 404）：

```nginx
location /wss {
    proxy_pass http://php:8282;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 3600s;   # 长连接，避免默认 60s 掐断
    proxy_send_timeout 3600s;
}
```

验证（期望 `HTTP/1.1 101 Switching Protocols`）：

```bash
curl -s -i --http1.1 -H "Connection: Upgrade" -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  https://k.gaodekuai.cn/wss | head -1
```

---

## 5. 换域名时容易漏掉的三件事

1. **`.env` 改了必须重建 php 容器**。`env_file` 只在容器创建时读取，`restart` 不生效：
   ```bash
   ./reload-prod.sh          # 已封装：重建 php + 清缓存 + reload nginx + 重建首页缓存
   # 或最小操作：docker compose up -d php
   ```
   需要更新的键：`SITE_URL`、`WEBSOCKET_CONNECT_ADDRESS`（`SITE_DOMAIN` 目前代码未引用）。
   没重建的症状：首页图片仍写成旧域名绝对地址、`/api/socket/info` 仍返回 `wss://旧域名/wss`。

2. **改完要清首页缓存**。首页 HTML 会被写进 Redis，旧的绝对地址会一直复现：
   ```bash
   docker compose exec -T -w /var/www/html/ctc php php console.php maintain rebuild_index_course_cache
   ```

3. **证书不会自动跟着域名走**。新域名必须重新 `--issue` + `--install-cert`，见第 2 节。

---

## 6. 回滚

```bash
# 站点配置回滚到换证书前的备份
cp nginx/conf.d/default.conf.bak-20261014 nginx/conf.d/default.conf
docker exec ctc-nginx nginx -t && docker exec ctc-nginx nginx -s reload

# acme.sh 每次续期都会把上一版证书存到 ~/.acme.sh/<域名>_ecc/backup/
ls /home/docker/.acme.sh/k.gaodekuai.cn_ecc/backup/
```

---

## 7. 排错速查

| 现象 | 排查方向 |
|---|---|
| `Permission denied` / 证书装到 `/root/.acme.sh` | 用了 root。必须 `su - docker -c '...'`，acme.sh 属于 `docker` 用户 |
| 签发时 `Add TXT record` 失败 | 阿里云 AccessKey 权限或额度；确认 `account.conf` 里 `SAVED_Ali_Key/Secret` 有效 |
| 续期成功但浏览器仍是旧证书 | `Le_ReloadCmd` 没登记，或 `docker` 用户不在 `docker` 组（`id docker` 应含 `docker` 组） |
| 浏览器报证书名不匹配 | `default.conf` 的 `ssl_certificate` 还指向旧域名的 crt |
| 证书链不完整（手机端报错） | 用了 `--cert-file` 而非 `--fullchain-file` |
| wss 连不上、控制台 404 | server 块缺 `/wss` 反代，见第 4 节 |
| 静态图片 404 | 见下方"已知问题" |

---

## 8. 已知问题（待处理）

**`/storage/upload/` 下的多级子目录文件偶发 404。**

现象：`nginx/conf.d/default.conf` 中该 location 使用了带变量的 `alias`：

```nginx
location ^~ /storage/upload/ {
    alias $base_dir/html/ctc/storage/upload/;   # $base_dir 由 server 级 set 定义
}
```

实测同一 URL 在服务器本机 `curl` 返回 200、从公网访问返回 404，且随时间窗口翻转；
同级 `.gitignore`（一级文件）稳定 200，`2026/09/22/*.png`（多级）会 404。
`nginx` 对 `alias` 中使用变量的处理与 `root` 不同，建议改为字面量路径后重启 nginx 容器观察：

```nginx
location ^~ /storage/upload/ {
    alias /var/www/html/ctc/storage/upload/;
}
```

改完执行 `docker exec ctc-nginx nginx -t && docker compose restart nginx`（重启而非 reload，
以彻底替换 worker 进程），再从公网复测。
