# Mundo-X V2Board 安全升级实施记录

审计日期：2026-11-01。目标仓库：`basumobai/v2board-for-Mundo-X-build`。

## 基线和发布范围

本地基线为 `35aad69494d5acae0d83a6b16478596e43d811bc`，上游为
`Mundo-Connect/v2board@f8fb72b4d2e909599e6ff8c37334c8ba47c4765f`，共同祖先为
`4b1604d8220b60ba427779e3a54872b8cc40af5e`。GitHub Compare 确认上游独有 19 个提交、
本地独有 63 个提交，上游相对共同祖先修改 26 个文件。

本批实现计划中的 PR-01，并提前加入 PHP 镜像安全基线。框架迁移、容器网络改造、
其余上游功能同步及生产发布必须继续按独立阶段验证。本批不代表整个加固计划已经完成。
未访问生产服务器、生产数据库、支付商户账户或线上日志，不能判断实际入侵情况或生产 PHP 版本。

## 本批实现

- 在缓存和 JWT 解析前拒绝数组、对象、布尔值、数字、空白认证参数；三个鉴权中间件执行同样检查。
- 使用带 SHA-256 的独立认证缓存键，防止把其他业务缓存当作已验证身份。
- 每次使用缓存身份都核对会话；撤销单个或全部会话立即使缓存令牌失效。仍然有效的 JWT 不需要重新登录。
- 停止注册全部 Staff 路由。保留控制器供后续权限设计审查，员工用户信息查询采用字段白名单；
  用户模型统一隐藏密码哈希、密码算法、盐和 remember token。用户自己的订阅功能仍然可用。
- 按支付 ID 或 UUID 加载配置时，同时核对网关类型；不存在的配置返回受控错误。
  管理端切换支付类型时展示新网关的空表单，避免将旧凭据传给新网关，并保留正常编辑流程。
- 回调付款记录与订单的 `payment_id` 必须一致。回调金额可用时，在订单行锁内校验总额加手续费，
  并在同一事务中完成未付到已付的状态转换。切换结账渠道也使用订单行锁，不能修改已支付订单。
- 保留 `OrderService::open()` 原有的订单及用户行锁、事务重试、流量重置边界和开通幂等机制。
- Epusdt 拒绝空密钥、嵌套参数和未成功状态；校验签名覆盖的商户及币种信息（如有）。
  Paytaro 保留本地原有空密钥防护，并补充参数与金额检查；EPay、EPayQrcode、MGate 补充空密钥保护。
- 在统一回调入口阻止全部内置网关使用空认证密钥；Coinbase 仅在 `charge:confirmed` 后结算，
  BTCPay 必须收到 `InvoiceSettled` 并从已配置商店复查发票的状态、金额及币种。创建或处理中的事件不入账。
  Coinbase 的旧版人工 resolved 事件需要人工核验，未自动开通；本批未迁移到新的 Coinbase Business API。
- Coinbase、BTCPay、CoinPayments 对原始请求字节验签，并使用大小写不敏感的请求头读取，避免重编码导致验签错误。
  CoinPayments 校验商户、币种和原币金额；支付宝及微信补充应用/商户及可用金额的绑定。
- 恢复 Epusdt 两条出站请求、MGate 及 BEasyPaymentUSDT 的 TLS 证书、主机名验证，没有新增“跳过验证”选项。
- 明确采用 `php:8.2.35-cli-bookworm`，构建时验证 PHP 下限；镜像默认配置和 CLI 配置均关闭
  `session.upload_progress.enabled`。该标签仍需在构建后记录实际 digest，并扫描镜像内 OS 包。
- 增加认证、支付回调、会话撤销回归测试，以及真实 MySQL / InnoDB、多进程并发结算测试。
  CI 的 PHP 作业提供独立 MySQL 8.4 测试数据库；容器检查验证 PHP 补丁版本和上传进度配置。

## 上游 19 个提交的处理清单

这里记录功能审查状态，不把“本地已有相似实现”计为 Git 历史已经合并。

