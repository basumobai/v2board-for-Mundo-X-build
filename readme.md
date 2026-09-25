<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="Mundo logo" width="130" height="130" align="right"/>

# V2Board for Mundo X

基于 [Mundo-Connect/v2board](https://github.com/Mundo-Connect/v2board) 的 Docker 部署分支，面向 Ubuntu、宝塔 Nginx、宿主机 MySQL 和 Mundo X 节点。

本分支提供：

- PHP 8.2、AdapterMan、Nginx、Redis、Horizon 和 Scheduler 的 Compose 配置；
- 安装时自定义 Web / Nginx 端口与 Worker 数量，默认端口 6600 / 7001，仅监听本机；
- 同机多实例的容器、镜像、Redis 数据卷和缓存前缀隔离；
- AdapterMan 常驻 Worker 下按内容变化刷新的动态配置缓存；
- production 环境队列配置、视图预编译、HTTP 健康检查与日志轮转；
- 节点报告以 MySQL 报告账本保证重试幂等；用户流量扣费与日统计在同一事务完成；
- 兼容旧队列和 Redis 流量批次，重置账期时记录边界，避免迟到报告扣减新账期；
- 更新前排空旧队列，严格校验数据库迁移和账本索引，校验失败时停止启动新版服务；
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

## 已有站点升级

本次更新会新增流量报告与 Redis 批次的 MySQL 账本、用户账期字段和必要索引。请在维护窗口执行，并先在**生产数据副本**上验证升级；升级过程中 Web 入口会暂时停止。

1. 备份并确认能够恢复 MySQL 数据库、**当前实例的 Redis 数据卷**、`.env`、`config/v2board.php`、主题配置和自定义资源。MySQL 与 Redis 的备份应对应同一个维护时间点；不要只恢复其中一份后直接重放旧队列。
2. 在已安装的 Git 仓库目录检查 `git status --short`。处理好本地修改，确认使用正确的实例名、端口和 Redis 卷，然后直接运行 `bash update.sh`。**不要先执行 `git pull`**：脚本会先获取远端代码，再停止旧服务、等待旧 `traffic_fetch` 和 `stat` 队列排空，最后切换代码、校验迁移并结算遗留 Redis 批次。
3. 更新完成后验证 `docker compose ps`、管理员登录、节点报告与用户用量和日统计的一致性，以及 Horizon 队列状态。先恢复少量节点上报，再逐步扩大，持续观察 MySQL 锁等待、队列延迟、报告失败数和账本增长。

旧报告超过 30 天会停止自动重放，需要人工核账。升级脚本或迁移校验失败时，先排查错误和队列状态；不要运行首次安装器，也不要单独回滚 MySQL 或 Redis。详细命令与故障处理见[部署指南](./How%20to%20build.md)。

**验证范围：**[PR #6 的完整 CI](https://github.com/basumobai/v2board-for-Mundo-X-build/actions/runs/36098235504) 已通过 60 项 PHPUnit 测试、25 项 Node 测试、双实例全新安装以及真实 MySQL/Redis 流量探针。尚未在生产数据副本上实跑 `update.sh`，也未做生产负载压测；上线后的吞吐、锁等待和队列积压需在灰度中测量。

## Mundo X

- [Mundo X 后端](https://github.com/Mundo-Connect/M)
- [Mundo X 网站](https://668993.xyz)
- Telegram：[@mconnectofficial](https://t.me/mconnectofficial)

## 重要注意事项

- 不要提交 `.env`、数据库密码、管理员令牌或节点通信密钥；
- 不要运行 `docker compose down -v`，它会删除 Redis 数据卷；
- 不要执行 `php artisan config:cache`，动态面板配置必须保持可重新载入；
- 不要在生产目录直接运行会执行 `git reset --hard` 的更新脚本；
- 更新前先备份数据库、Redis 数据卷、`.env`、`config/v2board.php` 和主题配置。

## 上游要求

- PHP 7.3+；本 Docker 运行环境固定使用 PHP 8.2；
- MySQL；
- Redis；
- Composer；
- Laravel 8。

## 反馈

提交 Issue 时请提供可复现步骤、相关容器状态和已经打码的日志。不要公开密码、JWT、订阅令牌或节点通信密钥。
