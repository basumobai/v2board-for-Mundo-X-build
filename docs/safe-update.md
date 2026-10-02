# 已有面板安全更新

本流程用于本仓库的 Docker Compose 部署，需要本机 Docker、Git、flock、tar、sha256sum 和可连接的 MySQL TCP 数据库。更新会短暂停站；保留现有 `.env`、APP_KEY、Compose 项目名、端口、数据库、Redis 卷、面板设置、主题设置和未被 Git 跟踪的自定义资源。不要运行首次安装器，也不要删除卷。

## 更新

在面板目录执行 `bash update.sh`。旧版本的脚本没有自动备份，因此第一次升级到本版本时，使用下面的方式读取新版脚本，避免先 `git pull` 改动运行中的源码：

```bash
git fetch origin master
git show origin/master:update.sh | bash -s -- --project-dir "$PWD"
```

第一条命令必须成功后再执行第二条。此命令按你当前分支从同名 origin 分支快进更新，生产部署通常应位于 master。以后直接执行 `bash update.sh` 即可。可用 `UPDATE_BACKUP_ROOT=/path/outside/panel bash update.sh` 改变备份位置。

脚本拒绝未提交修改和非快进更新，避免覆盖手工修改的主题或部署文件。先提交这些修改并合并差异；不要使用 `reset --hard` 或 `git clean` 丢弃定制。

## 自动备份与失败处理

1. 先获取目标提交、检查 Compose 并拉取 MySQL 备份工具镜像，此时服务仍在线。
2. 停止入口、Web 和 Scheduler，等待旧流量/统计队列（含执行中的任务）排空，最多 10 分钟；再停止 Horizon。
3. 保存源码/配置/storage/主题归档、MySQL 事务快照和停止后的 Redis 数据卷归档，生成 SHA256SUMS。备份目录权限由 umask 077 限制；数据库凭据仅临时存于文件，成功或失败退出时都删除，不进入命令参数或日志。
4. 只有备份完整并生成 COMPLETE 标记后才快进源码，安装锁定依赖、迁移、结清遗留流量并刷新视图缓存，最后启动和健康检查。

备份默认位于项目旁的 `mundo-backups/`。目录含 `project.tar.gz`、`database.sql`、`redis.tar.gz`、`manifest.txt`、`SHA256SUMS` 和 `COMPLETE`。源码归档只排除项目根目录的 `.git`、vendor、node_modules；主题或自定义资源里的同名目录仍完整备份。依赖可按归档里的锁文件重新安装。备份失败且尚未切换源码时会尝试恢复旧服务；切换后失败不会自动倒退数据库或启动旧 Worker。

备份凭据来自应用的当前数据库配置。备份用户需要导出该数据库、触发器、例程和事件的权限；不具备权限时更新停止，请先处理权限。不要将备份目录放在项目/public 或可公开下载的位置。其他程序若也向同一数据库写入，应由维护者一起停止；本脚本只控制这个 Compose 实例。

## 恢复

先检查 `COMPLETE` 并在备份目录执行 `sha256sum -c SHA256SUMS`。保留失败现场，停止 gateway/web/horizon/scheduler/redis。恢复必须将**源码、MySQL 和 Redis 同一份快照一起恢复**，不要只回滚数据库后重放新队列。

推荐在新的私有项目目录解压 `project.tar.gz`，按 `manifest.txt` 的 old_commit 恢复 Git 源码并检查配置；原 `.env` 中的 Compose 项目名必须保留。通过 MySQL 客户端将 `database.sql` 导入原数据库（或先导入隔离库验证）。凭据使用权限 600 的客户端配置文件，避免在命令行中填写密码。将 `redis.tar.gz` 恢复到该实例原 Redis 卷之前，先保存失败后的卷，再清空该卷中的旧文件，避免遗留 AOF 混入快照。

按恢复的 composer.lock 安装依赖，执行 config:clear 和 view:cache，最后启动服务并检查健康状态、用户余额/流量、节点和主题。恢复是维护者的显式操作，脚本不会自动删除数据库或 Redis 数据。涉及流量账本的升级不要跳过数据库校验，也不要通过重新安装来恢复已有站点。

## 样式更新

自定义 CSS/JS 与管理端 Umi bundle 都使用内容哈希作为 URL 版本，但管理端覆盖层和 Umi bundle 使用**彼此独立的缓存键**。修改 CSS/自定义 JS 不会再让浏览器重新下载数 MB 的 Umi bundle；只有 `public/assets/admin/umi.js` 本身变化时才更新它的 URL。CDN 必须把查询参数计入缓存键，首页/后台 HTML 不应缓存为永久静态页面。更新后普通刷新即可获取发生变化的资源；已打开的标签页需要刷新。本版本不会强制中断正在编辑的表单。现有主题配置不会重置，新安装的默认顶栏使用浅色。既有站点若仍是深色用户顶栏，可在主题配置中将“顶部风格”选为“亮”。