|上游提交|内容|处理记录|
|---|---|---|
|`3cfb3f0d318e`|远端生成 TLS 证书|基线已有本地 `TlsCertificateService` 和管理端实现，保留；兼容性归 PR-02|
|`25ab8e081c3d`|证书 pin 订阅参数|基线已有证书 pin 实现及测试，保留；客户端兼容性归 PR-02|
|`aa87ceefda73`|MGate 替换 Paytaro|基线已有 Paytaro；明确保留 MGate，本批补充其 TLS 和密钥防护|
|`acc8d31ff99c`|版本号调整|待最终发布版本确定后统一更新|
|`8661511bd5ef`|XFF 展示|基线已有相关资源与测试；真实代理环境验收归 PR-02/04|
|`77dca7373f40`|二维码支付|基线已有 EPayQrcode、PaytaroQR 和 Laravel 页面桥接；保留本地实现|
|`8b7a672cd388`|上游合并及管理端冲突|不整体覆盖 Umi 产物，逐项浏览器验证归 PR-02|
|`6e323e629aa4`|返回独立前端域名|待 PR-02；不能照搬任意 Origin / Forwarded Host 作为返回地址|
|`99f8526eddb7`|支付编辑默认项|待 PR-02 与本地前端定制合并验证|
|`9e940653ea31`|Shadowrocket 永久用户日期|待 PR-02|
|`c51301b3c92f`|Mihomo X25519MLKEM768|待 PR-02，检查支持及不支持该参数的客户端|
|`ee45b54c4683`|上游历史合并|按以上实际功能处理，不作为独立功能覆盖|
|`70371f756b39`|移除 demo|基线已无演示账户说明；发布说明继续避免包含假默认账户|
|`209e0d0d0436`|上游历史合并|按功能清单处理，未宣称 Git merge 已完成|
|`495e96d82c88`|MX 流量统计|基线已有 MX 排名及排序；保留 MySQL 报告账本和批次幂等；补充排序校验归 PR-02|
|`e695843d1303`|认证参数类型|本批移植并扩展缓存隔离、会话撤销测试|
|`3cb990215f82`|停用未完成 Staff API|本批完成，不注册 Staff 路由|
|`cfabf395644b`|支付错配、空密钥及重复开通|本批融合；本地开通行锁实现保留，增强回调的配置及金额绑定|
|`f8fb72b4d2e9`|上游历史合并|审计上游头；未整体合并或覆盖本地仓库|

## 安全公告与剩余工作

PHP 官方 2026-10-21 公告确认 `CVE-2026-109618`、`CVE-2026-109619`。
PHP 8.2 的修复版本为 8.2.35，8.4 的修复版本为 8.4.27；8.2 于 2026-12-31 结束安全维护。
2026-10-23 官方另建议关闭上传进度，涉及后续公告 `CVE-2026-115560` 的特殊会话配置路径。
本批配置已关闭此功能，但不能把配置缓解等同于修复所有未来 PHP 漏洞。

对原锁文件执行 `composer audit --locked --format=json`，发现 6 个包共 14 条公告，另有
`swiftmailer/swiftmailer` 停止维护。完整原始审计保存在
[`security/composer-audit-baseline-20261101.json`](security/composer-audit-baseline-20261101.json)。
**这是未解决的基线结果，不能写成“依赖审计通过”。**

|依赖|公告|本批适用性判断和后续处理|
|---|---|---|
|Laravel 8|`CVE-2026-114509` TokenGuard 认证绕过|现有业务使用自定义 JWT 中间件；未找到 `auth:api` 路由或 API guard 调用。配置仍有 TokenGuard，框架依赖仍受影响，迁移必须处理|
|Laravel 8|`CVE-2026-102279` 调试页 XSS|运行时 `APP_DEBUG` 待核验；本批未修改框架|
|Laravel 8|临时签名 URL 路径混淆、邮件验证规则 CRLF、`CVE-2025-27515`|待逐入口检查和受支持框架迁移，不以当前未发现调用判定永久不适用|
|league/commonmark 2.10.0|原始 HTML 过滤绕过、GFM 表格扫描 DoS|检查 Markdown 入口及扩展配置，更新到已修复稳定版本|
|league/flysystem 1.1.10|`CVE-2026-102601`|随框架迁移到受支持分支，复查文件路径输入|
|symfony/http-foundation 5.4.50|`CVE-2026-108082`|更新已修复补丁，复查 Cache-Control 解析入口|
|symfony/mime 5.4.52|`CVE-2026-45070`|升级到已修复版本并验证邮件发送|
|symfony/yaml 4.4.x-dev|`CVE-2026-109413`、`CVE-2026-45304`、`CVE-2026-45305`、`CVE-2026-45133`|移除开发分支约束，升级支持分支，检查可控 YAML 输入|

已用 Packagist 原始 API 核实 `PKSA-y2cr-5h3j-g3ys` 对应
`GHSA-2x45-7fc3-mxwq` / `CVE-2025-45769`，影响 `firebase/php-jwt <7.0.0`。
锁定的 JWT 为 v7.1.0，不受该公告影响，本批移除了这个不再需要的审计忽略项。
没有增加或扩大其他忽略，也没有据此将另外 14 条公告标记为已修复。

