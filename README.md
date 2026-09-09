### 项目介绍

基于酷瓜云课堂二次开发的支持云课堂，支持火山云点播、本地存储

### 安装指南

下载安装脚本

```
git clone git@github.com:avehub/cloud_classroom.git
```

增加执行权限

```
chmod +x install.sh
```

根据实际情况修改配置

```
nano install.sh
```

执行安装，快慢取决于网络，当有错误或者超时请再次重试安装

```
bash install.sh
```

### 访问网站

* 管理账号：10000@163.com / 123456
* 前台地址：http://{your-domain}.com
* 后台地址：http://{your-domain}.com/admin

后续设置： [系统设置](https://koogua.com/page/wiki)


### 结束安装

安装完成，请删除安装脚本

```
rm install.sh
```



探查完毕，信息齐全。下面是基于源码（`/Users/admin/Desktop/Item/class/html/ctc`）的完整二开指南。

---

# 酷瓜云课堂源码目录全解析（二次开发指南）

## 一、技术栈与架构总览

| 层 | 技术 |
|---|---|
| 框架 | **Phalcon 3.4**（PHP 7.3，C 扩展框架）+ Volt 模板引擎 |
| 前端 | 服务端渲染 + **Layui**（jQuery 体系），无独立前端工程 |
| 数据 | MySQL 5.7（Phinx 迁移管理）+ Redis（缓存/会话） |
| 搜索 | Xunsearch（中文全文检索） |
| 实时通信 | Workerman GatewayWorker（websocket，端口 8282） |
| 定时任务 | 每分钟 cron 调度 `scheduler.php` → 各 Task（订单关闭、学习记录同步、索引同步等） |

**架构分层**（请求流向）：`nginx → Controller（注解路由）→ Repos（数据仓库）→ Models（ORM）`，横向由 `Services`（支付/搜索/存储/邮件等）、`Caches`、`Builders`、`Validators` 支撑。

## 二、目录结构地图（✏️=二开主战场，🔒=不要动）

```
html/ctc/
├── app/                          ✏️ 全部业务代码（核心改动区）
│   ├── Http/                     ✏️ 三大前端模块（页面/接口）
│   │   ├── Home/                 ✏️ 前台（学员端网站）
│   │   │   ├── Controllers/      ✏️ 30+ 控制器：Course/CourseChapter/Article/
│   │   │   │                        Question/Answer/Article/Order/Trade/Account/
│   │   │   │                        UserConsole(个人中心)/TeacherConsole/Vip...
│   │   │   ├── Views/            ✏️ Volt 模板：course/ article/ index/ user/
│   │   │   │                        templates/(布局) partials/ macros/(宏)
│   │   │   └── Services/         ✏️ 前台表单/业务封装
│   │   ├── Admin/                ✏️ 后台管理（course/chapter/user/order/setting/
│   │   │                            stat/slide/nav/category/role... 35 个视图组）
│   │   └── Api/                  ✏️ APP/小程序接口（仅 Controllers+Services，无视图，输出 JSON）
│   ├── Models/                   ✏️ 73 个 ORM 模型（kg_ 前缀表：Course/Chapter/Account...）
│   ├── Repos/                    ✏️ 数据仓库层（查询封装，Controller 主要调这里）
│   ├── Services/                 ✏️ 共享服务：Pay(支付)/Search(搜索)/OAuth/Mailer/
│   │                                Smser/Live/ChapterVod/CourseStat/Logic/...
│   ├── Builders/                 ✏️ 复杂查询构建器
│   ├── Validators/               ✏️ 表单验证
│   ├── Caches/                   ✏️ 缓存读写封装（Redis）
│   ├── Console/Tasks/            ✏️ 32 个 CLI 任务（索引重建/订单关闭/学习同步/升级...）
│   ├── Listeners/ Providers/     🔧 框架事件与 DI 注册（一般不改）
│   └── Library/ Exceptions/ Traits/  🔧 基础库（按需）
├── public/
│   ├── index.php                 🔒 Web 入口（别动）
│   └── static/                   ✏️ 前端静态资源（nginx 直接服务）
│       ├── home/{js,css,img}/    ✏️ 前台 JS：course.show.js / chapter.vod.player.js...
│       ├── admin/{js,css,img}/   ✏️ 后台 JS：common.js / content.editor.js...
│       └── lib/                  🔧 layui、echarts 等第三方库
├── config/                       ✏️ config.php(密钥/DB/Redis) routes.php(路由扫描)
│                                   events.php errors.php alipay/ wxpay/ xs.*.ini(搜索字段)
├── db/migrations/                ✏️ Phinx 数据库迁移（改表结构在这里）
├── websocket/                    ✏️ Workerman 启动脚本+事件（聊天/实时通知逻辑）
├── bootstrap/                    🔧 框架引导（Kernel/错误处理，一般不动）
├── console.php                   🔧 CLI 入口：php console.php --task=x --action=y
├── scheduler.php                 ✏️ 定时任务注册表（每分钟调度哪些 Task、频率）
├── storage/                      🔒 cache(volt编译产物/metadata/annotations) log/ —— 可清空勿手改
└── vendor/                       🔒 composer 依赖（严禁直接改）
```

**路由机制**：`config/routes.php` 自动扫描三个模块的 `Controllers/` 目录，配合控制器内 **Phalcon 注解**注册路由 → 新增页面 = 新建控制器方法（写注解）+ 对应 Views 模板。

## 三、典型改动场景对照表

| 你想改什么 | 改哪里 |
|---|---|
| 前台页面布局/样式/文案 | `app/Http/Home/Views/**.volt` + `public/static/home/css/*.css` |
| 前台交互逻辑 | `public/static/home/js/*.js`（每页一个 js，如 course.show.js） |
| 前台页面数据/业务 | `app/Http/Home/Controllers/XxxController.php`（+ 对应 `Home/Services/`） |
| 后台管理页面 | `app/Http/Admin/Views/**.volt` + `public/static/admin/{js,css}` + `Admin/Controllers/` |
| APP/小程序接口 | `app/Http/Api/Controllers/`（返回 JSON，不走视图） |
| 新增数据表/改字段 | `db/migrations/` 新建 Phinx 迁移 + `app/Models/` 对应模型 |
| 业务规则（下单/退款/积分…） | `app/Services/`、`app/Repos/`、`app/Builders/` |
| 支付/存储/短信/邮件配置 | `config/config.php` + `config/alipay|wxpay/` |
| 搜索字段调整 | `config/xs.course.ini` 等 + 重建索引 |
| 定时任务增减 | `app/Console/Tasks/` 新建 Task + `scheduler.php` 注册 |
| 实时推送/聊天逻辑 | `websocket/Events.php`（改后需重启容器） |
| 站点导航/菜单/轮播 | 大多在 **后台管理界面** 配置（存 DB），不用改代码 |

## 四、修改后的“重新部署”流程

**关键认知**：`html/` 是 bind-mount 挂载进容器的，**改源码即时生效，不需要重新 build 镜像**。需要处理的只有“缓存”和“常驻进程”：

### 场景 1：改 PHP 代码（Controller/Service/Repo/Model）
```bash
# 无需任何操作，刷新浏览器即生效
# 若不生效（极少见，opcache 缓存），重启：
docker compose restart php
```

### 场景 2：改 Volt 模板（.volt）⚠️ 必做
Phalcon 默认**不检测模板变更**，编译产物缓存在 `storage/cache/volt/`：
```bash
rm -rf html/ctc/storage/cache/volt/*     # 清模板缓存
# 若同时改了 Model（字段/关联），再清这两个：
rm -rf html/ctc/storage/cache/metadata/* html/ctc/storage/cache/annotations/*
```
> 💡 开发期可把 `config/config.php` 的 `$config['env'] = 'pro'` 改为 `'dev'`，能直接在页面看到报错详情（改完记得改回 pro）。

### 场景 3：改静态 JS/CSS
即时生效，但 URL 带 `?v=202004080830` 版本号会被浏览器缓存：
- 临时：浏览器硬刷新（Cmd+Shift+R）
- 正式：改 `config/config.php` 的 `static_version`（如改为当前日期）全站自动失效缓存

### 场景 4：数据库变更
```bash
# 1) 生成迁移文件（会创建 db/migrations/新时间戳.php，编辑它的 up/down 方法）
docker exec -it ctc-php sh -c 'cd /var/www/html/ctc && vendor/bin/phinx create AddXxx'
# 2) 手工编辑迁移文件后执行
docker exec -it ctc-php sh -c 'cd /var/www/html/ctc && vendor/bin/phinx migrate'
# 3) 同步修改 app/Models/ 下对应模型，并清 metadata 缓存（见场景 2）
```
> 改库前建议备份：`docker exec ctc-mysql sh -c 'mysqldump -uctc -p"密码" ctc' > backup.sql`

### 场景 5：websocket / 定时任务 / composer 依赖
```bash
# 改了 websocket/ 或 composer.json 后：
docker compose restart php                        # supervisor 会自动拉起 websocket
docker exec ctc-php sh -c 'cd /var/www/html/ctc && composer install'   # 加了新依赖包时
docker exec ctc-php php /var/www/html/ctc/console.php --task=course_index --action=rebuild  # 搜索字段变更后重建索引
```

### 场景 6：整站镜像级变更（改 Dockerfile / nginx 配置）
```bash
docker compose up -d --build        # 才需要重新构建
```

## 五、推荐的一轮二开闭环（示例：给课程列表页加个自定义筛选）

```bash
cd /Users/admin/Desktop/Item/class/html/ctc

# 1. 后端：改数据查询
vim app/Http/Home/Controllers/CourseController.php   # 调整 listAction / 注解路由
vim app/Repos/Course.php                             # 加查询条件

# 2. 前端：改模板 + 样式
vim app/Http/Home/Views/course/list.volt
vim public/static/home/css/content.css

# 3. 清缓存 + 验证
rm -rf storage/cache/volt/*
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: ctc.docker' http://127.0.0.1/course/list

# 4. 满意后提交到自己的 git 仓库（该目录本身就是 gitee 仓库的克隆，建议 fork 后改 remote）
git add -A && git commit -m "feat: 自定义课程筛选"
```

## 六、注意事项

1. **`vendor/` 永远不要直接改**——需要改第三方包行为时用 composer fork + 仓库替换。
2. **`storage/` 里的文件都是生成物**，随时可清空重建（log 除外，看问题用）。
3. 每分钟有定时任务在跑（订单关闭、学习同步等），**调试相关 Task 时**可直接手动触发：`docker exec ctc-php php console.php --task=close_order --action=main`，避免等 cron。
4. 建议先 **fork gitee 的 course-tencent-cloud 仓库**再改 remote，方便后续合并官方升级；本地已有 `core.fileMode=false` 配置，macOS 权限位不会污染 diff。
5. 配置里 `websocket.connect_address` 当前是 `ctc.docker:8282`，若日后换域名/部署到服务器，记得同步改 `config/config.php` 和 nginx 的 `server_name`。

