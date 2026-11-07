<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="Mundo logo" width="130" height="130" align="right"/>

# V2Board for Mundo X

基于 [Mundo-Connect/v2board](https://github.com/Mundo-Connect/v2board) 的 Docker 部署分支，面向 Ubuntu、宝塔 Nginx、宿主机 MySQL 和 Mundo X 节点。

本分支提供：

- Laravel 13、PHP 8.4.27、AdapterMan、Nginx、Redis、Horizon 和 Scheduler 的 Compose 配置；
- 安装时自定义 Web / Nginx 端口与 Worker 数量，默认端口 6600 / 7001，仅监听本机；
- 同机多实例的容器、镜像、Redis 数据卷和缓存前缀隔离；
- AdapterMan 常驻 Worker 下按内容变化刷新的动态配置缓存；
- production 环境队列配置、视图预编译、HTTP 健康检查与日志轮转；
- 节点报告以 MySQL 报告账本保证重试幂等；用户流量扣费与日统计在同一事务完成；
- 兼容旧队列和 Redis 流量批次，重置账期时记录边界，避免迟到报告扣减新账期；
- 更新前排空旧队列，严格校验数据库迁移和账本索引，校验失败时停止启动新版服务；
- V2Board 管理员令牌驱动的 Horizon 鉴权；
- 数据库事务跨请求隔离；
- 严格认证与即时撤权、支付配置/金额绑定、订单并发幂等和敏感日志脱敏；
- 可选的独立网络、非 root、源码只读部署，以及依赖审计、镜像扫描和 SBOM；
- Mundo X、VMess、VLESS、Shadowsocks 等节点复制修复；
- 完整安装、验收、备份和故障排查文档。

## 部署文档

[阅读完整 Docker 部署与排错指南](./How%20to%20build.md)

[安全升级记录与上游提交处置](./docs/security-upgrade-20261101.md) · [隔离部署迁移](./docs/hardened-deployment.md) · [安全更新与恢复](./docs/safe-update.md)

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

本次更新将框架迁移至 Laravel 13，并保留流量报告与 Redis 批次的 MySQL 账本、用户账期字段和必要索引。请在维护窗口执行，并先在**生产数据副本**上验证升级；升级过程中 Web 入口会暂时停止。更新脚本会重建 PHP/Nginx 镜像并清理旧框架生成的缓存，不会自动切换隔离网络。

1. 备份并确认能够恢复 MySQL 数据库、**当前实例的 Redis 数据卷**、`.env`、`config/v2board.php`、主题配置和自定义资源。MySQL 与 Redis 的备份应对应同一个维护时间点；不要只恢复其中一份后直接重放旧队列。
2. 在已安装的 Git 仓库目录检查 `git status --short`。处理好本地修改，确认使用正确的实例名、端口和 Redis 卷，然后直接运行 `bash update.sh`。**不要先执行 `git pull`**：脚本会先获取远端代码，再停止旧服务、等待旧 `traffic_fetch` 和 `stat` 队列排空，最后切换代码、校验迁移并结算遗留 Redis 批次。
3. 更新完成后验证 `docker compose ps`、管理员登录、节点报告与用户用量和日统计的一致性，以及 Horizon 队列状态。先恢复少量节点上报，再逐步扩大，持续观察 MySQL 锁等待、队列延迟、报告失败数和账本增长。

旧报告超过 30 天会停止自动重放，需要人工核账。升级脚本或迁移校验失败时，先排查错误和队列状态；不要运行首次安装器，也不要单独回滚 MySQL 或 Redis。详细命令与故障处理见[部署指南](./How%20to%20build.md)。

**验证范围：**[PR #16](https://github.com/basumobai/v2board-for-Mundo-X-build/pull/16) 扩展至 94 项 PHPUnit 测试、27 项 Node 测试、真实 MySQL 并发和桌面/手机浏览器回归。部署演练覆盖双实例安装、Redis 隔离、真实 Horizon 任务、默认/隔离网络安全更新及备份恢复，以该 PR 最终提交的完整 CI 为准。测试使用一次性数据库；尚未访问生产数据副本、真实支付商户或执行生产负载压测，上线仍需验证吞吐、锁等待和队列积压。

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

## 当前运行要求

- PHP 8.3+；本 Docker 运行环境使用 PHP 8.4.27 / Alpine 3.24，并在构建时安装系统安全更新；
- MySQL；
- Redis；
- Composer 按锁文件安装；
- Laravel 13.39.0。非 Docker 部署须自行验证 PHP 补丁和扩展；旧 Laravel 8 环境不能直接复用旧 vendor。

## 反馈

提交 Issue 时请提供可复现步骤、相关容器状态和已经打码的日志。不要公开密码、JWT、订阅令牌或节点通信密钥。

## 私有仓库：服务器拉取与更新

仓库转为 Private 后，匿名 HTTPS `git clone` / `git pull` 无法继续使用。推荐给每台服务器配置**每仓库独立的只读 SSH Deploy Key**，不需要把 PAT 放进 README、Git URL 或环境配置。

1. 在需要访问此项目的服务器上用 root 运行：

```bash
ssh-keygen -t ed25519 -f /root/.ssh/github_v2board -N '' -C 'v2board-for-Mundo-X-build-deploy-readonly'
cat /root/.ssh/github_v2board.pub
```

2. 把输出的**公钥**加入对应私有仓库 `Settings → Deploy keys → Add deploy key`，不要勾选 `Allow write access`。私钥只保存在服务器（推荐权限 `0600`），不要复制到 GitHub 或公开文档。

3. 克隆并设置此项目专用的 SSH 身份：

```bash
GIT_SSH_COMMAND='ssh -i /root/.ssh/github_v2board -o IdentitiesOnly=yes' \
  git clone git@github.com:basumobai/v2board-for-Mundo-X-build.git
cd v2board-for-Mundo-X-build
git config core.sshCommand 'ssh -i /root/.ssh/github_v2board -o IdentitiesOnly=yes'
git fetch origin
```

已有 Git 工作树可先备份，再用 `git remote set-url origin git@github.com:basumobai/仓库名.git` 修改 origin，并设置上述 `core.sshCommand`。之后 `git pull` / `git fetch` 会自动使用只读 Deploy Key；GitHub SSH 主机指纹应按官方文档校验。部署密钥与应用内部的 API Key、节点 Token 是两种不同的凭证。

**Fork 迁移提醒：** 本仓库当前仍是公开 Fork。方案 B 的正确顺序为：先建立独立的 Private 仓库，完整镜像推送代码及分支、Tag；核验新仓库后将生产目录的 `origin` 改成实际新仓库地址。上方克隆地址只有在该原仓库本身可设为 Private 的情况下才适用；对于独立副本，请替换为新仓库名。`update.sh` 本身通过 `git fetch origin` 获取更新，只要部署密钥配置好即可继续工作。**不要在迁移前删除原 Fork，也不要在生产目录执行镜像覆盖命令。**
