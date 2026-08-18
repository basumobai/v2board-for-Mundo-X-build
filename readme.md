<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@mconnectofficial-blue.svg)](https://t.me/mconnectofficial)

## 本分支支持的后端
 - [Mundo X后端](https://github.com/Mundo-Connect/M)
 - [Mundo X网址](https://668993.xyz)

## 原版迁移步骤

按以下步骤进行面板代码文件迁移：

    git remote set-url origin https://github.com/wyx2685/v2board  
    git checkout master  
    ./update.sh  


按以下步骤配置缓存驱动为redis，然后刷新设置缓存，重启队列:

    sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
    php artisan config:clear
    php artisan config:cache
    php artisan horizon:terminate

最后进入后台重新保存主题： 主题配置-选择default主题-主题设置-确定保存

# **V2Board**

- PHP7.3+
- Composer
- MySQL5.5+
- Redis
- Laravel

## Demo
[Demo_user](https://v2bdemo.v-50.me/)
[Demo_admin](https://v2bdemo.v-50.me/admindashboard)
邮箱和密码可随意输入

## Document
[Click](https://v2board.com)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Group: [@mconnectofficial](https://t.me/mconnectofficial)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.

# Mundo V2Board：Ubuntu + 宝塔 + Docker 完整部署与排错指南

> 本指南来自一次真实完成的部署和排错过程。
>
> 测试环境：Ubuntu 24.04、Docker Engine 29.7.2、Docker Compose v5.5.0、PHP 8.2、MySQL 运行在宿主机、Cloudflare 代理域名。
>
> 测试源码：`Mundo-Connect/v2board` 的 `master` 分支，提交 `4b1604d`（2026-08-18）。上游代码以后可能变化；如果提交差异很大，应先重新审查本指南中的补丁。

## 目录

1. [部署架构和重要说明](#1-部署架构和重要说明)
2. [准备域名、数据库和端口](#2-准备域名数据库和端口)
3. [安装 Docker](#3-安装-docker)
4. [下载并确认 Mundo 源码](#4-下载并确认-mundo-源码)
5. [创建 Docker 配置文件](#5-创建-docker-配置文件)
6. [修改 Mundo 的运行配置](#6-修改-mundo-的运行配置)
7. [构建镜像并安装依赖](#7-构建镜像并安装依赖)
8. [安装前检查数据库](#8-安装前检查数据库)
9. [运行 V2Board 安装器](#9-运行-v2board-安装器)
10. [初始化主题并生成生产配置](#10-初始化主题并生成生产配置)
11. [启动和检查全部容器](#11-启动和检查全部容器)
12. [配置宝塔反向代理](#12-配置宝塔反向代理)
13. [配置 HTTPS 和 Cloudflare](#13-配置-https-和-cloudflare)
14. [最终验收](#14-最终验收)
15. [本次实际遇到的错误与处理方法](#15-本次实际遇到的错误与处理方法)
16. [日常维护、备份与更新](#16-日常维护备份与更新)
17. [安全检查清单](#17-安全检查清单)

---

## 1. 部署架构和重要说明

### 1.1 本指南采用的架构

```text
访客 HTTPS 443
  ↓
Cloudflare（可选，但本指南包含其配置）
  ↓
宝塔 Nginx（证书、公网入口）
  ↓ http://127.0.0.1:7001
Docker Nginx Gateway（静态文件）
  ↓ http://127.0.0.1:6600
AdapterMan + Laravel（Mundo V2Board）

同时：
Laravel → 宿主机 127.0.0.1:3306 → 宝塔 MySQL
Laravel → /data/redis.sock → Docker Redis
```

Docker 中运行五个服务：

- `web`：PHP 8.2、AdapterMan、Workerman、Laravel；
- `gateway`：只监听 `127.0.0.1:7001`，提供静态文件并转发动态请求；
- `horizon`：队列消费者；
- `scheduler`：每分钟执行一次 Laravel 计划任务；
- `redis`：不开放 TCP 端口，使用共享 Unix Socket。

### 1.2 不要直接使用 XBoard 的样例配置

一些朋友可能会提供 XBoard 的 `composer.json` 或 Compose 样例作为参考，但不能直接覆盖进 Mundo：

- XBoard 样例可能基于 Laravel 12 和 Octane；
- 本指南测试的 Mundo 基于 Laravel 8；
- Mundo 自带 `webman.php`，使用 AdapterMan + Workerman；
- Mundo 没有 XBoard 样例里某些服务和入口文件。

可以借鉴样例的容器结构，但不能把 XBoard 的 `composer.json` 覆盖到 Mundo 项目。

### 1.3 本指南中的占位值

下面统一使用：

```text
面板域名：panel.example.com
数据库名：v2board
数据库用户名：v2board
项目目录：/opt/mundo-v2board
```

部署时必须替换成自己的值。不要照抄 `panel.example.com`。

---

## 2. 准备域名、数据库和端口

### 2.1 创建数据库

在宝塔数据库界面创建：

```text
数据库名：v2board
用户名：v2board（也可以使用宝塔生成的用户名）
字符集：utf8mb4
密码：强随机密码
访问权限：仅本机
```

不要在聊天、截图、Shell 历史或公开日志中粘贴数据库密码。

确认 MySQL 正在监听：

```bash
ss -lntp | grep ':3306 '
```

如果完全没有输出，先在宝塔中启动 MySQL。

如果显示 `*:3306`、`0.0.0.0:3306` 或 `[::]:3306`，代表 MySQL 监听所有网卡。部署可以继续，但必须在云防火墙或系统防火墙阻止公网访问 3306。

### 2.2 准备域名

在 DNS 服务商创建：

```text
类型：A
名称：panel（按自己的域名填写）
目标：服务器公网 IPv4
```

使用 Cloudflare 时，首次申请源站证书前可以暂时设置为 `DNS only`（灰云）。

### 2.3 检查内部端口

```bash
ss -lntp | grep -E ':(6600|7001)\b' || true
```

首次安装时正常结果是没有输出。如果端口已被占用，先找出占用服务，不要直接杀进程：

```bash
ss -lntp | grep -E ':(6600|7001)\b'
```

---

## 3. 安装 Docker

已有可正常工作的 Docker Engine 和 `docker compose` 时可以跳过。

以下命令以 `root` 用户执行，使用 Docker 官方 Ubuntu 软件源：

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

必须满足：

- `docker --version` 有版本输出；
- `docker compose version` 成功；
- Docker 服务为 `active`；
- `hello-world` 输出安装成功提示。

本指南使用的是带空格的 `docker compose`，不是旧命令 `docker-compose`。

---

## 4. 下载并确认 Mundo 源码

确认目标目录不存在：

```bash
test ! -e /opt/mundo-v2board \
  && echo '目标目录可用' \
  || echo '目标目录已经存在，请先检查，禁止直接覆盖'
```

克隆源码：

```bash
git clone --depth 1 --branch master \
  https://github.com/Mundo-Connect/v2board.git \
  /opt/mundo-v2board

cd /opt/mundo-v2board
```

检查：

```bash
git remote -v
git log -1 --oneline

test -f artisan \
  && test -f composer.json \
  && test -f webman.php \
  && echo 'Mundo 源码结构正常'
```

不要把别的项目的 `composer.json` 复制进来。

---

## 5. 创建 Docker 配置文件

最终目录结构：

```text
/opt/mundo-v2board/
├── artisan
├── composer.json
├── webman.php
├── Dockerfile
├── compose.yaml
├── .dockerignore
└── docker/
    └── nginx.conf
```

先创建目录：

```bash
cd /opt/mundo-v2board
mkdir -p docker
```

### 5.1 Dockerfile

创建 `/opt/mundo-v2board/Dockerfile`：

```dockerfile
FROM php:8.2-cli-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        unzip \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        posix \
        sockets \
        zip \
    && pecl install igbinary redis \
    && docker-php-ext-enable igbinary redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN printf '%s\n' \
    'memory_limit=512M' \
    'max_execution_time=300' \
    'upload_max_filesize=32M' \
    'post_max_size=32M' \
    'date.timezone=Asia/Shanghai' \
    'display_errors=Off' \
    'display_startup_errors=Off' \
    'expose_php=Off' \
    'log_errors=On' \
    'opcache.enable=1' \
    'opcache.enable_cli=1' \
    'opcache.validate_timestamps=1' \
    > /usr/local/etc/php/conf.d/99-v2board.ini

WORKDIR /www
```

### 5.2 compose.yaml

创建 `/opt/mundo-v2board/compose.yaml`：

```yaml
x-v2board-image: &v2board-image
  image: mundo-v2board-local:latest

x-v2board-service: &v2board-service
  <<: *v2board-image
  working_dir: /www
  volumes:
    - redis-data:/data
    - ./:/www
  environment:
    docker: "true"
  depends_on:
    redis:
      condition: service_healthy
  network_mode: host
  restart: unless-stopped

services:
  web:
    <<: *v2board-service
    build:
      context: .
      dockerfile: Dockerfile
    command: php -c cli-php.ini webman.php start

  gateway:
    image: nginx:stable-alpine
    network_mode: host
    volumes:
      - ./:/www:ro
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - web
    restart: unless-stopped

  horizon:
    <<: *v2board-service
    command: php artisan horizon

  scheduler:
    <<: *v2board-service
    command:
      - sh
      - -lc
      - |
        while true; do
          php artisan schedule:run --no-interaction
          sleep 60
        done

  redis:
    image: redis:7-alpine
    command:
      - redis-server
      - --appendonly
      - "yes"
      - --port
      - "0"
      - --unixsocket
      - /data/redis.sock
      - --unixsocketperm
      - "777"
    volumes:
      - redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "-s", "/data/redis.sock", "ping"]
      interval: 5s
      timeout: 3s
      retries: 20
    sysctls:
      net.core.somaxconn: 1024
    restart: unless-stopped

volumes:
  redis-data:
```

说明：

- `web`、`horizon`、`scheduler` 使用宿主机网络，因此容器里的 `127.0.0.1:3306` 就是宿主机 MySQL；
- Redis 不开放 TCP，只创建 `/data/redis.sock`；
- 多个应用容器共享 `redis-data`，因此都能访问同一个 Socket；
- `gateway` 和 `web` 都只监听宿主机回环地址，不直接暴露公网。

### 5.3 docker/nginx.conf

创建 `/opt/mundo-v2board/docker/nginx.conf`：

```nginx
server {
    listen 127.0.0.1:7001;
    server_name _;

    root /www/public;
    client_max_body_size 32m;

    location = / {
        proxy_pass http://127.0.0.1:6600;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;
        proxy_set_header X-Forwarded-Host $http_x_forwarded_host;
        proxy_set_header X-Forwarded-Port $http_x_forwarded_port;
    }

    location / {
        try_files $uri @v2board;
    }

    location @v2board {
        proxy_pass http://127.0.0.1:6600;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;
        proxy_set_header X-Forwarded-Host $http_x_forwarded_host;
        proxy_set_header X-Forwarded-Port $http_x_forwarded_port;
    }

    location ~ /\. {
        deny all;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|webp|woff|woff2|ttf|map)$ {
        try_files $uri @v2board;
        expires 7d;
        access_log off;
    }
}
```

这里的 `X-Forwarded-Proto` 从宝塔传入，所以宝塔反向代理中必须设置该请求头。

### 5.4 .dockerignore

创建 `/opt/mundo-v2board/.dockerignore`：

```text
.git
.env
vendor
storage/logs/*
wwwlogs
mysql
backups
```

### 5.5 校验 Compose

```bash
cd /opt/mundo-v2board
docker compose config --quiet
echo "Compose 校验退出码：$?"
```

退出码必须是 `0`。

---

## 6. 修改 Mundo 的运行配置

### 6.1 修改 `.env.example`

首次安装前只编辑 `.env.example`，不要提前创建 `.env`。安装器发现 `.env` 已存在会直接拒绝安装。

至少修改为：

```dotenv
APP_NAME=V2Board
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://panel.example.com

LOG_CHANNEL=stack

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=v2board
DB_USERNAME=v2board
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

HORIZON_MAX_PROCESSES=4

REDIS_HOST=/data/redis.sock
REDIS_PASSWORD=null
REDIS_PORT=0

SESSION_SECURE_COOKIE=true
```

安装器稍后会再次询问并覆盖数据库地址、数据库名、用户名和密码。

### 6.2 信任反向代理

```bash
cd /opt/mundo-v2board

sed -i "s/protected \$proxies;/protected \$proxies = '*';/" \
  app/Http/Middleware/TrustProxies.php

grep -n 'protected.*proxies' \
  app/Http/Middleware/TrustProxies.php
```

应看到：

```php
protected $proxies = '*';
```

### 6.3 删除重复 PHP 扩展声明

Docker 已经通过 `/usr/local/etc/php/conf.d` 加载 Redis 和 igbinary。Mundo 的 `cli-php.ini` 如果再次加载，会出现 `Module already loaded`。

```bash
sed -i \
  -e '/^extension=igbinary\.so$/d' \
  -e '/^extension=redis\.so$/d' \
  cli-php.ini

grep '^extension=' cli-php.ini \
  || echo 'cli-php.ini 无重复扩展声明'
```

### 6.4 修复生产环境 Horizon 管理员鉴权

Mundo 后台首页会请求 `/monitor/api/stats`。原始 `HorizonServiceProvider` 的生产环境邮箱白名单为空，会返回 `403`。后台前端又会把任何 `403` 当成登录失效，于是表现为“登录成功后马上跳回登录页”。

不要把 `APP_ENV` 改回 `local` 来绕过。应让 Horizon 使用 V2Board 自己的管理员 JWT 鉴权。

在当前测试提交上，可以应用：

```bash
git apply <<'PATCH'
diff --git a/app/Providers/HorizonServiceProvider.php b/app/Providers/HorizonServiceProvider.php
index 119fd69..6fe45db 100644
--- a/app/Providers/HorizonServiceProvider.php
+++ b/app/Providers/HorizonServiceProvider.php
@@ -2,6 +2,7 @@
 
 namespace App\Providers;
 
+use App\Services\AuthService;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
 use Laravel\Horizon\Horizon;
@@ -34,10 +35,16 @@ class HorizonServiceProvider extends HorizonApplicationServiceProvider
      */
     protected function gate()
     {
-        Gate::define('viewHorizon', function ($user) {
-            return in_array($user->email, [
-                //
-            ]);
+        Gate::define('viewHorizon', function ($user = null) {
+            $authorization = request()->input('auth_data')
+                ?? request()->header('authorization');
+
+            if (!$authorization) {
+                return false;
+            }
+
+            $v2boardUser = AuthService::decryptAuthData($authorization);
+            return $v2boardUser && !empty($v2boardUser['is_admin']);
         });
     }
 }
PATCH
```

如果上游代码已经变化导致补丁无法应用，应人工完成两处修改：

```php
use App\Services\AuthService;
```

并把 `gate()` 改为：

```php
protected function gate()
{
    Gate::define('viewHorizon', function ($user = null) {
        $authorization = request()->input('auth_data')
            ?? request()->header('authorization');

        if (!$authorization) {
            return false;
        }

        $v2boardUser = AuthService::decryptAuthData($authorization);
        return $v2boardUser && !empty($v2boardUser['is_admin']);
    });
}
```

该修改不是公开放行 Horizon；它要求请求携带有效的 V2Board 管理员令牌。

---

## 7. 构建镜像并安装依赖

### 7.1 构建镜像

```bash
cd /opt/mundo-v2board
docker compose build --pull
```

验证 PHP 和扩展：

```bash
docker compose run --rm --no-deps web php -v

docker compose run --rm --no-deps web php -m \
  | grep -E '^(bcmath|gd|igbinary|intl|mbstring|pcntl|pdo_mysql|posix|redis|sockets|zip)$'
```

至少必须包含：

```text
pcntl
pdo_mysql
posix
redis
sockets
```

### 7.2 启动 Redis

```bash
docker compose up -d redis
docker compose ps
docker compose exec redis redis-cli -s /data/redis.sock ping
```

正常返回：

```text
PONG
```

### 7.3 安装 Composer 依赖

先安装 Mundo 原有依赖：

```bash
docker compose run --rm web \
  composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction
```

再安装 `webman.php` 在 PHP 8 下需要的 AdapterMan：

```bash
docker compose run --rm web \
  composer require joanhey/adapterman \
  --update-no-dev \
  --update-with-all-dependencies \
  --no-interaction
```

这一步会修改 Mundo 的 `composer.json` 和 `composer.lock`，属于预期行为；仓库自己的 `init.sh` 也会安装 AdapterMan。

检查：

```bash
docker compose run --rm web \
  composer show joanhey/adapterman

docker compose run --rm web \
  composer check-platform-reqs --no-dev

docker compose run --rm web \
  php -l webman.php
```

所有平台依赖应显示 `success`，`webman.php` 应无语法错误。

---

## 8. 安装前检查数据库

### 8.1 确认 `.env` 不存在

```bash
cd /opt/mundo-v2board

test ! -f .env \
  && echo '应用 .env 尚未创建，可以首次安装' \
  || echo '应用 .env 已存在，停止并检查'
```

### 8.2 通过容器测试数据库，并确认数据库为空

将示例中的数据库名和用户名替换为自己的值：

```bash
read -rsp '请输入数据库密码（不会显示）: ' DB_PASS
echo
export DB_PASS

docker compose run --rm \
  -e DB_PASS \
  web php -r '
try {
    $pdo = new PDO(
        "mysql:host=127.0.0.1;port=3306;dbname=v2board;charset=utf8mb4",
        "v2board",
        getenv("DB_PASS"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
    )->fetchColumn();

    echo "数据库连接成功\n";
    echo "当前数据库表数量：{$count}\n";
    exit($count === 0 ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, "数据库连接失败：" . $e->getMessage() . PHP_EOL);
    exit(1);
}
'

DB_TEST_STATUS=$?
unset DB_PASS

echo "数据库测试退出码：${DB_TEST_STATUS}"
```

首次安装预期：

```text
数据库连接成功
当前数据库表数量：0
数据库测试退出码：0
```

如果表数量不是 `0`，不要继续。确认是否是旧数据库、导入残留或其他程序正在使用。

---

## 9. 运行 V2Board 安装器

执行：

```bash
cd /opt/mundo-v2board
docker compose run --rm web php artisan v2board:install
```

按提示填写：

```text
请输入数据库地址（默认:localhost）：127.0.0.1
请输入数据库名：v2board
请输入数据库用户名：v2board
请输入数据库密码：自己的数据库密码
请输入管理员邮箱：自己的管理员邮箱
```

### 最重要的注意事项

数据库地址必须明确输入：

```text
127.0.0.1
```

不要直接回车接受默认的 `localhost`。PDO 遇到 `localhost` 会尝试连接容器内的 MySQL Unix Socket，而宿主机 MySQL 的 Socket 不在容器中，因此会连接失败。

项目安装器使用普通提问而不是隐藏输入，所以数据库密码可能会显示在 SSH 终端。输入时不要录屏、直播或把终端内容复制到公开位置。

成功时会显示：

```text
数据库导入完成
一切就绪
管理员邮箱：...
管理员密码：...
访问 http(s)://你的站点/随机后台路径 进入管理面板
```

立即保存：

- 管理员邮箱；
- 随机管理员密码；
- 随机后台路径。

不要把密码发给他人。第一次登录后应立即改成新的强密码。

---

## 10. 初始化主题并生成生产配置

### 10.1 创建目录和权限

```bash
cd /opt/mundo-v2board

docker compose run --rm web sh -lc '
mkdir -p \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache \
  config/theme

chmod -R ug+rwX storage bootstrap/cache config/theme
'
```

### 10.2 在服务启动前生成默认主题配置

Mundo 首次访问首页时会动态生成 `config/theme/default.php` 并调用 `config:cache`。在 AdapterMan 常驻进程中执行这个初始化可能导致首次首页 `500` 或进程继续使用旧配置。因此在启动 Web 服务前主动生成。

```bash
docker compose run --rm web php -r '
$target = "config/theme/default.php";

if (is_file($target)) {
    echo "默认主题配置已经存在\n";
    exit(0);
}

$source = "public/theme/default/config.json";
$config = json_decode(file_get_contents($source), true);

if (!isset($config["configs"]) || !is_array($config["configs"])) {
    fwrite(STDERR, "主题配置文件格式错误\n");
    exit(1);
}

$data = [];

foreach ($config["configs"] as $item) {
    $data[$item["field_name"]] = $item["default_value"] ?? "";
}

$content = "<?php\nreturn " . var_export($data, true) . ";\n";

if (file_put_contents($target, $content) === false) {
    fwrite(STDERR, "主题配置写入失败\n");
    exit(1);
}

echo "默认主题配置创建成功\n";
'
```

### 10.3 避免后台 `custom.css` 404

当前后台模板会请求 `/assets/admin/custom.css`，而部分源码提交没有该文件。创建空文件即可：

```bash
cd /opt/mundo-v2board
install -m 0644 /dev/null public/assets/admin/custom.css
```

### 10.4 检查 `.env`，但不要输出密码

```bash
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|CACHE_DRIVER|QUEUE_CONNECTION|SESSION_DRIVER|REDIS_HOST|REDIS_PORT|SESSION_SECURE_COOKIE)=' .env
```

应包含：

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.example.com
DB_HOST=127.0.0.1
DB_PORT=3306
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=/data/redis.sock
REDIS_PORT=0
SESSION_SECURE_COOKIE=true
```

### 10.5 检查 PHP 语法并建立缓存

```bash
docker compose run --rm web \
  php -l app/Providers/HorizonServiceProvider.php

docker compose run --rm web php artisan config:clear
docker compose run --rm web php artisan config:cache
docker compose run --rm web php artisan route:clear
docker compose run --rm web php artisan view:clear
```

---

## 11. 启动和检查全部容器

### 11.1 启动

```bash
cd /opt/mundo-v2board
docker compose up -d
docker compose ps
```

应该看到：

```text
gateway     Up
horizon     Up
redis       Up (healthy)
scheduler   Up
web         Up
```

### 11.2 检查日志

```bash
docker compose logs --tail=100 web
docker compose logs --tail=100 gateway
docker compose logs --tail=100 horizon
docker compose logs --tail=100 scheduler
docker compose logs --tail=100 redis
```

正常关键输出包括：

```text
Adapterman/... (Workerman/...) OK
http://127.0.0.1:6600
Start success
Horizon started successfully
```

Gateway 出现下面提示通常无害：

```text
can not modify /etc/nginx/conf.d/default.conf (read-only file system?)
```

这是因为配置以只读方式挂载；只要随后显示 `ready for start up`，Nginx 就已经正常启动。

### 11.3 检查端口

```bash
ss -lntp | grep -E ':(6600|7001)\b' || true
```

应该只监听：

```text
127.0.0.1:6600
127.0.0.1:7001
```

### 11.4 本机功能测试

把域名和后台路径替换成真实值：

```bash
curl -sS -o /dev/null \
  -w '6600 首页状态码：%{http_code}\n' \
  -H 'Host: panel.example.com' \
  -H 'X-Forwarded-Proto: https' \
  http://127.0.0.1:6600/

curl -sS -o /dev/null \
  -w '7001 首页状态码：%{http_code}\n' \
  -H 'Host: panel.example.com' \
  -H 'X-Forwarded-Proto: https' \
  http://127.0.0.1:7001/

curl -sS -o /dev/null \
  -w '后台状态码：%{http_code}\n' \
  -H 'Host: panel.example.com' \
  -H 'X-Forwarded-Proto: https' \
  http://127.0.0.1:7001/你的随机后台路径
```

三个状态码都应为 `200`。

---

## 12. 配置宝塔反向代理

### 12.1 添加站点

在宝塔中打开：

```text
网站 → 添加站点
```

填写：

```text
域名：panel.example.com
端口：80
根目录：/www/wwwroot/panel.example.com
PHP版本：纯静态
数据库：不创建
FTP：不创建
```

PHP 已在 Docker 中运行，宝塔站点必须选择纯静态。

### 12.2 添加反向代理

```text
网站 → panel.example.com → 反向代理 → 添加
```

填写：

```text
代理名称：v2board
代理目录：/
目标 URL：http://127.0.0.1:7001
发送域名：panel.example.com 或 $host
内容替换：留空
缓存：关闭
```

宝塔生成的代理配置中必须有：

```nginx
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Port $server_port;
```

如果后三项不存在，应在该站点的反向代理配置中添加。

检查配置位置：

```bash
grep -RIl '127.0.0.1:7001' \
  /www/server/panel/vhost/nginx \
  /www/server/nginx/conf 2>/dev/null
```

检查 Nginx：

```bash
/www/server/nginx/sbin/nginx -t
/www/server/nginx/sbin/nginx -s reload
```

本机测试宝塔入口：

```bash
curl -sS -o /dev/null \
  -w '宝塔 HTTP 首页：%{http_code}\n' \
  -H 'Host: panel.example.com' \
  http://127.0.0.1/

curl -sS -o /dev/null \
  -w '宝塔 HTTP 后台：%{http_code}\n' \
  -H 'Host: panel.example.com' \
  http://127.0.0.1/你的随机后台路径
```

开启强制 HTTPS 前，这两个一般应为 `200`。

---

## 13. 配置 HTTPS 和 Cloudflare

### 13.1 申请源站证书

使用 Cloudflare 时，推荐顺序：

1. 确认 `panel.example.com` 的 A 记录指向服务器公网 IPv4；
2. 暂时把代理状态改成 `DNS only`（灰云）；
3. 等公共解析直接返回源站 IP；
4. 在宝塔网站的 SSL 页面申请 Let's Encrypt 证书；
5. 证书部署成功后开启强制 HTTPS。

解析检查：

```bash
getent ahostsv4 panel.example.com \
  | awk '{print $1}' \
  | sort -u
```

源站 HTTPS 测试：

```bash
curl --resolve panel.example.com:443:127.0.0.1 \
  -sS -o /dev/null \
  -w '源站 HTTPS 首页：%{http_code}\n' \
  https://panel.example.com/

curl --resolve panel.example.com:443:127.0.0.1 \
  -sS -o /dev/null \
  -w '源站 HTTPS 后台：%{http_code}\n' \
  https://panel.example.com/你的随机后台路径
```

两个应为 `200`。

### 13.2 Cloudflare 加密模式

在 Cloudflare：

```text
SSL/TLS → Overview → Full (strict) / 完全（严格）
```

不要使用 `Flexible / 灵活`，否则宝塔强制 HTTPS 时容易形成重定向循环。

设置为 `Full (strict)` 后，再把 DNS 代理恢复为橙云。

公网检查：

```bash
curl -sS -o /dev/null \
  -w '公网首页：%{http_code}\n' \
  https://panel.example.com/

curl -sS -o /dev/null \
  -w '公网后台：%{http_code}\n' \
  https://panel.example.com/你的随机后台路径

curl -sSI https://panel.example.com/ \
  | sed -n '1,20p'
```

预期首页和后台都是 `200`，使用橙云时响应头通常会包含 `server: cloudflare`。

---

## 14. 最终验收

### 14.1 登录后台

访问：

```text
https://panel.example.com/随机后台路径
```

登录后：

1. 立即修改安装器生成的随机管理员密码；
2. 重新保存站点配置；
3. 重新保存默认主题配置；
4. 检查 Horizon 和计划任务状态。

### 14.2 检查 Horizon 监控接口

登录后台首页后执行：

```bash
cd /opt/mundo-v2board

docker compose logs --since=5m gateway 2>&1 \
  | grep '/monitor/api/stats' \
  | tail -n 20
```

必须看到：

```text
GET /monitor/api/stats ... 200
```

### 14.3 检查 Redis 跨进程读写

```bash
docker compose exec web php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$result = Illuminate\Support\Facades\Cache::put(
    "DEPLOY_AUTH_TEST",
    "redis-persistence-ok",
    120
);

echo "写入结果：" . ($result ? "成功" : "失败") . PHP_EOL;
'

docker compose exec web php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$value = Illuminate\Support\Facades\Cache::get("DEPLOY_AUTH_TEST");
echo "读取结果：" . var_export($value, true) . PHP_EOL;
exit($value === "redis-persistence-ok" ? 0 : 1);
'

echo "Redis 跨进程测试退出码：$?"
```

预期退出码为 `0`。

### 14.4 总体健康检查

```bash
cd /opt/mundo-v2board

docker compose ps
docker compose exec redis redis-cli -s /data/redis.sock ping
docker compose run --rm web php artisan horizon:status

curl -sS -o /dev/null \
  -w '最终公网状态：%{http_code}\n' \
  https://panel.example.com/
```

---

## 15. 本次实际遇到的错误与处理方法

### 15.1 `docker: command not found`

原因：服务器没有安装 Docker。

处理：按照第 3 节使用 Docker 官方 Ubuntu 软件源安装，并用 `hello-world` 验证。不要只看到宝塔里有容器菜单就假定系统已经安装 Docker。

### 15.2 错把其他项目的 Compose 当成 Mundo 一键部署文件

原因：样例属于 XBoard/Laravel 12/Octane，与 Mundo/Laravel 8/AdapterMan 不同。

处理：保留 Mundo 原始 `composer.json`，只借鉴容器架构，使用本指南提供的 Dockerfile 和 Compose。

### 15.3 PHP 显示 `Module already loaded`

原因：Docker 的 `conf.d` 和项目 `cli-php.ini` 同时加载 `redis`、`igbinary`。

处理：删除 `cli-php.ini` 中两行重复的 `extension=` 声明，见第 6.3 节。

### 15.4 安装器第一次数据库连接成功，正式安装却失败

典型过程：独立测试使用 `127.0.0.1` 成功，但安装器数据库地址直接回车，接受了默认 `localhost`。

原因：PDO 把 `localhost` 当作 Unix Socket 连接；容器内没有宿主机 MySQL Socket。

处理：安装器必须输入 `127.0.0.1`。

### 15.5 数据库连接失败后，重新安装提示 `.env` 已存在

原因：安装器会先复制 `.env.example`、生成 APP_KEY、写入数据库配置，然后才测试数据库连接。即使连接失败，`.env` 已经存在。

不要直接反复运行安装器。先检查数据库是否仍为空：

```bash
cd /opt/mundo-v2board
docker compose run --rm web php artisan config:clear

mv .env /root/mundo-v2board.failed-install.env
```

然后重新执行第 8 节的数据库连接和表数量检查：

- 表数量为 `0`：可以重新运行安装器；
- 表数量大于 `0`：可能已经部分导入，先重建空数据库或恢复安装前备份，不能盲目重装。

如果数据库密码曾出现在聊天、截图或公开日志里，应先在宝塔中更换密码，再重新安装。

失败安装生成的后台路径来自旧 APP_KEY；清理失败 `.env` 后，该路径也会失效，应以成功安装最后输出的新路径为准。

### 15.6 容器全部运行，但首页 `/` 返回 `500`，后台路径返回 `200`

原因：默认主题配置 `config/theme/default.php` 尚未生成；在常驻 AdapterMan 请求内首次生成并刷新配置可能失败或仍使用旧缓存。

处理：按照第 10.2 节在启动 Web 服务前生成默认主题配置，再执行：

```bash
docker compose run --rm web php artisan config:clear
docker compose run --rm web php artisan config:cache
docker compose restart web horizon scheduler
```

### 15.7 管理员登录成功后，立刻跳回登录页

实际日志：

```text
POST /api/v1/passport/auth/login        200
GET  /api/v1/user/info                  200
GET  /monitor/api/stats                 403
```

原因：

1. 登录本身成功；
2. Redis 和管理员 JWT 都正常；
3. Horizon 在生产环境使用了空邮箱白名单，返回 `403`；
4. Mundo 后台前端把任何 `403` 都当成登录过期，删除 `localStorage.authorization` 并跳回登录页。

处理：应用第 6.4 节的 `HorizonServiceProvider` 鉴权修复，重建配置缓存并重启 `web`、`horizon`。

不要通过以下方式掩盖问题：

- 不要把 `APP_ENV` 改成 `local`；
- 不要公开放行 `/monitor`；
- 不要关闭整个后台鉴权。

### 15.8 清理浏览器缓存无效

如果登录接口和 `/api/v1/user/info` 都是 `200`，但仍跳回登录页，应检查完整请求日志，而不是继续反复清缓存：

```bash
docker compose logs --since=5m gateway 2>&1 \
  | grep -E '(/api/v1/|/monitor/)' \
  | grep ' 403 ' \
  | tail -n 50
```

这次就是靠该日志定位到 `/monitor/api/stats 403`。

### 15.9 `no configuration file provided: not found`

原因：重新登录 SSH 后当前位置变回 `/root`，不在项目目录。

处理：

```bash
cd /opt/mundo-v2board
docker compose ps
```

所有 `docker compose` 命令都应先确认位于包含 `compose.yaml` 的目录。

### 15.10 Gateway 提示只读配置文件

日志：

```text
can not modify /etc/nginx/conf.d/default.conf (read-only file system?)
```

原因：`nginx.conf` 被有意以只读方式挂载。只要 Gateway 后续正常启动，这不是故障。

### 15.11 `/assets/admin/custom.css` 返回 `404`

原因：后台模板引用了该文件，但部分提交没有提供。

处理：

```bash
install -m 0644 /dev/null \
  /opt/mundo-v2board/public/assets/admin/custom.css
```

### 15.12 节点请求 `/api/v1/server/UniProxy/...` 返回 `500`

这与管理员登录问题无关，表示某个节点正在以不匹配的配置访问面板。检查：

- 节点填写的面板地址；
- 节点 ID；
- `node_type`；
- 面板后台的通信密钥；
- 密钥是否被错误 URL 编码；
- 节点端与面板端协议版本是否匹配。

请求 URL 可能包含通信密钥。分享日志前必须打码；如果密钥曾公开，应在面板中立即更换并同步到节点。

---

## 16. 日常维护、备份与更新

### 16.1 常用命令

```bash
cd /opt/mundo-v2board

docker compose ps
docker compose logs --tail=100 web
docker compose logs --tail=100 gateway
docker compose logs --tail=100 horizon
docker compose logs --tail=100 scheduler

docker compose restart web
docker compose restart horizon
docker compose restart

docker compose up -d
docker compose down
```

不要执行：

```bash
docker compose down -v
```

`-v` 会删除 Compose 管理的 Redis 数据卷。

### 16.2 备份

至少备份：

- MySQL 数据库；
- `/opt/mundo-v2board/.env`；
- `composer.json` 和 `composer.lock`；
- Dockerfile、Compose、Nginx 配置；
- `app/Http/Middleware/TrustProxies.php`；
- `app/Providers/HorizonServiceProvider.php`；
- `config/theme/`；
- 重要上传文件和主题文件。

示例：

```bash
cd /opt/mundo-v2board

BACKUP_DIR='/root/mundo-v2board-backup-20260818'
mkdir -p "$BACKUP_DIR"

cp -a \
  .env \
  composer.json \
  composer.lock \
  Dockerfile \
  compose.yaml \
  .dockerignore \
  docker \
  config/theme \
  app/Http/Middleware/TrustProxies.php \
  app/Providers/HorizonServiceProvider.php \
  "$BACKUP_DIR"/
```

数据库应通过宝塔备份或 `mysqldump` 单独导出。

### 16.3 更新注意事项

本部署对源码有本地修改：

- `composer.json`、`composer.lock`；
- `cli-php.ini`；
- `TrustProxies.php`；
- `HorizonServiceProvider.php`；
- `config/theme/default.php`；
- Docker 配置文件。

仓库自带更新脚本可能执行 `git reset --hard`，从而丢失这些修改。更新前必须：

```bash
cd /opt/mundo-v2board
git status --short
git diff
```

先备份数据库和文件，再获取上游差异并人工合并。不要在生产站直接无脑运行 `git pull`、`git reset --hard` 或来源不明的更新脚本。

更新依赖或 Dockerfile 后通常需要：

```bash
cd /opt/mundo-v2board

docker compose build --pull
docker compose run --rm web composer install \
  --no-dev --prefer-dist --optimize-autoloader --no-interaction
docker compose run --rm web php artisan config:clear
docker compose run --rm web php artisan config:cache
docker compose up -d
```

---

## 17. 安全检查清单

部署完成后逐项确认：

- [ ] `APP_ENV=production`；
- [ ] `APP_DEBUG=false`；
- [ ] Cloudflare 为 `Full (strict)`，不是 `Flexible`；
- [ ] 管理员初始随机密码已经更换；
- [ ] 数据库密码从未出现在公开聊天或截图中；若出现过已经轮换；
- [ ] 节点通信密钥从未公开；若出现过已经轮换；
- [ ] 3306、6379、6600、7001 不允许公网访问；
- [ ] 6600 和 7001 只监听 `127.0.0.1`；
- [ ] Redis TCP 端口未映射到宿主机；
- [ ] 宝塔、SSH 和 Cloudflare 账号启用了强密码及双因素认证；
- [ ] 数据库和 `.env` 有可恢复备份；
- [ ] 没有执行过 `docker compose down -v`；
- [ ] 分享日志前已经移除密码、管理员 JWT、订阅令牌和节点通信密钥。

如果准备启用 UFW，不要直接运行 `ufw enable`。应先确认 SSH 端口放行规则和云厂商安全组，避免把自己锁在服务器外。最简单的原则是：公网只开放实际需要的 SSH、80 和 443，其余端口默认拒绝。

---

## 参考资料

- Mundo V2Board：<https://github.com/Mundo-Connect/v2board>
- Docker Engine on Ubuntu：<https://docs.docker.com/engine/install/ubuntu/>
- AdapterMan：<https://github.com/joanhey/AdapterMan>
- Laravel Horizon 8.x：<https://laravel.com/docs/8.x/horizon>
- Cloudflare Full (strict)：<https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/full-strict/>

---

## 最短验收标准

完成部署后，至少同时满足：

```text
docker compose ps：五个服务全部运行，Redis healthy
http://127.0.0.1:6600/：200
http://127.0.0.1:7001/：200
https://真实域名/：200
https://真实域名/后台路径：200
/monitor/api/stats：管理员登录后返回 200
Redis 跨进程测试：退出码 0
后台登录后不会自动跳回登录页
```

达到以上条件，才算完整部署成功，而不仅仅是“容器已经启动”。
