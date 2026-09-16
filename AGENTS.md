# AGENTS.md

本文件为 AI Agent 在本项目工作时的指引与规范，涵盖项目架构、开发规范、验证流程及版本管理要求。

---

## 1. 核心工作准则（强制执行）

1. **每次修改必须测试验证**：
   - 任何代码/配置修改后，必须执行对应的验证命令或测试手段（如语法检查、接口请求、缓存清理、进程重启或回归测试）。
   - 禁止在未验证的情况下直接交付。
2. **每次修改后必须同步 Git Commit**：
   - 修改验证通过后，需将改动通过 `git add` 和 `git commit` 进行结构化提交。
   - 提交信息要求清晰规范（如 `feat: xxx`、`fix: xxx`、`refactor: xxx`），便于出现问题时快速回退与排查。

---

## 2. 项目总览与技术栈

本项目基于酷瓜云课堂二次开发，支持火山云点播、本地存储等功能。

| 层次/模块 | 技术选型 | 说明 |
|---|---|---|
| **后端框架** | Phalcon 3.4 (PHP 7.3, C扩展) | 架构分层：Controller (注解路由) → Repos → Models |
| **模板引擎** | Volt 模板引擎 | 编译产物缓存在 `html/ctc/storage/cache/volt/` |
| **前端体系** | Layui + jQuery + 原生 JS/CSS | 服务端渲染，无独立前端构建工程 |
| **数据库/存储** | MySQL 5.7 + Redis | 数据库迁移采用 Phinx；Redis 用于缓存/Session |
| **全文检索** | Xunsearch | 中文全文检索系统 |
| **实时通信** | Workerman GatewayWorker | WebSocket 服务（端口 8282） |
| **定时调度** | Linux Cron + `scheduler.php` | 每分钟调度各类 Task（订单、学习记录同步等） |
| **运行环境** | Docker Compose | 包含 php、nginx、mysql、redis、xunsearch 服务 |

---

## 3. 目录结构与修改指南

```
.
├── html/ctc/                       # 核心业务代码（bind-mount 挂载到容器）
│   ├── app/                        # 业务代码核心区
│   │   ├── Http/
│   │   │   ├── Home/               # 前台（学员端）：Controllers / Views / Services
│   │   │   ├── Admin/              # 后台管理端：Controllers / Views
│   │   │   └── Api/                # API 接口（JSON 输出，无视图）
│   │   ├── Models/                 # ORM 模型（kg_ 前缀表）
│   │   ├── Repos/                  # 数据仓库层（查询逻辑封装）
│   │   ├── Services/               # 核心业务服务（Pay/Search/OAuth/Vod/Live等）
│   │   ├── Builders/               # 复杂查询构建器
│   │   ├── Validators/             # 表单校验器
│   │   ├── Caches/                 # Redis 缓存封装
│   │   └── Console/Tasks/          # CLI 异步/定时任务
│   ├── config/                     # 配置文件（config.php, routes.php, xs.*.ini）
│   ├── db/migrations/              # Phinx 数据库迁移脚本
│   ├── public/                     # Web 根目录与静态资源（static/home, static/admin）
│   ├── storage/                    # 运行时缓存与日志（cache/, log/）
│   ├── websocket/                  # Workerman 实时通信脚本与事件处理
│   ├── console.php                 # CLI 任务入口
│   └── scheduler.php               # 定时任务注册与调度入口
├── docker-compose.yml              # 容器编排定义
├── nginx/                          # Nginx 配置与证书
├── php/                            # PHP Dockerfile 与配置
├── mysql/                          # MySQL 初始化与配置
└── redis/                          # Redis 配置
```

### 修改场景与定位表

| 修改目标 | 对应路径 |
|---|---|
| 前台页面模板 / 样式 | `html/ctc/app/Http/Home/Views/**.volt` + `html/ctc/public/static/home/css/` |
| 前台前端交互 JS | `html/ctc/public/static/home/js/*.js` |
| 前台后端业务/控制器 | `html/ctc/app/Http/Home/Controllers/` + `html/ctc/app/Http/Home/Services/` |
| 后台管理页面与逻辑 | `html/ctc/app/Http/Admin/` + `html/ctc/public/static/admin/` |
| API 接口 | `html/ctc/app/Http/Api/Controllers/` |
| 数据库表与模型 | `html/ctc/db/migrations/` + `html/ctc/app/Models/` |
| 核心业务逻辑 | `html/ctc/app/Services/`、`html/ctc/app/Repos/` |
| 配置文件 | `html/ctc/config/config.php` |
| 定时任务 | `html/ctc/app/Console/Tasks/` + `html/ctc/scheduler.php` |
| WebSocket 逻辑 | `html/ctc/websocket/Events.php` |

---

## 4. 修改后测试验证与生效流程

由于 `html/` 是挂载进容器的，改动后需要按照场景进行缓存清理与验证：

### 4.1 PHP 代码修改（Controller / Service / Repo / Model）
- **生效机制**：通常修改即生效。
- **验证方式**：
  - 语法检查：`php -l <path-to-file>`
  - 如开启 opcache 且未更新：`docker compose restart php`
  - 接口/页面请求验证：`curl -I http://127.0.0.1/<route>` 或指定 Host 访问。

### 4.2 Volt 模板修改（`.volt`）
- **必要操作**：Phalcon 不自动检测模板变更，必须清理模板编译缓存：
  ```bash
  rm -rf html/ctc/storage/cache/volt/*
  ```
- **模型字段变更时清理元数据**：
  ```bash
  rm -rf html/ctc/storage/cache/metadata/* html/ctc/storage/cache/annotations/*
  ```

### 4.3 数据库变更（Phinx）
- **执行流程**：
  ```bash
  # 创建迁移
  docker exec -it ctc-php sh -c 'cd /var/www/html/ctc && vendor/bin/phinx create MigrationName'
  # 执行迁移
  docker exec -it ctc-php sh -c 'cd /var/www/html/ctc && vendor/bin/phinx migrate'
  ```
- **验证方式**：检查 MySQL 库表结构与数据，清理 metadata 缓存并运行测试。

### 4.4 WebSocket / 定时任务 / 依赖变更
- **WebSocket 变更**：`docker compose restart php` 重启服务。
- **定时任务调试验证**：直接手动运行对应 CLI 任务进行测试：
  ```bash
  docker exec ctc-php php /var/www/html/ctc/console.php --task=<task_name> --action=<action_name>
  ```
- **Composer 依赖变更**：
  ```bash
  docker exec ctc-php sh -c 'cd /var/www/html/ctc && composer install'
  ```

---

## 5. 标准开发与提交流程闭环

Agent 在进行任何需求开发、缺陷修复或功能调整时，必须严格执行以下工作流：

```
1. 需求分析与代码定位
   ↓
2. 编写/修改代码或配置
   ↓
3. 清理必要缓存 (volt/metadata/annotations) & 重启关联服务 (如需)
   ↓
4. 执行测试验证 (语法检查 / CLI 任务测试 / HTTP 状态码与响应校验 / 日志检查)
   ↓
5. 验证通过后，执行 Git 提交保存节点 (git add & git commit)
   ↓
6. 交付结果说明
```