框架最终目标仍为 **受维护的 Laravel 13 + 已修复的 PHP 8.4**，实际补丁版本必须再次核验。
已核实 TokenGuard 至少需要 Laravel 12.68.0 / 13.29.0；调试页 XSS 公告另要求
12.69.0 / 13.30.0，因此不能只写“升级到 Laravel 13.0”。
8→9→10→11→12→13 为隔离分支内的兼容性检查步骤，中间 EOL 版本不进入生产。

## 后续 PR 和验收门槛

|阶段|必须完成|发布前置条件|
|---|---|---|
|PR-01 本批|认证、支付、PHP 紧急基线|安全回归、真实 MySQL 并发、现有安装及前端检查通过；核验生产受影响配置|
|PR-02|其余上游功能、管理端冲突、客户端兼容、可信前端返回域名|逐项更新上表，桌面/手机浏览器验收；原计费账本和流量边界测试通过|
|PR-03|Laravel、PHP 8.4、依赖稳定版及锁文件、移除无效忽略|框架逐大版本检查、AdapterMan/Horizon/邮件测试、CVE 适用性复核及可重复构建|
|PR-04|隔离容器网络、可写目录、非 root、Redis 组权限、CORS、可信代理、安全响应头|宿主机 MySQL 连通、多实例端口隔离、队列及账期处理测试；不得直接套用网络改造|
|PR-05|Composer / Trivy / CodeQL、SBOM、供应链权限和更新记录|严重/高危公告完成适用性及修复核验，备份恢复与发布门槛能够阻断危险发布|

## 验证记录

- 本地已完成锁文件依赖安装和 PHP 源码语法检查。
- 无 MySQL 环境时的 PHPUnit：81 项；79 项通过，2 项真实 MySQL 并发测试按条件跳过。
- Node 安装、更新、支付和 TLS 资源检查：27 项全部通过。
- 本地测试 PHP 为隔离的 Ubuntu PHP 8.3.6 发行版包，仅运行合成测试数据；不是生产版本证明。
- 当前执行器不允许 MySQL 创建 Unix Socket，本地无法启动真实 MySQL 服务；并发验收由新增 CI
  MySQL 作业执行。首个提交的 CI 已通过真实 MySQL 8.4 的全部 75 项测试（557 条断言），
  并通过容器、双实例安装、前端和 Compose 作业；支付插件扩展修复后的最新提交仍需以最新 CI 为准。
  没有以 SQLite 测试代替真实行锁证明。
- Docker 构建、容器扩展、多实例安装恢复和浏览器作业结果以本 PR 最新 CI 为准；未运行的检查不得标记通过。

## 发布与回滚操作要求

1. 先检查本 PR 所有 CI 作业及人工审查，再选择固定提交和构建镜像；记录镜像 digest、PHP `php -v`
   及关键扩展版本，重建并重启全部 Web、Horizon、Scheduler 工作进程，不能只拉取代码。
2. 在维护点排空现有流量处理队列，按 `docs/safe-update.md` 生成同一批 MySQL、Redis、源码、配置、
   密钥和主题备份；不得对共享 Redis 执行全库清理，不要求全部用户退出登录。
3. 发布前列出当前支付方式，核对 `payment_id`、Epusdt 密钥/证书链、金额单位与手续费。
   本批校验只使用网关已认证返回的金额，不使用未验签的原始请求补全金额。
   没有金额字段的旧协议只执行配置、订单和签名校验，不能宣称已核验该协议的支付金额。
4. 在脱敏数据副本验收后，先验证认证、真实小额支付、重复回调、订阅、MX 上报和流量账本，
   再恢复正常请求。没有访问商户及生产环境时不能代替这些人工验收。
5. 出现一致性异常应暂停业务并恢复同一维护点的整套快照。回滚代码不能复活已撤销会话；
   若退回旧认证实现，必须同时保留认证类型修复，不能把原绕过路径重新上线。
6. 不将 `git reset --hard`、`docker compose down -v`、删除表/字段或只回滚部分账本作为常规方案。

## 官方参考

- [PHP 2026 安全公告与上传进度补充说明](https://www.php.net/archive/2026.php)
- [PHP 8.2.35](https://www.php.net/releases/8_2_35.php)
- [Laravel 13 支持周期](https://laravel.com/docs/13.x/releases)
- [Laravel TokenGuard 公告](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
- [Laravel 调试页 XSS 公告](https://github.com/laravel/framework/security/advisories/GHSA-jh5r-qr3c-85q8)
- [BTCPay 发票结算及回调](https://docs.btcpayserver.org/Development/ecommerce-integration-guide/)
- [CoinPayments 官方 IPN 示例](https://www.coinpayments.net/downloads/cpipn.phps)
- 其他公告的原始来源与版本范围见审计 JSON，实际升级时重新查询。
