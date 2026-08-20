<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="Mundo logo" width="130" height="130" align="right"/>

# V2Board for Mundo X

基于 [Mundo-Connect/v2board](https://github.com/Mundo-Connect/v2board) 的 Docker 部署分支，面向 Ubuntu、宝塔 Nginx、宿主机 MySQL 和 Mundo X 节点。

本分支提供：

- PHP 8.2、AdapterMan、Nginx、Redis、Horizon 和 Scheduler 的 Compose 配置；
- 仅监听 `127.0.0.1:6600` 与 `127.0.0.1:7001` 的反向代理结构；
- AdapterMan 常驻 Worker 下的动态配置与主题配置刷新；
- V2Board 管理员令牌驱动的 Horizon 鉴权；
- 数据库事务跨请求隔离；
- Mundo X、VMess、VLESS、Shadowsocks 等节点复制修复；
- 完整安装、验收、备份和故障排查文档。

## 部署文档

[阅读完整 Docker 部署与排错指南](./How%20to%20build.md)

最短流程：

```bash
git clone https://github.com/basumobai/v2board-for-Mundo-X-build.git mundo-v2board
cd mundo-v2board

docker compose build --pull
docker compose up -d redis
docker compose run --rm web composer install \
  --no-dev --prefer-dist --optimize-autoloader --no-interaction
docker compose run --rm web php artisan v2board:install
docker compose run --rm web php artisan config:clear
docker compose up -d
```

安装前请先阅读完整指南。V2Board 仍需要数据库和管理员初始化，因此全新安装不是单纯执行一次 `docker compose up -d`。

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
