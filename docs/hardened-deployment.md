# 隔离容器部署

`compose.hardened.yaml` 提供每实例 bridge 网络、非 root 应用/Nginx、只读源码和 rootfs、
Redis Socket 770。应用只可写 storage、bootstrap/cache、config、public/theme、resources/rules。
默认 compose 保留宿主机数据库兼容性，更新脚本不会自动切换网络。

## 迁移前

先完成标准安装或安全更新，在隔离副本演练并保存整套一致性备份。
保留原 COMPOSE_PROJECT_NAME、APP_KEY、数据库、Redis 卷和端口。新安装也可先用标准安装器再迁移。

MySQL 原先只监听 127.0.0.1 时，bridge 容器无法访问它。须让数据库监听一个受防火墙保护、
容器可达的主机地址，并只授权业务账号访问本实例数据库和实际来源网段。
不要因此开放公网 3306 或使用 MySQL root。host.docker.internal 指向 Docker gateway，
MySQL 仍必须监听对应地址。远程数据库应使用受控网络和证书验证。

选择专用非 root UID/GID，例如 1000:1000，并与主机维护账号/共享组策略一致。
权限工具会修改明确可写目录的所有者和权限，不跟随符号链接；迁移前检查目录没有链接到外部路径。
应用通过 REDIS_GID 与 Redis 共享 Socket 组。每实例保持独立 Redis 卷。

## 切换

1. 在旧配置停止 gateway、web、scheduler，等待流量/统计队列（含执行中任务）排空，再停止 Horizon 和 Redis。
   按 safe-update.md 保存同一维护点的快照，不删除卷。
2. 在原 `.env` 中增加这些值，按实际环境修改 UID/GID 和数据库地址：

   ```dotenv
   COMPOSE_FILE=compose.hardened.yaml
   HARDENED_DB_HOST=host.docker.internal
   HARDENED_DB_PORT=3306
   APP_UID=1000
   APP_GID=1000
   REDIS_GID=1000
   ```

3. 验证配置、准备权限和数据库连接，此时不恢复入口：

   ```bash
   docker compose --profile tools config --quiet
   docker compose run --rm -T --no-deps permissions
   docker compose run --rm -T --no-deps installer php -r '
       require "vendor/autoload.php";
       $app = require "bootstrap/app.php";
       $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
       Illuminate\Support\Facades\DB::connection()->getPdo();
       echo "Database reachable\n";
   '
   ```

4. 启动与检查：

   ```bash
   docker compose up -d --wait --wait-timeout 180
   docker compose run --rm -T --no-deps installer php docker/healthcheck.php gateway
   docker compose run --rm -T --no-deps installer php docker/admin-asset-smoke.php
   docker compose exec -T web php -r 'echo "uid=" . posix_geteuid() . " socket=" . decoct(fileperms("/data/redis.sock") & 0777) . PHP_EOL;'
   ```

   UID 必须非 0，Socket 应为 770；检查账户、支付、Horizon、节点和流量。
   只向 127.0.0.1:GATEWAY_PORT 发布入口，宿主机外层反向代理继续连接该端口。

## 代理、前端和邮件

外层代理必须清除客户端提供的 X-Forwarded-*，重新设置真实 IP、协议、主机和端口。
应用默认信任 loopback；隔离配置额外仅信任本实例 gateway 的 DNS 地址，重建容器仍可识别。
其他代理按实际地址/CIDR 配置 TRUSTED_PROXIES，不能设为任意来源。

独立前端设置 FRONTEND_URL、FRONTEND_ORIGINS（逗号分隔的完整来源，包含实际端口）；
BACKEND_URL 为外部可达的 HTTPS 后端基础 URL。不得依赖未经允许的 Origin 决定支付返回域名。
跨域凭据默认关闭，现有 JWT Authorization 客户端不需要开启。

邮件兼容后台 SMTP 配置和旧 MAIL_DRIVER，新配置使用 MAIL_MAILER。
ssl 使用 smtps；tls 使用 SMTP STARTTLS 自动协商。不要禁用 TLS 校验来解决证书链错误。

## 更新与恢复

设置 COMPOSE_FILE 后，`bash update.sh` 使用同一隔离配置；备份读取当前有效数据库和网络设置，
导出工具支持 host.docker.internal。Redis 由无网络、数据卷只读的 backup 服务归档。
依赖及视图更新后，permissions 再次恢复可写目录 UID/GID。线上服务不获得整个源码写权限。

DB_HOST/DB_PORT 在容器中由 HARDENED_DB_HOST/HARDENED_DB_PORT 覆盖。
恢复至 host 网络时同时恢复对应维护点的 .env 和连接设置；不能只删除 COMPOSE_FILE 就当作完成回滚。
按 [safe-update.md](safe-update.md) 恢复整套快照。

CI 对同一临时数据从默认模式迁移到隔离模式，检查非 root、源码只读、Socket、多实例、真实回调和流量，
并在两种模式分别执行更新和备份。它不替代生产宿主机的网络、防火墙和 MySQL 授权验收。
