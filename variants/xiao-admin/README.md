# Xiao 管理员前端

这是一份独立的管理员前端安装包：沿用本仓库已经改好的浅色界面、悬浮导航、手机布局、主题切换、辅助功能以及节点新增/刷新修复，适配 [wyx2685/v2board](https://github.com/wyx2685/v2board) 原版。

核对版本：Xiao `70371f756b392dc17ff8bb9105591b34d62deeb3`；界面来源 `821ab8d61050178a632963642c8a544347e11553`。具体 API 路由摘要和 V2node 校验规则见 `compatibility.json`。

| 功能 | Xiao 版处理 |
| --- | --- |
| 仪表盘、统计、用户、套餐、订单、工单、优惠券、礼品卡、知识库、主题、队列 | 保留原版接口与界面 |
| V2node、Shadowsocks、VMess、Trojan、Hysteria、TUIC、VLESS、AnyTLS | 保留新增、编辑及原版操作 |
| Mundo X 节点、MC1、Mundo RDP | 从模型、菜单、筛选和表单中移除 |
| 支付方式 | 使用 Xiao 后端动态返回的列表；移除 Mundo 的 MGate 专属提示 |
| 静态资源缓存 | 在 Blade 中计算独立的界面/应用缓存键，兼容原版路由传入的变量 |

## 构建

在本仓库根目录使用 Node.js 22+：

```bash
node scripts/build-xiao-admin.mjs
tar -C test-results -czf test-results/xiao-admin.tar.gz xiao-admin
```

GitHub 的 `Xiao admin` workflow 也会生成 `xiao-admin-package` 下载包。构建只复用 `public/assets/admin`，应用经过校验的兼容补丁，并加入独立的管理员 Blade 模板。应用 bundle 一旦变化，构建会停止，需重新核对兼容补丁。目标服务器安装时不需要 Node.js。

## 安装到现有 Xiao 站点

解压生成的包后，在 `xiao-admin` 目录执行：

```bash
bash install.sh install /www/wwwroot/你的Xiao站点目录
```

脚本验证包校验和及目标管理 API 路由，备份原管理员资源和模板，然后替换 `public/assets/admin` 与 `resources/views/admin.blade.php`。其他路由、控制器、数据库、用户前端和配置均不属于安装内容。备份位于站点目录旁，目录权限为 700。

安装完成后，必须在该站点的 PHP 运行环境执行 `php artisan view:clear`。若站点有宿主机 PHP 和 Composer 依赖，脚本会自动执行；宝塔多 PHP 环境可指定 `PHP_BIN=/www/server/php/81/bin/php`；容器部署则进入实际站点容器执行。无需运行 `init.sh`、`update.sh`、迁移或清空配置缓存。

脚本会打印备份路径及完整回滚命令：

```bash
bash install.sh restore /www/wwwroot/你的Xiao站点目录 /路径/打印出的备份目录
```

目标 API 路由与核对版本不同会停止安装；先核对差异，避免把未支持的选项暴露给旧后端。上游更新可能覆盖前端，更新后需重新核对并安装。

## 验证范围

契约测试核对 Xiao 原始路由与协议校验，验证包构建、校验和、安装/回滚和后端文件不被替换。浏览器测试使用实际发布的 React/Umi bundle、Xiao API 白名单及协议参数校验，覆盖桌面/390px 登录、仪表盘、用户、节点、抽屉、弹窗、主题、减少动画、节点保存后的刷新竞态。API 数据为隔离数据，不代表已在生产数据库中安装验收。
