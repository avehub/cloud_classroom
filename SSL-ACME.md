# SSL 证书手册（acme.sh + Let's Encrypt + DNS 校验）

本文是一份通用 HTTPS 证书维护模板，适用于使用 **acme.sh + Let's Encrypt**、并通过 DNS API 完成域名校验的 Docker Compose 部署。

> 文中的域名、IP、用户名和路径均为占位符，请替换为你自己的环境：
>
> - 站点域名：`class.example.com`
> - 备用域名：`example.com`
> - 点播/CDN 域名：`vod.example.com`
> - 部署用户：`deploy`（不要使用 root 运行 acme.sh）
> - 项目目录：`/opt/cloud_classroom`

---

## 1. 约定与前置条件

| 项目 | 建议值 |
|---|---|
| acme.sh 安装目录 | `/home/deploy/.acme.sh` |
| CA | Let's Encrypt |
| 校验方式 | DNS API（如阿里云 `dns_ali`、Cloudflare `dns_cf`） |
| 证书算法 | ECC（`--keylength ec-256`） |
| 证书落地目录 | `nginx/ssl/`（Compose 挂载到容器内 `/etc/nginx/ssl`） |
| Web 服务 | 容器 `ctc-nginx`，由 Compose 发布 80/443 |

`nginx/ssl/`、`nginx/conf.d/*.conf` 和 `.env` 都已在 `.gitignore` 中，证书私钥和站点配置不应进入版本库。

---

## 2. 安装 acme.sh

```bash
sudo -u deploy -H bash -lc '
  curl https://get.acme.sh | sh -s email=admin@example.com
'
```

安装后确认：

```bash
sudo -u deploy -H bash -lc '/home/deploy/.acme.sh/acme.sh --version'
```

如果使用 DNS API，请先在 acme.sh 的 `account.conf` 中配置对应服务商的 API 凭据，或通过环境变量传入。不要把 AccessKey/Secret 写入仓库、README 或聊天记录。

---

## 3. 签发证书

以下命令以 DNS 校验为例，签发时不要求站点开机，也不需要临时开放 80 端口。

```bash
sudo -u deploy -H bash -lc '
  /home/deploy/.acme.sh/acme.sh --issue \
    --dns dns_ali \
    -d class.example.com \
    --keylength ec-256 \
    --server letsencrypt
'
```

> `dns_ali` 只是示例，请替换为实际 DNS 服务商的 hook，例如 `dns_cf`、`dns_dp` 等。

### 安装证书到项目目录

```bash
sudo -u deploy -H bash -lc '
  /home/deploy/.acme.sh/acme.sh --install-cert -d class.example.com --ecc \
    --key-file       /opt/cloud_classroom/nginx/ssl/class.example.com.key \
    --fullchain-file /opt/cloud_classroom/nginx/ssl/class.example.com.crt \
    --reloadcmd      "docker exec ctc-nginx nginx -s reload"
'
```

要点：

- 使用 `--fullchain-file`（包含中间证书），不要只安装叶子证书，否则部分客户端会报证书链不完整。
- `--reloadcmd` 会被写入 `~/.acme.sh/<domain>_ecc/<domain>.conf` 的 `Le_ReloadCmd`，后续自动续期时会自动执行。
- `nginx/ssl/` 是 bind mount，新证书写入后容器内立即可见，不需要重建容器。

---

## 4. 配置 Nginx

编辑服务器本地文件 `nginx/conf.d/default.conf`（该文件不进入 git）：

```nginx
server {
    listen 80;
    server_name class.example.com;

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name class.example.com;

    ssl_certificate     /etc/nginx/ssl/class.example.com.crt;
    ssl_certificate_key /etc/nginx/ssl/class.example.com.key;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    add_header Strict-Transport-Security "max-age=31536000" always;

    include /etc/nginx/conf.d/app.locations.inc;
}
```

修改后先测试再重载：

```bash
docker exec ctc-nginx nginx -t
docker exec ctc-nginx nginx -s reload
```

---

## 5. 自动续期

acme.sh 安装时会注册 cron。确认任务存在：

```bash
sudo -u deploy -H bash -lc 'crontab -l | grep acme.sh'
```

典型输出类似：

```cron
12 0,6,12,18 * * * "/home/deploy/.acme.sh"/acme.sh --cron --home "/home/deploy/.acme.sh" > /dev/null
```

验证续期链路（不会真的换证书）：

```bash
# 查看证书清单和下次续期时间
sudo -u deploy -H bash -lc '/home/deploy/.acme.sh/acme.sh --list'

# 空跑 cron
sudo -u deploy -H bash -lc '/home/deploy/.acme.sh/acme.sh --cron --home /home/deploy/.acme.sh'

# 确认 reloadcmd 已登记
grep Le_ReloadCmd /home/deploy/.acme.sh/class.example.com_ecc/class.example.com.conf \
  | sed 's/.*START_//; s/__ACME.*//' | base64 -d; echo
```

强制续期会消耗 Let's Encrypt 的重复证书配额，非必要不要执行。

---

## 6. 线上证书巡检

```bash
# 有效期与 SAN
echo | openssl s_client -connect class.example.com:443 -servername class.example.com 2>/dev/null \
  | openssl x509 -noout -subject -dates -ext subjectAltName

# 证书链是否受信（Verify return code: 0 (ok) 为正常）
echo | openssl s_client -connect class.example.com:443 -servername class.example.com 2>/dev/null \
  | grep "Verify return code"

# 端到端
curl -svo /dev/null https://class.example.com/ 2>&1 | grep -E "subject:|expire date|verify ok|HTTP/"
```

---

## 7. WebSocket（wss）必须同步配置

站点启用 HTTPS 后，前端会优先使用 `wss://<domain>/wss`。Nginx 必须有 `/wss` 反向代理，否则前端 WebSocket 会返回 404。

```nginx
location /wss {
    proxy_pass http://php:8282;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

验证：

```bash
curl -s -i --http1.1 \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  https://class.example.com/wss | head -1
```

期望看到 `HTTP/1.1 101 Switching Protocols`。

---

## 8. 换域名时容易漏掉的三件事

1. **更新 `.env` 并重建 PHP 容器**。`env_file` 只在容器创建时读取，`restart` 不会重新加载：
   ```bash
   docker compose up -d php
   ```
   重点更新：`SITE_URL`、`SITE_DOMAIN`、`WEBSOCKET_CONNECT_ADDRESS`。

2. **清理首页缓存**。首页 HTML 可能缓存在 Redis 中，旧的绝对地址会继续出现：
   ```bash
   docker compose exec -T -w /var/www/html/ctc php \
     php console.php maintain rebuild_index_course_cache
   ```

3. **证书不会自动跟着域名走**。新域名必须重新 `--issue` + `--install-cert`。

---

## 9. 回滚

```bash
# 站点配置回滚到备份
cp nginx/conf.d/default.conf.bak nginx/conf.d/default.conf
docker exec ctc-nginx nginx -t
docker exec ctc-nginx nginx -s reload

# acme.sh 会把上一版证书保存在 backup 目录
ls /home/deploy/.acme.sh/class.example.com_ecc/backup/
```
