# Mundo V2Board：Ubuntu、宝塔与 Docker 部署指南

本仓库在 Mundo V2Board 基础上提供可直接使用的 Docker 运行环境，并修复 AdapterMan 常驻进程下的配置刷新、数据库事务残留、Horizon 管理员鉴权和节点列表一致性问题。

本指南适用于：

- Ubuntu 22.04 或 24.04；
- Docker Engine 和 Docker Compose 插件；
- 宝塔 Nginx 负责公网 HTTPS；
- MySQL 运行在宿主机；
- Redis 运行在 Docker 中并通过 Unix Socket 连接；
- Cloudflare 可选。

## 1. 部署结构

```text
浏览器
  ↓ HTTPS
Cloudflare（可选）
  ↓
宝塔 Nginx :443
  ↓ http://127.0.0.1:7001
Docker gateway（Nginx）
  ↓ http://127.0.0.1:6600
Docker web（AdapterMan + Laravel）

web / horizon / scheduler
  ├─ 宿主机 MySQL：127.0.0.1:3306
  └─ Docker Redis：/data/redis.sock
```

`6600` 和 `7001` 只监听回环地址，不应开放到公网。Redis 不开放 TCP 端口。

仓库已经包含：

```text
Dockerfile
compose.yaml
docker/nginx.conf
docker/v2board-proxy-headers.inc
.dockerignore
```

不要再按照旧教程手工创建这些文件，也不要把其他 V2Board/XBoard 项目的 Compose 文件混进来。

## 2. 安装前准备

至少准备：

- 一个指向服务器的面板域名，例如 `panel.example.com`；
- 一个空的 MySQL 数据库；
- 该数据库的独立用户和强密码；
- 可用的 80、443、6600、7001 端口；
- 至少 2 GB 内存，推荐 4 GB。

检查端口：

```bash
ss -lntp | grep -E ':(3306|6600|7001)\b' || true
```

MySQL 可以监听 `127.0.0.1:3306`。安装前 `6600` 和 `7001` 应为空闲。

数据库必须是空库。如果同名数据库中已经存在 V2Board 表，不要直接重复安装。

## 3. 安装 Docker

如果已经能够成功运行 `docker run --rm hello-world`，跳过本节。

