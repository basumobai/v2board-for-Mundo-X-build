# Mundo V2Board：Docker 安装、上线与维护指南

本指南只适用于当前仓库：

```text
https://github.com/basumobai/v2board-for-Mundo-X-build
```

仓库已经包含 Docker 运行环境、锁定的 Composer 依赖、首次安装脚本，以及针对 AdapterMan 常驻进程的配置刷新、数据库连接清理、Horizon 鉴权、节点列表一致性和管理界面修复。

因此，部署时应直接使用仓库内的文件。不要再从旧教程复制 Dockerfile、Compose 配置、`composer.json` 或 PHP 补丁，也不要重新执行旧的手工修复。

## 目录

1. [部署结构与重要原则](#1-部署结构与重要原则)
2. [安装前准备](#2-安装前准备)
3. [安装 Docker](#3-安装-docker)
4. [准备域名和 MySQL](#4-准备域名和-mysql)
5. [下载并检查仓库](#5-下载并检查仓库)
6. [推荐：使用 init.sh 完成首次安装](#6-推荐使用-initsh-完成首次安装)
7. [备用：逐步手动安装](#7-备用逐步手动安装)
8. [启动与本机验收](#8-启动与本机验收)
9. [配置宝塔反向代理和 HTTPS](#9-配置宝塔反向代理和-https)
10. [上线后的完整验收](#10-上线后的完整验收)
11. [常见错误与当前正确处理方式](#11-常见错误与当前正确处理方式)
12. [更新现有部署](#12-更新现有部署)
13. [备份与日常维护](#13-备份与日常维护)
14. [最终检查清单](#14-最终检查清单)

---

## 1. 部署结构与重要原则

### 1.1 本仓库采用的结构

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

Docker web / horizon / scheduler
  ├─ 宿主机 MySQL：127.0.0.1:3306
  └─ Docker Redis：/data/redis.sock
```

Compose 会运行五个服务：

| 服务 | 用途 |
| --- | --- |
| `web` | PHP 8.2、AdapterMan、Workerman 和 Laravel |
| `gateway` | 监听 `127.0.0.1:7001`，提供静态文件并转发动态请求 |
| `horizon` | 处理 Laravel 队列 |
| `scheduler` | 每分钟执行一次 Laravel 计划任务 |
| `redis` | 使用共享 Unix Socket，不开放 Redis TCP 端口 |

### 1.2 必须遵守的原则

- `6600` 和 `7001` 只能监听 `127.0.0.1`，不能直接暴露公网。
- MySQL 在宿主机运行；由于应用容器使用 host 网络，数据库地址填写 `127.0.0.1`。
- Redis 在 Docker 中运行，应用通过 `/data/redis.sock` 访问，不填写 `redis:6379`。
- 不要执行 `php artisan config:cache`。面板配置和主题配置需要在常驻 Worker 中动态刷新。
- 不要执行 `docker compose down -v`。`-v` 会删除 Redis 数据卷。
- 不要在生产目录执行 `git reset --hard`。
- 不要把其他 V2Board、XBoard 或旧 Mundo 教程的文件覆盖进本仓库。
- 不要公开 `.env`、数据库密码、管理员密码、JWT、订阅令牌或节点通信密钥。

### 1.3 仓库已经提供的构建文件

```text
Dockerfile
compose.yaml
docker/nginx.conf
docker/v2board-proxy-headers.inc
.dockerignore
composer.lock
init.sh
update.sh
```

首次部署通常只需要准备服务器、域名和数据库，然后运行 `./init.sh`。本指南同时保留逐步手动流程，方便安装失败时定位具体步骤。

---

## 2. 安装前准备

### 2.1 推荐环境

- Ubuntu 22.04、24.04 或 Docker 官方当前支持的 Ubuntu LTS；
- 64 位服务器；
- 至少 2 GB 内存，推荐 4 GB；
- 至少 10 GB 可用磁盘；
- root 用户，或者能够使用 `sudo`；
- 一个面板域名；
- 一个全新的空 MySQL 数据库；
- 该数据库的独立用户名和强密码；
- 宝塔 Nginx，可选 Cloudflare。

### 2.2 本文占位值

下面统一使用：

```text
项目目录：/opt/mundo-v2board
面板域名：panel.example.com
数据库名：v2board
数据库用户：v2board
数据库端口：3306
```

实际部署时必须换成自己的值。面板域名指正在部署的 V2Board 域名，不是宝塔面板的管理域名。

### 2.3 检查系统和端口

```bash
cat /etc/os-release
uname -m
free -h
df -h /

ss -lntp | grep -E ':(3306|6600|7001)\b' || true
```

预期结果：

- MySQL 已安装时，`3306` 可以有监听；
- 首次部署前，`6600` 和 `7001` 应为空闲；
- 如果 `6600` 或 `7001` 已被其他程序占用，先确认占用者，不要直接结束未知进程。

---

## 3. 安装 Docker

如果下面四项都成功，可以直接进入第 4 节：

```bash
docker --version
docker compose version
systemctl is-active docker
docker run --rm hello-world
```

本指南使用 Docker 官方 apt 仓库，不使用 Snap Docker，也不混用 Ubuntu 的 `docker.io`、`podman-docker` 或旧版 `docker-compose`。

官方参考：[Install Docker Engine on Ubuntu](https://docs.docker.com/engine/install/ubuntu/)

以 root 用户运行：

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

验证：

```bash
docker --version
docker compose version
systemctl is-active docker
docker run --rm hello-world
```

如果 apt 报告 `docker.io`、`docker-compose`、`docker-compose-v2`、`podman-docker`、`containerd` 或 `runc` 冲突，先检查服务器是否已经运行其他容器。不要在未备份现有 Docker 数据时盲目卸载。

Docker 官方文档提醒：Docker 发布的容器端口可能绕过 UFW 的常规规则。本仓库没有把应用端口发布到公网，但仍应在部署后检查实际监听地址和云防火墙。

---

## 4. 准备域名和 MySQL

### 4.1 域名

为 V2Board 创建 DNS 记录，例如：

```text
panel.example.com → 服务器公网 IPv4
```

如果使用 Cloudflare：

- 申请源站证书期间可以暂时使用 DNS only；
- 上线后可以开启代理；
- Cloudflare 开启代理后，DNS 查询得到 Cloudflare IP 属于正常现象。

检查解析：

```bash
echo -n '服务器公网 IPv4：'
curl -4 -s https://api.ipify.org
echo

echo '域名 IPv4：'
getent ahostsv4 panel.example.com | awk '{print $1}' | sort -u
```

### 4.2 MySQL

在宝塔数据库页面创建：

```text
数据库：v2board
用户名：v2board
字符集：utf8mb4
访问权限：仅本机
密码：独立强密码
```

要求：

- 必须使用空数据库；
- 数据库用户必须拥有该数据库的全部必要权限；
- MySQL 必须能够通过宿主机 `127.0.0.1:3306` 访问；
- 不要把 `3306` 开放到公网。

检查 MySQL：

```bash
systemctl is-active mysql || systemctl is-active mysqld
ss -lntp | grep ':3306 '
```

数据库名和用户名可以相同，但它们是两个不同概念，安装器会分别询问。

---

## 5. 下载并检查仓库

先确认目标目录不存在：

```bash
test ! -e /opt/mundo-v2board \
  && echo '目标目录可用' \
  || echo '目标目录已经存在，请先检查，禁止直接覆盖'
```

克隆主分支：

```bash
cd /opt
git clone --branch master \
  https://github.com/basumobai/v2board-for-Mundo-X-build.git \
  mundo-v2board

cd /opt/mundo-v2board
```

检查仓库：

```bash
git remote -v
git branch --show-current
git log -1 --oneline
git status --short

test -f artisan \
  && test -f composer.json \
  && test -f composer.lock \
  && test -f webman.php \
  && test -f Dockerfile \
  && test -f compose.yaml \
  && test -x init.sh \
  && echo '仓库结构正常'
```

首次安装时 `git status --short` 应无输出，并且不应存在 `.env`：

```bash
test ! -f .env \
  && echo '首次安装状态正常' \
  || echo '.env 已存在，请先确认此目录是否安装过'
```

不要提前复制 `.env.example`。安装器会复制模板、生成应用密钥并安全写入数据库参数。

---

## 6. 推荐：使用 init.sh 完成首次安装

### 6.1 先准备好安装器需要的信息

- V2Board 完整网址，例如 `https://panel.example.com`；
- 数据库地址：本方案填写 `127.0.0.1`；
- 数据库名；
- 数据库用户名；
- 数据库密码；
- 管理员邮箱。

完整网址必须包含 `http://` 或 `https://`，并且不要带末尾斜杠。

### 6.2 运行安装脚本

```bash
cd /opt/mundo-v2board
./init.sh
```

`init.sh` 会按顺序执行：

1. 检查当前目录是否已经有 `.env`；
2. 校验 `compose.yaml`；
3. 构建 PHP 运行镜像；
4. 启动并等待 Redis healthy；
5. 通过 Unix Socket 测试 Redis；
6. 按 `composer.lock` 安装生产依赖；
7. 启动交互式 V2Board 安装器；
8. 清除 Laravel 配置缓存；
9. 启动全部五个服务；
10. 显示容器状态。

安装器提示时填写：

```text
面板完整网址：https://panel.example.com
数据库地址：127.0.0.1
数据库名：自己的数据库名
数据库用户名：自己的数据库用户
数据库密码：自己的数据库密码（输入不会显示）
管理员邮箱：自己的管理员邮箱
```

安装器会：

- 验证完整网址；
- 生成新的 `APP_KEY`；
- 将 HTTPS 状态写入面板运行时配置；
- 测试数据库连接；
- 检查数据库是否为空；
- 导入数据库结构；
- 创建管理员；
- 生成随机管理员密码和随机后台路径。

成功时立即保存：

```text
管理员邮箱
随机管理员密码
随机后台路径
```

不要把这些信息发到聊天、公开日志或截图中。首次登录后立即修改管理员密码。

### 6.3 安装完成后的安全检查

只输出不含密码的环境变量：

```bash
cd /opt/mundo-v2board

grep -E \
  '^(APP_ENV|APP_DEBUG|APP_URL|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|CACHE_DRIVER|QUEUE_CONNECTION|SESSION_DRIVER|REDIS_HOST|REDIS_PORT|SESSION_SECURE_COOKIE)=' \
  .env
```

关键值应类似：

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.example.com
DB_HOST=127.0.0.1
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=/data/redis.sock
REDIS_PORT=0
SESSION_SECURE_COOKIE=true
```

不要运行 `cat .env`，避免把数据库密码一起输出。

---

## 7. 备用：逐步手动安装

只有在需要观察每一步结果时才使用本节。它与 `init.sh` 执行的是同一套流程，不要自动流程和手动流程重复执行。

### 7.1 校验 Compose

```bash
cd /opt/mundo-v2board
docker compose config --quiet
echo "Compose 校验退出码：$?"
```

退出码必须是 `0`。

### 7.2 构建 PHP 镜像

```bash
docker compose build --pull web

docker image inspect mundo-v2board-local:latest \
  --format '镜像 ID={{.Id}} 创建时间={{.Created}}'
```

### 7.3 启动并测试 Redis

```bash
docker compose up -d --wait redis
docker compose exec redis redis-cli -s /data/redis.sock ping
```

必须返回：

```text
PONG
```

### 7.4 安装锁定依赖

```bash
docker compose run --rm web \
  composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction
```

检查 PHP、扩展和依赖：

```bash
docker compose run --rm --no-deps web php -v

docker compose run --rm --no-deps web php -m | \
  grep -E '^(bcmath|curl|gd|igbinary|intl|mbstring|pcntl|pdo_mysql|posix|redis|sockets|zip)$' | \
  sort

docker compose run --rm web composer check-platform-reqs --no-dev
docker compose run --rm web php -l webman.php
```

AdapterMan 已经写入 `composer.json` 和 `composer.lock`。不要在生产服务器再次执行：

```text
composer require joanhey/adapterman
```

### 7.5 可选：提前测试 MySQL

下面的命令不会显示密码。必须把数据库名和用户名替换成自己的值：

```bash
read -rsp '数据库密码（不会显示）: ' DB_TEST_PASSWORD
echo

docker compose run --rm \
  -e DB_TEST_PASSWORD="$DB_TEST_PASSWORD" \
  web php -r '
$pdo = new PDO(
    "mysql:host=127.0.0.1;port=3306;dbname=v2board;charset=utf8mb4",
    "v2board",
    getenv("DB_TEST_PASSWORD"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "数据库连接成功\\n";
'

unset DB_TEST_PASSWORD
```

### 7.6 运行安装器

```bash
docker compose run --rm web php artisan v2board:install
```

安装完成后：

```bash
docker compose run --rm web php artisan config:clear
test ! -f bootstrap/cache/config.php && echo '配置缓存已清除'

docker compose up -d
docker compose ps
```

---

## 8. 启动与本机验收

### 8.1 容器状态

```bash
cd /opt/mundo-v2board
docker compose ps
```

应看到：

```text
web
gateway
horizon
scheduler
redis (healthy)
```

### 8.2 服务日志

```bash
docker compose logs --tail=100 web
docker compose logs --tail=100 gateway
docker compose logs --tail=100 horizon
docker compose logs --tail=100 scheduler
```

正常特征：

- Web 显示 AdapterMan/Workerman `Start success`；
- Horizon 显示启动成功；
- Scheduler 持续执行计划任务；
- Redis 为 healthy；
- 日志中没有持续重复的 4xx/5xx 或崩溃重启。

### 8.3 端口和 HTTP

```bash
ss -lntp | grep -E ':(6600|7001)\b'

curl -sS -o /dev/null \
  -w '6600 首页：%{http_code}\\n' \
  http://127.0.0.1:6600/

curl -sS -o /dev/null \
  -w '7001 首页：%{http_code}\\n' \
  http://127.0.0.1:7001/
```

要求：

- 两个首页都返回 `200`；
- `6600` 和 `7001` 只监听 `127.0.0.1`；
- `6379` 没有对外 TCP 监听；
- `3306` 没有对公网开放。

如果首页第一次访问时还没有主题配置，应用会从 `public/theme/default/config.json` 安全初始化，不需要执行 `config:cache`。

---

## 9. 配置宝塔反向代理和 HTTPS

### 9.1 宝塔站点

在宝塔中新建 V2Board 域名站点，例如：

```text
panel.example.com
```

不要使用宝塔面板自己的管理域名。

### 9.2 反向代理

将代理目标设置为：

```text
http://127.0.0.1:7001
```

请求头至少包含：

```nginx
proxy_http_version 1.1;
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Port $server_port;
proxy_set_header Authorization $http_authorization;
```

保存后检查宝塔 Nginx：

```bash
nginx -t
```

宝塔使用自带 Nginx 路径时，可以在宝塔界面执行配置检查和重载。

### 9.3 HTTPS

- 为 V2Board 域名申请有效证书；
- 开启 HTTPS；
- 可以开启 HTTP 自动跳转 HTTPS；
- `.env` 中的 `APP_URL` 必须与实际 HTTPS 域名一致；
- 使用 HTTPS 时，`SESSION_SECURE_COOKIE=true`。

### 9.4 Cloudflare

推荐：

- SSL/TLS 模式使用 `Full (strict)`；
- 不使用 `Flexible`；
- 不为整个站点启用 Cache Everything；
- 后台路径和 `/api/*` 必须绕过 HTML/API 缓存；
- 静态 CSS、JavaScript、字体和图片可以使用正常浏览器缓存；
- 不要让 Cloudflare 修改或移除 `Authorization` 请求头。

### 9.5 公网检查

```bash
curl -I https://panel.example.com/
```

再访问安装器输出的后台路径：

```text
https://panel.example.com/随机后台路径
```

---

## 10. 上线后的完整验收

### 10.1 登录与鉴权

- 管理员能够登录；
- 登录后不会短暂进入又跳回登录页；
- 刷新后台后仍保持登录；
- `/monitor/api/stats` 携带有效管理员令牌时返回 `200`；
- 未登录用户和普通用户不能访问 Horizon。

### 10.2 动态配置

依次修改并保存：

- 开启或关闭新用户注册；
- 流量重置方式；
- 站点名称；
- 主题配置；
- 后台路径或强制 HTTPS（如确有需要）。

保存后立即重新打开对应页面，数值应保持为新值，不需要手工刷新页面或重启容器。

当前仓库会：

- 原子写入 `config/v2board.php` 和主题配置；
- 在每次 HTTP 请求开始时重新载入动态配置；
- 删除旧的 Laravel 聚合配置缓存；
- 只在路由相关设置变化时，在响应发出后使用 `SIGUSR2` 优雅重载 Worker。

不要重新创建 `bootstrap/cache/config.php`。

### 10.3 节点管理

测试：

- 点击节点管理顶部“+”；
- 新增 Mundo X、VMess、VLESS 或其他节点；
- 复制已有节点；
- 保存后观察列表；
- 连续刷新节点接口。

新节点或副本应立即出现在列表中，不应必须刷新整页，也不能在连续请求中出现新旧数据交替。

### 10.4 管理界面

管理端现代化界面由下面两个文件提供：

```text
public/assets/admin/custom.css
public/assets/admin/custom.js
```

不需要安装 Node.js，也不要修改或重新压缩原版 `umi.js`。

检查：

- 桌面端侧栏、顶栏、卡片、表格、表单、抽屉和弹窗；
- 手机端侧栏开关、节点页“+”、搜索框和保存按钮；
- 使用键盘 `Tab` 时焦点清晰可见；
- 浏览器允许页面缩放；
- 默认、黑色、暗蓝、绿色主题切换后仍保持现代化样式；
- 减少动画系统设置能够生效。

检查静态资源：

```bash
curl -I https://panel.example.com/assets/admin/custom.css
curl -I https://panel.example.com/assets/admin/custom.js
```

两个资源都应返回 `200`。Blade 会根据文件修改时间生成资源版本，因此更新代码后通常不需要手工清除 Cloudflare 或浏览器缓存。

---

## 11. 常见错误与当前正确处理方式

### 11.1 `docker: command not found`

Docker 尚未安装，或者安装后当前 Shell 没有找到命令。完成第 3 节并重新登录 Shell。

### 11.2 `no configuration file provided: not found`

Compose 命令不在项目目录执行：

```bash
cd /opt/mundo-v2board
docker compose ps
```

### 11.3 `Module already loaded`

不要把下面内容加回 `cli-php.ini`：

```text
extension=igbinary.so
extension=redis.so
```

Docker 镜像已经通过 `/usr/local/etc/php/conf.d/` 加载这些扩展。

### 11.4 安装器提示数据库连接失败

检查：

```bash
systemctl is-active mysql || systemctl is-active mysqld
ss -lntp | grep ':3306 '
```

然后确认：

- 数据库地址是 `127.0.0.1`；
- 数据库名、用户名和密码完全对应；
- 用户拥有目标数据库权限；
- 密码中字符没有被手工复制错误；
- MySQL 没有限制本机连接。

安装器会在测试数据库之前创建 `.env`。失败后 `init.sh` 再次运行会因为 `.env` 已存在而停止。

此时不要直接反复删除文件重装。先确认：

1. 这是全新安装，不是现有站点；
2. 目标数据库是否仍为空；
3. 是否已经导入部分表。

只有确认数据库仍为空且没有需要保留的数据时，才删除失败安装产生的 `.env` 并重新运行。数据库已经出现部分表时，应先重新创建一个空数据库或安全清空该专用新库，再重试。

### 11.5 安装器提示数据库不是空库

安装器拒绝覆盖已有表，这是安全保护。不要绕过检查。

- 新部署：创建新的空数据库；
- 迁移旧站：使用数据库备份恢复和项目更新流程，不要执行首次安装器；
- 不确定数据用途：先备份并停止操作。

### 11.6 登录成功后跳回登录页

先检查请求：

```bash
docker compose logs --since=5m gateway 2>&1 | \
  grep -E '/passport/auth/login|/user/info|/monitor/api/stats'
```

再检查 Redis：

```bash
docker compose exec redis redis-cli -s /data/redis.sock ping
```

当前仓库已经修复 Horizon 管理员鉴权和代理信任设置。如果仍发生：

- 确认运行的是本仓库当前 `master`；
- 确认宝塔保留 `Authorization`；
- 确认 Cloudflare 没有缓存后台或 API；
- 确认 `APP_URL` 与实际域名一致；
- 确认 Redis healthy。

不要把 `APP_ENV` 改成 `local` 绕过权限。

### 11.7 后台保存配置返回 `500`

```bash
docker compose logs --since=5m gateway 2>&1 | grep '/config/save'
docker compose logs --since=5m web
test ! -f bootstrap/cache/config.php && echo '没有 Laravel 配置缓存'
```

当前仓库已经移除旧版危险流程。不要恢复：

- 请求内的 `Artisan::call('config:cache')`；
- 把 `opcache_reset() === false` 当成保存失败；
- 使用 `SIGTERM` 结束 Web 主进程；
- `posix_kill(..., 15)`。

### 11.8 显示保存成功，但值立即回退

检查 OPcache：

```bash
docker compose exec web php -i | \
  grep -E 'opcache.(validate_timestamps|revalidate_freq|enable_file_override)'
```

应为：

```text
opcache.validate_timestamps => On
opcache.revalidate_freq => 0
opcache.enable_file_override => Off
```

清除遗留配置缓存并重建 Web 容器：

```bash
docker compose run --rm web php artisan config:clear
test ! -f bootstrap/cache/config.php
docker compose up -d --force-recreate web
```

不要执行 `config:cache`。

### 11.9 新增或复制节点成功，但列表仍是旧数据

```bash
docker compose logs --since=10m web | \
  grep -E 'transaction|Database connection cleanup|事务'
```

当前仓库通过 HTTP 中间件清理遗留事务，并在请求结束后断开数据库连接。

不要把 `DB::purge()`、`DB::disconnect()` 或数据库 Facade 调用放到 `webman.php` 顶层。Laravel 尚未初始化时执行这些代码可能导致 Web 无法启动并出现 `502`。

### 11.10 修改代码后出现 `502`

先检查服务和语法：

```bash
docker compose ps
docker compose logs --tail=120 web
docker compose exec web php -l webman.php
```

如果修改的是 PHP 文件，对具体文件执行 `php -l`。修正语法后：

```bash
docker compose restart web horizon scheduler
docker compose ps
```

不要通过反复重启掩盖语法错误。

### 11.11 管理界面仍显示旧样式

检查资源：

```bash
curl -I https://panel.example.com/assets/admin/custom.css
curl -I https://panel.example.com/assets/admin/custom.js
```

然后确认服务器已拉取最新 `master`，并重启 Web：

```bash
git status --short
git log -1 --oneline
docker compose restart web
```

如果资源 URL 已带新版本但浏览器仍显示旧内容，再执行一次强制刷新。通常不需要清除整个 Cloudflare 缓存。

### 11.12 `/api/v1/server/UniProxy/...` 返回 `500`

这通常是节点与面板配置不匹配，不是管理员登录或节点列表缓存问题。检查：

- 节点 ID；
- `node_type`；
- 面板完整网址；
- 节点通信密钥；
- 节点端版本和协议支持。

分享日志前必须打码 `token`。

### 11.13 Gateway 提示默认配置只读

Nginx 官方镜像入口脚本可能提示无法修改只读挂载的 `default.conf`。只要随后显示配置完成，Gateway 正常运行且 `7001` 返回 `200`，通常不是故障。

---

## 12. 更新现有部署

### 12.1 更新前必须备份

至少备份：

- MySQL 数据库；
- `.env`；
- `config/v2board.php`；
- `config/theme/`；
- `storage/` 中需要保留的文件；
- 自己新增的静态资源。

备份应放在项目目录之外。

### 12.2 检查本地修改

```bash
cd /opt/mundo-v2board
git status --short
git diff
```

如果有输出，先确认这些修改是否仍需要保留。不要直接覆盖，也不要执行 `git reset --hard`。

### 12.3 推荐更新方式

工作区干净且备份完成后：

```bash
cd /opt/mundo-v2board
./update.sh
```

`update.sh` 会：

1. 拒绝在非 Git 仓库中运行；
2. 拒绝覆盖未提交的本地修改；
3. 使用 `git pull --ff-only` 更新；
4. 重新构建镜像；
5. 按锁文件安装依赖；
6. 执行 V2Board 数据库更新；
7. 清除 Laravel 配置缓存；
8. 重新启动全部服务。

### 12.4 手动更新方式

```bash
cd /opt/mundo-v2board

git pull --ff-only origin master
docker compose config --quiet
docker compose build --pull web
docker compose up -d redis

docker compose run --rm web \
  composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

docker compose run --rm web php artisan v2board:update
docker compose run --rm web php artisan config:clear
docker compose up -d
docker compose ps
```

源码通过 bind mount 提供给容器。即使 Dockerfile 没变，更新流程仍重新构建镜像，以确保 PHP 扩展和运行配置与仓库一致。

更新后重复第 8 节和第 10 节的验收。

---

## 13. 备份与日常维护

### 13.1 日常状态

```bash
cd /opt/mundo-v2board

docker compose ps
docker compose logs --tail=100 web
docker compose logs --tail=100 gateway
docker compose logs --tail=100 horizon
docker compose logs --tail=100 scheduler
docker system df
df -h
```

### 13.2 安全重启

```bash
docker compose restart web
docker compose restart horizon scheduler
```

修改 Compose、Dockerfile 或镜像运行环境后使用：

```bash
docker compose up -d --build
```

### 13.3 不要执行

```text
docker compose down -v
git reset --hard
php artisan config:cache
composer update
```

原因：

- `down -v` 会删除 Redis 数据卷；
- `reset --hard` 会丢失生产目录中的本地修改；
- `config:cache` 会破坏动态面板配置刷新；
- `composer update` 会绕过锁文件改变生产依赖版本。

生产环境使用：

```text
composer install
```

### 13.4 日志安全

发送日志前删除或打码：

- 数据库密码；
- 管理员密码；
- `Authorization`；
- JWT；
- 订阅令牌；
- 节点 `token`；
- 节点通信密钥；
- 用户订阅地址。

---

## 14. 最终检查清单

- [ ] Docker Engine、Compose 和 `hello-world` 正常；
- [ ] 使用的是本仓库 `master`，工作区无未知修改；
- [ ] `APP_ENV=production`；
- [ ] `APP_DEBUG=false`；
- [ ] `APP_URL` 是 V2Board 的完整 HTTPS 域名；
- [ ] MySQL 使用独立用户和空数据库完成首次安装；
- [ ] 数据库、`.env` 和运行时配置已经备份；
- [ ] 五个服务正常，Redis 为 healthy；
- [ ] `6600`、`7001` 只监听 `127.0.0.1`；
- [ ] `3306`、`6379`、`6600`、`7001` 未向公网开放；
- [ ] 本机 `6600` 和 `7001` 首页返回 `200`；
- [ ] 公网 HTTPS 首页和后台路径返回 `200`；
- [ ] 宝塔完整传递代理头和 `Authorization`；
- [ ] Cloudflare 使用 `Full (strict)`，后台和 API 未被缓存；
- [ ] 后台登录不会跳回登录页；
- [ ] Horizon 管理接口鉴权正常；
- [ ] 新用户注册开关保存后不会回退；
- [ ] 流量重置方式保存后不会回退；
- [ ] 主题配置保存后不会回退；
- [ ] 新增和复制节点无需刷新整页即可看到；
- [ ] `custom.css` 和 `custom.js` 返回 `200`；
- [ ] 桌面端和手机端管理界面均可操作；
- [ ] 键盘焦点可见，浏览器缩放未被禁止；
- [ ] `bootstrap/cache/config.php` 不存在；
- [ ] 没有使用 `SIGTERM` 重启 Web；
- [ ] 管理员初始密码已经更换；
- [ ] 已验证备份可以恢复。

全部通过后，部署才算完成。容器仅显示 `Up` 并不代表登录、配置保存、节点列表和公网代理都已正确工作。
