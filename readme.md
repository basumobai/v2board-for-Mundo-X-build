<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="Mundo logo" width="130" height="130" align="right"/>

# V2Board for Mundo X

基于 [Mundo-Connect/v2board](https://github.com/Mundo-Connect/v2board) 的 Docker 部署分支，面向 Ubuntu、宝塔 Nginx、宿主机 MySQL 和 Mundo X 节点。

本分支提供：

- PHP 8.2、AdapterMan、Nginx、Redis、Horizon 和 Scheduler 的 Compose 配置；
- 安装时自定义 Web / Nginx 端口与 Worker 数量，默认端口 6600 / 7001，仅监听本机；
- 同机多实例的容器、镜像、Redis 数据卷和缓存前缀隔离；
- AdapterMan 常驻 Worker 下按内容变化刷新的动态配置缓存；
- production 环境队列配置、视图预编译、HTTP 健康检查与日志轮转；
- V2Board 管理员令牌驱动的 Horizon 鉴权；
- 数据库事务跨请求隔离；
- Mundo X、VMess、VLESS、Shadowsocks 等节点复制修复；
- 完整安装、验收、备份和故障排查文档。

## 部署文档

[阅读完整 Docker 部署与排错指南](./How%20to%20build.md)

准备好本机 Docker / Compose 2.20+ 和一个空 MySQL 数据库后，执行：

```bash
git clone https://github.com/basumobai/v2board-for-Mundo-X-build.git mundo-v2board
cd mundo-v2board

bash init.sh
```

按提示填写实例名、端口、资源参数、网址、数据库连接（包括端口）和管理员邮箱。脚本自动完成后显示后台地址、初始密码及宝塔反向代理目标。无需在宿主机安装 PHP / Composer，不用手工改 Compose 或 Nginx 配置。

第二套面板使用另一个克隆目录和独立数据库，在运行安装器时选择不同的实例名和端口，也可以预设：

```bash
COMPOSE_PROJECT_NAME=panel-b WEB_PORT=6601 GATEWAY_PORT=7002 bash init.sh
```

安装器会检测冲突，拒绝重装已有站点。详细的 HTTPS、多实例、更新和失败恢复说明见上方部署指南。已有站点请先备份并使用更新流程，不要重新运行 init.sh。

## Mundo X

- [Mundo X 后端](https://github.com/Mundo-Connect/M)
- [Mundo X 网站](https://668993.xyz)
- Telegram：[@mconnectofficial](https://t.me/mconnectofficial)

## 重要注意事项

- 不要提交 `.env`、数据库密码、管理员令牌或节点通信密钥；
- 不要运行 `docker compose down -v`，它会删除 Redis 数据卷；
- 不要执行 `php artisan config:cache`，动态面板配置必须保持可重新载入；
- 不要在生产目录直接运行会执行 `git reset --hard` 的更新脚本；
- 更新前先备份数据库、`.env`、`config/v2board.php` 和主题配置。

## 上游要求

- PHP 7.3+；本 Docker 运行环境固定使用 PHP 8.2；
- MySQL；
- Redis；
- Composer；
- Laravel 8。

## 反馈

提交 Issue 时请提供可复现步骤、相关容器状态和已经打码的日志。不要公开密码、JWT、订阅令牌或节点通信密钥。
