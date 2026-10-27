# A1 点播播放防盗链（第一阶段）

## 范围

本阶段不重新上传、不重新转码视频，只做：

1. 页面不再输出播放地址；
2. 播放时向后端申请短时播放票据；
3. CDN 远程鉴权校验票据；
4. 播放器用户水印；
5. F12 / 右键 / DevTools 提示。

限制：视频本身没有加密。用户在有效期内仍可通过自己的登录态下载，无法阻止录屏。真正的加密需要 A0（HLS AES-128）或更强的 DRM。

## 后端接口

### 申请播放票据

```http
POST /chapter/{id}/play-ticket
Cookie: PHPSESSID=...
X-Csrf-Token: ...
```

返回：

```json
{
  "code": 0,
  "data": {
    "chapter_id": 16,
    "expires_in": 3600,
    "play_urls": {
      "hd": {
        "url": "https://study.example.com/xxx.mp4?auth_key=...&pt=...",
        "width": 1280,
        "height": 720,
        "duration": 1200,
        "protected": true
      }
    },
    "watermark": {
      "uid": 123,
      "name": "张*"
    }
  }
}
```

### CDN 远程鉴权

```http
GET /chapter/play-auth?pt=<token>&uri=<原始播放地址>
```

允许返回 `200`，拒绝返回 `403`，限流返回 `429`。

鉴权服务会校验：

- HMAC 签名
- 过期时间
- 用户、章节
- UA（默认开启）
- IP（可选，`PLAY_TOKEN_BIND_IP=true`）
- 播放地址（默认开启；CDN 必须回传原始 URI）

## 火山引擎点播控制台配置

1. 进入点播空间 -> 分发加速 -> 域名管理 -> 点播加速域名 -> 配置。
2. 访问控制 -> 远程鉴权：
   - 鉴权地址：`https://你的站点域名/chapter/play-auth`
   - 请求方式：`GET`
   - 鉴权参数：保留原始 query，确保 `pt` 参数传给鉴权服务
   - 必须回传原始 URI（`uri` 参数或 `X-Original-URI` 请求头）
   - 必须回传客户端 IP（`X-Forwarded-For` / `X-Real-IP`）
   - 必须回传原始 `User-Agent`（`User-Agent` 或 `X-Forwarded-User-Agent`）
3. 访问控制 -> URL 鉴权：保持开启（当前点播域名已经开启 A 类型鉴权）。
4. 访问控制 -> Referer 白名单：加入站点域名。
5. 访问控制 -> UA 黑名单：按需加入常见下载器。
6. HTTPS：播放域名必须支持 HTTPS；站点当前已强制跳转 HTTPS。

如果控制台只支持固定的鉴权请求头，请把对应字段名改为本服务支持的别名：

- 票据：`pt`、`token`、`sign`、`auth_token`、`X-Play-Token`、`X-Auth-Token`
- 原始 URI：`uri`、`request_uri`、`url`、`path`、`X-Original-URI`、`X-Original-Url`、`X-Forwarded-Uri`、`X-Forwarded-Url`、`X-Request-Uri`
- 客户端 IP：`X-Forwarded-For`、`X-Real-IP`
- UA：`X-Forwarded-User-Agent`、`User-Agent`

## 环境变量

```dotenv
PLAY_TOKEN_SECRET=""
PLAY_TOKEN_LIFETIME=7200
PLAY_TOKEN_BIND_IP=false
PLAY_TOKEN_BIND_UA=true
PLAY_TOKEN_BIND_URL=true
PLAY_TOKEN_FORCE_HTTPS=true
PLAY_TOKEN_PROTECT_HOSTS=""
```

`PLAY_TOKEN_SECRET` 留空时使用 `APP_KEY`。修改密钥会让所有已签发播放地址立即失效。

如果开启 `PLAY_TOKEN_BIND_IP=true`，必须确保 Nginx/CDN 正确转发客户端真实 IP（`X-Forwarded-For` 或 `X-Real-IP`），否则所有请求会被识别为同一个代理 IP，IP 绑定将失去意义。

## 验证

1. 未登录请求播放票据应返回 401。
2. 未购买用户请求播放票据应返回 403。
3. 已购买用户能正常播放。
4. 页面源码中搜索不到 `m3u8`、`mp4`、`play_urls`、`auth_key`。
5. 复制 Network 中的播放地址：
   - 去掉 `pt` 参数后，CDN 远程鉴权应返回 403；
   - 使用错误的 UA 请求 `/chapter/play-auth` 应返回 403；
   - token 过期后应返回 403。
6. CDN 远程鉴权未配置时，`pt` 参数不会生效，播放地址仍受火山 URL 鉴权有效期保护，但无法做到会话级短时校验。
7. `file_remote` 指向第三方域名时，无法通过火山 CDN 远程鉴权保护；本阶段会跳过未在 `PLAY_TOKEN_PROTECT_HOSTS` / 点播分发域名中的地址，若全部被跳过则返回“点播资源尚未就绪”。

## 回滚

- 删除 CDN 远程鉴权配置；
- 回滚本次代码；
- 清理 Volt 缓存：`rm -rf html/ctc/storage/cache/volt/*`。

回滚后播放地址会重新出现在页面隐藏域中，不建议长期保留。
