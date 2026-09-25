# Docker 安装与多实例部署

## 先准备好

需要一台 Linux 服务器、本机 Docker Engine + Compose **2.20+**、Git、`ss`（iproute2）和 `flock`（util-linux），以及**一个空 MySQL 数据库和它的独立账号**。宿主机不需要 PHP、Composer 或 Node.js。

- Docker 尚未安装：[官方安装文档](https://docs.docker.com/engine/install/)。
- MySQL 已在宝塔中运行：为每个面板新建一个空库和独立用户；地址通常是 `127.0.0.1`，端口可在安装时填写。
- 先准备面板完整网址（例如 `https://panel.example.com`）和管理员邮箱。
- 本方案保留 Linux host 网络以兼容宿主机 MySQL。不是 Docker Desktop / 远程 Docker 安装器，也不会替你安装或改动系统 Docker、MySQL、防火墙。

## 安装只执行这三条命令

```bash
git clone https://github.com/basumobai/v2board-for-Mundo-X-build.git panel-a
cd panel-a
bash init.sh
```

按提示填写：实例名、Web 端口、Nginx 入口端口、Web/队列 Worker 数量、面板网址、数据库连接和管理员邮箱。其余构建、依赖安装、导入、视图预编译、服务启动和健康检查由脚本完成。

首次构建 PHP 镜像可能需要几分钟；端口默认从 6600、7001 开始寻找空闲值。密码输入不回显。结束时会显示**管理员初始密码、后台地址、反向代理目标**，请保存并及时更换初始密码。

不需要提前复制 `.env`，不需要手工改 Dockerfile、Compose 或 Nginx 文件。

## 同一台服务器再装一套

必须使用另一个目录、另一个实例名、不同的两个监听端口和独立数据库。可以在向导里填写，也可以预设非敏感部署参数：

```bash
git clone https://github.com/basumobai/v2board-for-Mundo-X-build.git panel-b
cd panel-b
COMPOSE_PROJECT_NAME=panel-b WEB_PORT=6601 GATEWAY_PORT=7002 WEB_WORKERS=2 bash init.sh
```

| 配置 | 第一套示例 | 第二套示例 | 含义 |
| --- | --- | --- | --- |
| 安装目录 | panel-a | panel-b | 源码、配置、日志、PID、vendor 分开 |
| COMPOSE_PROJECT_NAME | panel-a | panel-b | 容器、镜像及 Redis 卷的命名空间 |
| WEB_PORT | 6600 | 6601 | PHP 仅监听本机的端口 |
| GATEWAY_PORT | 7001 | 7002 | 宝塔反向代理应指向这个端口 |
| 数据库 | panel_a | panel_b | 独立库、独立授权用户 |
| WEB_WORKERS | 2 | 2 | 每个实例的 Web Worker 数量 |

安装器会将部署参数保存进 `.env`，以后在对应目录直接运行 `docker compose` 即可，不用每次重输。

Redis 继续使用每实例私有数据卷里的 Unix Socket，不开放 TCP 6379。即使每个容器内部都叫 `/data/redis.sock`，也不是同一个 Socket；安装器还会生成独立 Redis、缓存、Horizon 前缀和 Session Cookie 名。

**不要复制已安装目录的 `.env`，不要在安装后随意改实例名或使用另一个 `docker compose -p`。** 这可能创建新的 Redis 卷，导致旧队列暂时不可见。已有实例名或数据卷会让新安装器停止，不会自动接管或清空。

## 接上自己的域名和 HTTPS

在宝塔为面板域名新建站点并启用 HTTPS。反向代理目标填安装器最后显示的地址，例如：

```text
http://127.0.0.1:7002
```

不用代理到 Web 端口；Nginx 入口会直接提供静态资源。关闭代理缓存，完整保留 Authorization。手写 Nginx 时可使用下面的 location（端口按实际值替换）：

```nginx
location / {
    proxy_pass http://127.0.0.1:7002;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Authorization $http_authorization;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Port $server_port;
}
```

在 server 中设置 `client_max_body_size 32m;`，修改后先 `nginx -t` 再重载。不要公开 Web/Gateway、MySQL 或 Redis 端口。Cloudflare 如需使用，选择 Full (strict)，不要缓存后台、HTML、API 或订阅。

## 改端口或调整资源

在**该实例的** `.env` 中修改：

```dotenv
WEB_PORT=6601
GATEWAY_PORT=7002
WEB_WORKERS=2
WEB_MAX_REQUESTS=6600
HORIZON_MAX_PROCESSES=4
```

先确认新端口空闲，再执行：

```bash
docker compose config --quiet
docker compose up -d --wait --wait-timeout 180
```

端口和环境变量变更需要重建容器配置，单独 `docker compose restart` 不会应用新环境变量。如果改了 GATEWAY_PORT，也要修改宝塔的代理目标。既有安装未设置这些变量时，仍使用 6600/7001，不会擅自修改 Redis 前缀。

Web 默认 2 个 Worker，不再按整台宿主机 CPU 翻倍。队列默认固定 4 个工作进程：3 个处理订单、流量和统计，1 个处理邮件和 Telegram 通知，另有 Horizon 管理进程。各组按列出的队列顺序处理；设为 1 时所有队列共用一个工作进程。多个实例的 Worker 数量和内存开销会累加；先以小值运行，再根据队列积压、CPU 和内存调整，没有统一适用于所有服务器的最高性能配置。

## 这次性能改了什么

- 动态面板/主题配置按内容指纹缓存：内容不变时不再反复执行配置文件或强制失效 OPcache。每次读取仍检查内容变化，同一秒内等长修改、原子替换也能识别。
- 保留动态配置立即生效及路由变更的优雅重载，不启用会破坏该行为的 Laravel 配置/路由缓存。
- 保留 Composer 优化自动加载，安装和更新时预编译 Blade 视图。
- 默认禁用未经过本项目压测证明有益的 128 MB JIT 缓冲区；保留 OPcache 和时间戳检查。
- 修复 APP_ENV=production 没有 Horizon 队列配置的问题；队列规模按实例参数控制，不再探测整台主机内存决定进程数。
- 增加 HTTP 健康检查和容器日志轮转；继续保留数据库事务清理及请求结束断开连接的安全保护。

这一轮保留 PHP 8.2 / Laravel 8 / AdapterMan 及现有 API、业务表结构。**不是 Laravel 大版本升级，也不代表已经完成框架安全升级或业务负载性能压测。** 没有给出未经测量的 QPS 提升百分比。

## 已有站点更新

先备份数据库、`.env`、`config/v2board.php`、`config/theme/`、`storage/` 和自定义资源，备份放在项目目录之外并验证可恢复。然后确认工作区没有尚未处理的修改：

本次流量账本升级还必须保留**同一时间点的 Redis 数据卷备份**：旧版队列与待结算流量仍在 Redis。新版节点报告在 MySQL 的一个事务里同时记录报告 ID、用户流量和日统计；Redis 的旧流量批次只用于升级过渡。切勿单独回滚数据库或替换 Redis 卷后直接重放队列。

```bash
git status --short
git pull --ff-only
bash update.sh
```

此版本把 `docker/nginx.conf` 用作 Nginx 环境变量模板，请不要继续用旧的固定 `default.conf` 挂载。更新会保留已有 Compose 项目名、Redis 卷、密钥和前缀。脚本先停止 Web 和 Scheduler，让旧 Horizon 排空 `traffic_fetch` 与 `stat` 队列（最多等 10 分钟），再停止 Horizon、切换代码、运行迁移并结清遗留 Redis 批次。必要账本、索引、InnoDB 和重置字段检查失败时不会启动新版服务；请根据错误修复数据或恢复备份，不要跳过检查。更新期间入口会暂时不可用。自行改过部署文件时先合并差异，脚本不会替你覆盖未提交修改。

新版报告最多允许在接收后 30 天内自动重试；更旧的任务会进入失败队列，需要人工核账，避免账本清理后再次计费。流量重置每五分钟检查一次，当遗留 Redis 批次未结清时延后执行，成功重置的用户当天不会再次清零。上报以面板接收时刻归入账期；节点自身若把跨账期的使用量合并成一次上报，面板无法从这个汇总里还原每一字节的实际发生时刻。

原来的安装脚本只用于首次安装，不应用于更新或迁移旧数据库。

## 出错时看这里

```bash
docker compose ps
docker compose logs --tail=80 web gateway horizon scheduler
docker compose exec redis redis-cli -s /data/redis.sock ping
docker compose exec horizon php artisan horizon:status
```

| 情况 | 处理 |
| --- | --- |
| 端口占用、实例名冲突 | 选择未使用的端口/新实例名，不要结束未知进程或删除旧数据卷 |
| URL、邮箱、数据库密码错误或非空库 | 安装器在写入配置前停止；改正后可重跑，不要往已有业务库安装 |
| 已出现 `.env` 或 `config/v2board.php` | 先确认是否已经导入数据库；安装器不会重新执行建表，也不会删除配置 |
| 导入中途失败 | 保留配置和数据，检查数据库错误并从备份恢复或改用全新专用空库；不要直接删除 `.env` 重跑 |
| 已导入成功但启动失败 | 解决日志中的问题后运行下面的“恢复启动”，不重新执行安装器 |
| 修改配置似乎没生效 | 检查是否遗留 `bootstrap/cache/config.php`；不要执行 config:cache |
| 登录跳回或 Horizon 403 | 检查域名、Authorization 转发和 Redis；不要把环境改成 local 绕过权限 |

确认数据库导入和管理员创建**已成功**后的恢复启动：

```bash
docker compose up -d --wait redis
docker compose run --rm --no-deps installer php artisan config:clear
docker compose run --rm --no-deps installer php artisan view:cache
docker compose up -d --wait --wait-timeout 180
```

健康检查通过不等于所有业务都已验收。上线前实际验证：管理员登录、配置保存、节点新增/复制、节点流量上报、支付回调和邮件队列。分享日志前打码密码、JWT、订阅地址及节点 token。

**禁止作为日常操作执行：** `docker compose down -v`、`git reset --hard`、`php artisan config:cache`、`composer update`。前两项会丢失数据或本地修改，后两项会破坏动态配置或改变锁定依赖。
