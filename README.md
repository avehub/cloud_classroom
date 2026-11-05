# CTC 云课堂

基于 [酷瓜云课堂](https://gitee.com/koogua/course-tencent-cloud) 二次开发的开源在线教育系统，提供课程、点播、直播、专栏、问答、会员、订单、支付、后台管理等能力。

当前版本已完成 **PHP 8.2.35 + Phalcon 5.23** 升级，并补充了火山引擎点播、播放票据/防盗链、H5/微信登录、短信通知、Docker Compose 部署与安全加固等能力。

> 本文档只使用示例域名和占位符。部署时请通过 `.env` 和服务器本地配置文件填写真实信息，不要把域名、IP、账号、密码、证书或日志提交到仓库。

---

## 功能概览

- 课程 / 章节 / 课时管理，支持点播、直播、图文、专栏
- 火山引擎 VOD 点播、HLS 播放、播放票据与防盗链
- 本地存储与对象存储切换
- 前台学员端、后台管理端、H5 / API 接口
- 手机号/邮箱验证码登录，未注册账号自动创建
- 微信登录、账号绑定、密码设置与找回
- 会员、优惠券、订单、支付、退款、积分
- 全文检索（Xunsearch）、学习记录同步、定时任务
- WebSocket 实时通知与聊天
- Docker Compose 一键编排

---

## 技术栈

| 层次 | 技术 |
|---|---|
| 后端框架 | PHP 8.2.35 + Phalcon 5.23（C 扩展） |
| 模板引擎 | Volt |
| 架构分层 | Controller（注解路由）→ Repos → Models，Services/Builders/Validators 横向支撑 |
| 前端 | Layui + jQuery + 服务端渲染，无独立前端构建工程 |
| 数据库 | MySQL 5.7 + Phinx 迁移 |
| 缓存 / 会话 | Redis 5 |
| 全文检索 | Xunsearch |
| 实时通信 | Workerman GatewayWorker（WebSocket 8282） |
| Web 服务 | Nginx 1.18 |
| 运行编排 | Docker Compose |
| 定时任务 | Supervisor + `scheduler.php` + `console.php` |

---

## 目录结构

```text
.
├── html/ctc/                       # 核心业务代码（挂载到 PHP 容器）
│   ├── app/
│   │   ├── Http/Home/              # 学员端：Controllers / Views / Services
│   │   ├── Http/Admin/             # 管理后台
│   │   ├── Http/Api/               # API / H5 接口（JSON）
│   │   ├── Models/                 # ORM 模型（kg_ 前缀表）
│   │   ├── Repos/                  # 查询仓库层
│   │   ├── Services/               # 支付、点播、搜索、短信、直播等业务服务
│   │   ├── Builders/               # 复杂查询构建器
│   │   ├── Validators/             # 表单校验
│   │   ├── Caches/                 # Redis 缓存封装
│   │   └── Console/Tasks/          # CLI / 定时任务
│   ├── config/                     # 配置模板与路由
│   ├── db/migrations/              # Phinx 迁移脚本
│   ├── public/                     # Web 根目录与静态资源
│   ├── storage/                    # 缓存、日志、上传（运行时生成）
│   ├── websocket/                  # Workerman 脚本与事件
│   ├── console.php                 # CLI 入口
│   └── scheduler.php               # 定时任务注册
├── docker-compose.yml
├── php/                            # PHP 8.2 / Phalcon 5 运行镜像
├── nginx/                          # Nginx 配置模板与证书挂载目录
├── mysql/                          # MySQL 配置与数据目录
├── redis/                          # Redis 配置与数据目录
├── xunsearch/                      # Xunsearch 数据目录
├── reload-prod.sh                  # 生产发布脚本
├── deploy-prod.sh                  # 首次部署 / 自愈脚本
├── backup.sh                       # 数据库、Redis、上传文件、配置备份
└── docs/                           # 公开技术文档
```

---

## 环境要求

- Linux x86_64
- Docker Engine 24+，Docker Compose v2
- 建议最低 2 vCPU / 4 GB 内存；生产建议 2 vCPU / 8 GB 起步
- 已解析到服务器的域名（可选，但生产环境应启用 HTTPS）
- 可访问火山引擎、短信服务商等外部依赖（按需）

---

## 快速开始

### 1. 获取代码

```bash
git clone <your-repository-url> cloud_classroom
cd cloud_classroom
```

### 2. 创建环境配置

```bash
cp .env.default .env
```

至少修改以下配置：

```dotenv
APP_ENV=pro
APP_KEY="<openssl rand -hex 32 生成>"
SITE_DOMAIN="class.example.com"
SITE_URL="https://class.example.com"
WEBSOCKET_CONNECT_ADDRESS="class.example.com:8282"

MYSQL_ROOT_PASSWORD="<强随机密码>"
MYSQL_PASSWORD="<强随机密码>"
REDIS_PASSWORD="<强随机密码>"

# 首次执行数据库迁移时创建管理员
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="<强随机密码；留空则自动生成并打印到迁移日志>"
```

生成随机密钥：

```bash
openssl rand -hex 32
```

> `.env` 已加入 `.gitignore`，不要提交。`html/ctc/config/config.php` 已改为环境变量驱动，不要把密码或密钥写死在其中。

### 3. 配置 Nginx

本地 / HTTP 环境：

```bash
cp nginx/conf.d/default.local.conf.sample nginx/conf.d/default.conf
```

生产 HTTPS 环境：

```bash
cp nginx/conf.d/default.secure.conf.sample nginx/conf.d/default.conf
```

然后修改 `nginx/conf.d/default.conf` 中的：

- `server_name`
- `ssl_certificate`
- `ssl_certificate_key`

证书文件放到 `nginx/ssl/`。该目录和 `nginx/conf.d/*.conf` 都不会进入 Git。

### 4. 构建并启动

```bash
docker compose build
docker compose up -d
```

### 5. 安装 PHP 依赖

```bash
docker compose exec -T -w /var/www/html/ctc php \
  composer install --no-dev --prefer-dist --optimize-autoloader
```

### 6. 初始化数据库

```bash
docker compose exec -T -w /var/www/html/ctc php \
  php vendor/bin/phinx migrate -c phinx.php

docker compose exec -T -w /var/www/html/ctc php \
  php console.php upgrade
```

首次迁移会根据 `.env` 中的 `ADMIN_EMAIL` / `ADMIN_PASSWORD` 创建管理员。若 `ADMIN_PASSWORD` 留空，迁移日志会输出随机生成的初始密码，请立即保存并登录后台修改。

### 7. 访问

- 前台：`https://class.example.com/`
- 后台：`https://class.example.com/admin`
- 健康检查：`https://class.example.com/health`

---

## 配置说明

所有敏感配置统一放在项目根目录 `.env`，由 Docker Compose 注入容器。

| 变量 | 说明 |
|---|---|
| `APP_ENV` | `dev` / `test` / `pro` |
| `APP_KEY` | 应用密钥，必须使用强随机值 |
| `SITE_DOMAIN` | 站点域名，用于 Nginx 健康检查和 WebSocket |
| `SITE_URL` | 站点完整地址（含协议），用于生成绝对 URL |
| `WEBSOCKET_CONNECT_ADDRESS` | 客户端 WebSocket 地址 |
| `MYSQL_*` | MySQL 连接信息 |
| `REDIS_*` | Redis 连接信息 |
| `SMS_*` | 短信服务商 AccessKey、签名、模板 |
| `PLAY_TOKEN_*` | 点播播放票据、防盗链、有效期 |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | 首次迁移创建的管理员账号 |
| `APP_NAME` / `APP_ALIAS` | 应用名称与显示名 |
| `APP_LINK` | 应用主页，留空时回退到 `SITE_URL` |
| `APP_SUPPORT_URL` / `APP_COMMUNITY_URL` | 后台帮助 / 社区入口，留空回到后台首页 |
| `APP_INFO_SYNC_URL` | 应用信息上报接口；**默认留空关闭**，避免向第三方发送服务器信息 |

> 修改 `.env` 后需要重建读取该变量的容器（至少 `php`；`nginx` 健康检查也会读取 `SITE_DOMAIN`）：
>
> ```bash
> docker compose up -d --force-recreate php nginx
> ```

---

## 本地开发

### 修改 PHP 代码

通常即时生效。若启用 OPcache 且未刷新：

```bash
docker compose restart php
```

### 修改 Volt 模板

Phalcon 不会自动检测模板变更，需要清理编译缓存：

```bash
rm -rf html/ctc/storage/cache/volt/*
```

修改模型字段或关联后，同时清理元数据和注解缓存：

```bash
rm -rf html/ctc/storage/cache/metadata/* \
       html/ctc/storage/cache/annotations/*
```

### 修改静态资源

静态资源 URL 带 `APP_STATIC_VERSION` 版本号。发布时更新该值可使浏览器缓存失效。

### 数据库迁移

```bash
docker compose exec -T -w /var/www/html/ctc php \
  php vendor/bin/phinx create AddYourMigration
```

编辑 `html/ctc/db/migrations/` 下生成的迁移文件，然后执行：

```bash
docker compose exec -T -w /var/www/html/ctc php \
  php vendor/bin/phinx migrate -c phinx.php
```

---

## 生产发布

发布前先备份：

```bash
./backup.sh
```

标准发布：

```bash
./reload-prod.sh
```

脚本会依次执行：

1. `git pull --ff-only`
2. 构建 PHP 8.2 镜像
3. 重建 / 启动 PHP 服务
4. 首次 Phalcon 5 切换时清理旧 Redis 业务缓存
5. `composer install --no-dev`
6. Phinx 迁移与 `console.php upgrade`
7. 清理 Volt / metadata / annotations 缓存
8. 重启 PHP-FPM 与 WebSocket
9. 重载 Nginx
10. 重建首页缓存

仅验证本地改动、不拉代码：

```bash
SKIP_GIT_PULL=1 ./reload-prod.sh
```

> 发布脚本应由仓库目录的属主用户执行，不要用 root 拉取代码，否则 `.git` 会混入 root 属主文件，后续普通用户无法写入。
>
> 如果误用 root 执行过 `git fetch` / `git pull`：
>
> ```bash
> sudo chown -R <deploy-user>:<deploy-group> .git
> ```

---

## 安全与隐私

- `.env`、`nginx/conf.d/*.conf`、`nginx/ssl/`、日志、上传文件、备份目录均已加入 `.gitignore`
- 不要在 README、Issue、截图、日志或提交信息中粘贴真实域名、IP、账号、密码、AccessKey、证书私钥
- 生产环境必须使用 HTTPS，并限制后台、SSH、数据库、Redis、WebSocket 的访问来源
- `APP_INFO_SYNC_URL` 默认关闭；启用后会向配置的第三方接口上报站点主机信息，请先评估隐私影响
- 初始管理员密码不再使用固定默认值；首次迁移请设置 `ADMIN_PASSWORD` 或保存随机生成的密码
- 定期轮换 `APP_KEY`、数据库密码、Redis 密码、短信 AccessKey 和证书
- 发布账号建议使用只读 Deploy Key 或最小权限账号；不要复用个人主账号密钥

---

## 相关文档

- [账号自动注册与密码设置](docs/account-auth-auto-register.md)
- [点播播放票据与防盗链](docs/vod-playback-protection-a1.md)
- [SSL 证书维护（acme.sh）](SSL-ACME.md)
- [Kubernetes 迁移蓝图](k8s-blueprint.md)
- [安全日志查询](docs/security-log-guide.md)

---

## 许可证

本项目基于 GPL-2.0 许可证发布，详见 [LICENSE](LICENSE)。

上游项目：[酷瓜云课堂](https://gitee.com/koogua/course-tencent-cloud)。