下面使用 [Docker 官方 Ubuntu apt 仓库](https://docs.docker.com/engine/install/ubuntu/)。以 `root` 运行：

```bash
apt update
apt install -y ca-certificates curl git

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

apt update
apt install -y \
  docker-ce \
  docker-ce-cli \
  containerd.io \
  docker-buildx-plugin \
  docker-compose-plugin

systemctl enable --now docker
```

如果 `apt` 报告 `docker.io`、`docker-compose`、`podman-docker`、`containerd` 或 `runc` 冲突，先停止安装，确认服务器上是否已有容器与数据。不要在未备份时盲目卸载现有容器环境。

安装后检查：

```bash
docker --version
docker compose version
systemctl is-active docker
docker run --rm hello-world
```

四项都成功后再继续。本指南使用带空格的 `docker compose`，不是旧命令 `docker-compose`。不要同时混用 Snap Docker、Ubuntu `docker.io` 和 Docker 官方包。

Docker 官方文档提醒，对外发布的容器端口可能绕过 UFW 的常规规则。本仓库因此将 `6600` 和 `7001` 明确绑定到 `127.0.0.1`，但仍应检查云防火墙与实际监听地址。

## 4. 下载仓库

```bash
cd /opt
git clone https://github.com/basumobai/v2board-for-Mundo-X-build.git mundo-v2board
cd /opt/mundo-v2board

git remote -v
git status --short
```

首次安装时应保持工作区干净。不要提前创建 `config/v2board.php`。

## 5. 准备安装参数

不要修改受 Git 跟踪的 `.env.example`，也不要提前复制成 `.env`。安装器会生成已忽略的 `.env`；如果提前存在 `.env`，安装器会为避免覆盖现有站点而停止。

请先准备好：

- V2Board 完整访问网址，例如 `https://panel.example.com`；
- 数据库地址，本方案为 `127.0.0.1`；
- 空数据库的库名与专用用户名；
- 数据库密码；
- 管理员邮箱。

完整网址是正在搭建的 V2Board 域名，不是宝塔面板自己的管理域名。安装器会隐藏数据库密码输入。不要把真实密码提交到 Git，也不要粘贴到聊天、截图或可公开复制的命令中。

确认应用尚未安装：

```bash
cd /opt/mundo-v2board
test ! -f .env && echo '首次安装状态正常' || echo '.env 已存在，请先确认是否安装过'
```

## 6. 构建镜像和安装依赖

```bash
cd /opt/mundo-v2board

docker compose config --quiet
docker compose build --pull
docker compose up -d --wait redis
docker compose exec redis redis-cli -s /data/redis.sock ping
```

Redis 应返回：

```text
PONG
```

安装锁定的 Composer 依赖：

```bash
docker compose run --rm web \
  composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction
```

本仓库已经在 `composer.json` 和 `composer.lock` 中包含 AdapterMan。不要在生产服务器再次执行 `composer require joanhey/adapterman`。

检查运行环境：

```bash
docker compose run --rm --no-deps web php -v
docker compose run --rm web composer check-platform-reqs --no-dev
docker compose run --rm web php -l webman.php
```

## 7. 测试宿主机 MySQL

因为 `web` 使用宿主机网络，数据库地址应填写 `127.0.0.1`。

可以在不显示密码的情况下测试：

```bash
cd /opt/mundo-v2board
read -rsp '数据库密码（不会显示）: ' DB_TEST_PASSWORD
echo

docker compose run --rm -e DB_TEST_PASSWORD="$DB_TEST_PASSWORD" web php -r '
$password = getenv("DB_TEST_PASSWORD");
$pdo = new PDO(
    "mysql:host=127.0.0.1;port=3306;dbname=v2board;charset=utf8mb4",
    "v2board",
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "数据库连接成功\n";
'

unset DB_TEST_PASSWORD
```

将示例中的数据库名和用户名替换成自己的值。不要把真实密码复制到聊天、截图或 Shell 脚本。

## 8. 运行安装器

```bash
cd /opt/mundo-v2board
docker compose run --rm web php artisan v2board:install
```

安装器询问数据库信息时填写：

```text
面板完整网址：https://你的 V2Board 域名
数据库地址：127.0.0.1
数据库名：自己的数据库名
数据库用户名：自己的数据库用户
数据库密码：自己的数据库密码（输入时不显示）
```

安装器会先检查数据库是否为空库。只要存在任何表就会停止，不会覆盖旧数据。
完整面板网址会同时写入 `.env` 和面板运行时配置；使用 HTTPS URL 时会同步启用 HTTPS URL 生成。

成功后安装器会输出：

- 管理员邮箱；
- 随机管理员密码；
- 随机后台路径。

立即把这些信息保存在密码管理器中。首次登录后更换管理员密码。

不显示密码地检查关键运行参数：

```bash
grep -E \
  '^(APP_ENV|APP_DEBUG|APP_URL|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|CACHE_DRIVER|QUEUE_CONNECTION|SESSION_DRIVER|REDIS_HOST|REDIS_PORT|SESSION_SECURE_COOKIE)=' \
  .env
```

不要运行 `cat .env`，也不要输出 `DB_PASSWORD`。

如果安装失败后 `.env` 已经产生，不要立即重复运行安装器。先检查数据库中是否已经创建表。只有在确认数据库仍为空、安装确实未完成时，才能删除失败安装产生的 `.env` 后重新安装。

## 9. 启动服务

项目的动态配置不能使用 Laravel 聚合配置缓存。启动前清理一次旧缓存：

```bash
cd /opt/mundo-v2board
docker compose run --rm web php artisan config:clear
test ! -f bootstrap/cache/config.php && echo '配置缓存已清除'

docker compose up -d
docker compose ps
```

应看到五个服务：

```text
web
gateway
horizon
scheduler
redis (healthy)
```

检查日志：

```bash
docker compose logs --tail=100 web
docker compose logs --tail=100 gateway
docker compose logs --tail=100 horizon
docker compose logs --tail=100 scheduler
```

Web 日志应包含 AdapterMan/Workerman `Start success`，Horizon 应显示启动成功。

## 10. 本机验收

```bash
ss -lntp | grep -E ':(6600|7001)\b'

curl -sS -o /dev/null -w '6600 首页：%{http_code}\n' http://127.0.0.1:6600/
curl -sS -o /dev/null -w '7001 首页：%{http_code}\n' http://127.0.0.1:7001/
```

两个端口都应返回 `200`，并且只监听 `127.0.0.1`。

如果首页第一次访问时还没有主题配置，应用会安全地从 `public/theme/default/config.json` 初始化，不需要运行 `config:cache`。

## 11. 宝塔反向代理

在宝塔中创建面板域名站点，将反向代理目标设置为：

```text
http://127.0.0.1:7001
```

至少传递以下请求头：

```nginx
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Port $server_port;
```

不要把宝塔面板自己的管理域名当成 V2Board 的 `APP_URL`。`APP_URL` 必须是访问 V2Board 的完整 HTTPS 域名。

配置完成后：

```bash
curl -I http://127.0.0.1:7001/
curl -I https://panel.example.com/
```

## 12. HTTPS 与 Cloudflare

推荐：

- 宝塔源站配置有效证书；
- Cloudflare SSL/TLS 模式使用 `Full (strict)`；
- 不使用 `Flexible`；
- API 和后台路径不创建 Cache Everything 规则；
- 分享日志时移除管理员令牌、订阅令牌和节点通信密钥。

Cloudflare 开启代理后，域名解析显示 Cloudflare IP 属于正常现象。

## 13. 功能验收

### 13.1 登录和 Horizon

登录后台后确认：

- 不会短暂进入后又跳回登录页；
- `/monitor/api/stats` 在携带有效管理员令牌时返回 `200`；
- 未登录或普通用户不能访问 Horizon。

### 13.2 动态配置

依次测试：

- 开启或关闭新用户注册；
- 修改流量重置方式；
- 修改站点名称；
- 保存主题配置。

每次保存后立即重新打开对应设置，数值应保持为新值，不需要刷新页面或重启容器。

本仓库会对 `config/v2board.php` 和主题配置执行原子写入，并在每个 HTTP 请求开始时重新载入动态配置。不要重新创建 `bootstrap/cache/config.php`。

只有后台路径、订阅路径或强制 HTTPS 发生变化时，Web 才会在响应发送后使用 `SIGUSR2` 优雅重载路由；不会使用 `SIGTERM`。

### 13.3 节点列表

测试：

- 点击顶部“+”新增节点；
- 复制 Mundo X、VMess 等节点；
- 保存后观察列表；
- 连续刷新节点接口。

新节点应立即出现在列表中，连续请求不能在新旧列表之间跳动。

### 13.4 管理界面

仓库已经通过 `public/assets/admin/custom.css` 和 `custom.js` 提供现代化管理界面，不需要安装 Node.js，也不要修改或重新压缩原版 `umi.js`：

- 桌面端确认侧栏、顶栏、数据卡片、表格和抽屉表单显示正常；
- 手机端确认侧栏可以打开和关闭，节点页“+”、搜索框与保存按钮均可点击；
- 使用键盘 `Tab` 确认当前焦点清晰可见；
- 浏览器页面缩放不应被禁止；
- 明暗模式以及默认、黑色、暗蓝、绿色主题切换后，自定义界面不应退回旧样式。

自定义 CSS 和 JavaScript 使用各自文件修改时间生成缓存版本。执行 `git pull` 后，浏览器会请求新的资源 URL，通常不需要手工清除 Cloudflare 或浏览器缓存。可以检查资源是否返回 `200`：

```bash
curl -I https://panel.example.com/assets/admin/custom.css
curl -I https://panel.example.com/assets/admin/custom.js
```

## 14. 已知错误与处理

### `docker: command not found`

Docker 尚未安装或当前终端找不到 Docker。完成第 3 节后重新登录 Shell。

### `no configuration file provided: not found`

不在项目目录执行了 Compose。先运行：

```bash
cd /opt/mundo-v2board
docker compose ps
```

### `Module already loaded`

不要把 `extension=igbinary.so` 或 `extension=redis.so` 加回 `cli-php.ini`。Docker 已通过 `conf.d` 加载扩展。

### 安装器数据库连接失败

检查：

- 数据库地址是否为 `127.0.0.1`；
- MySQL 是否监听 3306；
- 数据库名、用户名、密码是否对应；
- 用户是否有该数据库权限；
- 数据库是否为空。

### 登录成功后跳回登录页

先检查：

```bash
docker compose logs --since=5m gateway | grep -E '/monitor/api/stats|/passport/auth/login|/user/info'
```

本仓库已经让 Horizon 使用 V2Board 管理员令牌鉴权。如果仍出现 `403`，检查是否运行了本仓库当前代码、Redis 是否正常，以及宝塔是否完整转发 `Authorization` 请求头。不要把 `APP_ENV` 改成 `local` 绕过权限。

### 保存配置返回 `500`

检查：

```bash
docker compose logs --since=5m gateway | grep '/config/save'
docker compose logs --since=5m web
test ! -f bootstrap/cache/config.php && echo '没有配置缓存'
```

不要执行 `php artisan config:cache`。不要恢复原版请求内的 `Artisan::call('config:cache')`、`opcache_reset()` 强制成功判断或 `posix_kill(..., 15)`。

### 显示保存成功但数值马上回退

确认 OPcache：

```bash
docker compose exec web php -i | grep -E 'opcache.(validate_timestamps|revalidate_freq|enable_file_override)'
```

应为：

```text
opcache.validate_timestamps => On
opcache.revalidate_freq => 0
opcache.enable_file_override => Off
```

然后确认没有配置缓存并重建 Web：

```bash
docker compose run --rm web php artisan config:clear
docker compose up -d --force-recreate web
```

### 节点新增或复制成功，但列表显示旧数据

查看是否捕获到遗留事务：

```bash
docker compose logs --since=10m web | grep -E 'transaction|事务'
```

本仓库在 Laravel HTTP 中间件内检查并清理事务，并在每次请求结束后断开数据库连接。不要把 `DB::purge()`、`DB::disconnect()` 或数据库 Facade 调用添加到 `webman.php` 顶层；这可能在 Laravel 尚未初始化时造成 502。

### `/api/v1/server/UniProxy/...` 返回 `500`

这是节点与面板配置不匹配，不是登录或节点列表缓存问题。检查节点 ID、`node_type`、面板地址、通信密钥和节点端版本。日志中的 `token` 必须打码。

### Gateway 提示默认配置只读

Nginx 官方入口脚本可能提示无法修改只读挂载的 `default.conf`。只要随后显示配置完成且 Gateway 正常启动，这通常不是故障。

## 15. 日常维护

```bash
cd /opt/mundo-v2board

docker compose ps
docker compose logs --tail=100 web
docker compose logs --tail=100 gateway
docker compose restart web
docker compose restart horizon scheduler
```

不要运行：

```text
docker compose down -v
```

`-v` 会删除 Redis 数据卷。

## 16. 备份

至少备份：

- MySQL 数据库；
- `.env`；
- `config/v2board.php`；
- `config/theme/`；
- `storage/` 中需要保留的文件；
- 自定义静态资源。

备份文件应放到项目目录之外，并定期验证能够恢复。

## 17. 更新

更新前先备份，然后检查本地修改：

```bash
cd /opt/mundo-v2board
git status --short
git diff
```

工作区干净时：

```bash
git pull --ff-only
docker compose build --pull
docker compose run --rm web composer install \
  --no-dev --prefer-dist --optimize-autoloader --no-interaction
docker compose run --rm web php artisan config:clear
docker compose up -d
```

不要在生产目录执行 `git reset --hard`，也不要直接运行会重置仓库的来源不明更新脚本。

## 18. 最终检查清单

- [ ] `APP_ENV=production`；
- [ ] `APP_DEBUG=false`；
- [ ] 五个容器正常，Redis healthy；
- [ ] 6600、7001 只监听 `127.0.0.1`；
- [ ] 3306、6379、6600、7001 未向公网开放；
- [ ] HTTP 与 HTTPS 首页返回 200；
- [ ] 后台登录不会跳回登录页；
- [ ] Horizon 管理接口正常鉴权；
- [ ] 新用户注册开关保存后不会回退；
- [ ] 流量重置方式保存后不会回退；
- [ ] 主题配置能够保存；
- [ ] 新增和复制节点无需刷新即可看到；
- [ ] 管理界面自定义 CSS、JavaScript 返回 200，桌面端与手机端均可操作；
- [ ] 键盘焦点可见，浏览器页面缩放未被禁止；
- [ ] `bootstrap/cache/config.php` 不存在；
- [ ] 没有使用 `SIGTERM` 重启 Web；
- [ ] 数据库和配置文件有可恢复备份；
- [ ] 管理员初始密码已经更换；
- [ ] Cloudflare 使用 `Full (strict)`。

全部通过后，才算部署完成，而不仅是容器处于 `Up` 状态。
