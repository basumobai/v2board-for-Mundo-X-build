# Mundo-X V2Board 上游同步与安全升级记录

日期：2026-11-01。仓库：`basumobai/v2board-for-Mundo-X-build`。版本：`1.7.5.20261101`。

## 范围

本地基线 `35aad69494d5acae0d83a6b16478596e43d811bc`；审查上游
`Mundo-Connect/v2board@f8fb72b4d2e909599e6ff8c37334c8ba47c4765f`；共同祖先
`4b1604d8220b60ba427779e3a54872b8cc40af5e`。上游独有 19 个提交、本地独有 63 个提交、上游变更 26 个文件。
本 PR 扩展原 PR-01，选择性吸收功能，完成仓库内的框架、依赖、Web 防护、隔离部署和扫描工作。
未整体覆盖代码，未伪造上游 Git 合并历史；最终合并以最新提交完整 CI 为准。

未连接生产服务器、商户账户、生产数据库或日志。生产实际 PHP 版本、入侵迹象、真实小额支付、
防火墙和备份完整性仍需部署方验收。仓库合并不等于生产发布。

## 认证、支付与数据一致性

- Cache/JWT 前拒绝非字符串和空白令牌，三个鉴权中间件同样检查；使用独立 SHA-256 缓存键。
  每次核对会话及实时账户状态，封禁、删除、撤权立即生效；模型修改密码/角色/封禁时撤销会话。
- 停止注册 Staff API；保留控制器采用字段白名单；用户模型隐藏密码、盐、算法和 remember token。
  用户本人登录与订阅必需的令牌交付保留，不能读取其他用户的订阅秘密。
- 支付 ID/UUID 同时绑定网关类型；全部 17 个内置网关统一拒绝空认证密钥；有效回调必须绑定订单 payment_id。
  核对协议提供且已认证的商户、币种、金额（含手续费）。无金额字段的旧协议只保证签名、配置和订单绑定。
- Coinbase 只接受 charge:confirmed 入账；BTCPay 要求 InvoiceSettled 并复查已配置商店发票；
  CoinPayments 检查商户、币种和原币金额。HMAC 验证原始字节，不能重编码或改动空白。人工 resolved 事件需人工核查。
  AdapterMan 请求显式注入 socket 原始正文，避免 CLI php://input 为空造成合法回调验签失败，后续请求不保留旧正文。
- 保留订单及用户行锁、事务重试、开通状态检查、流量重置边界；付款状态转换与配置/金额检查在同一事务，提交后入队。
  结账切换渠道同样加锁。重复通知、并发开通和取消竞态不重复增加余额。
- 恢复 Epusdt、MGate、BEasyPaymentUSDT 的 TLS 证书与主机名验证，无跳过验证选项。
- 保留 MySQL 报告账本、Redis 兼容批次、重复上报幂等、跨账期边界及结清流程。
  未删除支付插件、表、字段、历史订单或业务数据，框架迁移没有新增破坏性 SQL。

## 框架与运行时

在隔离工作区逐级解析依赖并通过回归：Laravel 9.52.23 → 10.50.4 → 11.57.1 → 12.70.2 → **13.39.0**。
中间 EOL 版本只用于兼容检查，不进入生产。PHP Docker 默认 **8.4.27-cli-alpine3.24**，构建和 CI 验证版本下限与关闭上传进度。

|组件|结果|
|---|---|
|Laravel|稳定版 13.39.0，约束 ^13.30，保留传统结构、现有路由及中文资源路径|
|邮件|Symfony Mailer，现代 mailers 配置兼容 MAIL_DRIVER；每个任务清理 SMTP transport 并恢复配置；失败正常重试|
|代理/维护|Laravel 内置 TrustProxies、PreventRequestsDuringMaintenance 和新 Symfony Forwarded 常量|
|日志|Monolog 3 LogRecord；递归隐藏密码、令牌、支付 key/sign、会话和异常对象；URI 不保存查询串，异常标题不写入 SQL/凭据|
|用户模型|Laravel Authenticatable，修正 provider 模型路径|
|测试|PHPUnit 12.5.42，现代 bootstrap 和数据提供器，保留真实 MySQL 并发|
|依赖|移除 fideloper/proxy、旧 Ignition、SwiftMailer 和开发分支约束，minimum-stability=stable|
|PHP 扩展|固定已构建验证的 PECL igbinary 3.2.17RC1 / redis 6.3.0；RC1 是 PECL 当前发行名称，后续更新仍需构建回归|

按锁文件 composer install，禁止在线上临时 composer update。更新脚本在一致性备份后删除旧 Laravel 生成的
config/provider/routes 缓存，避免引用已移除类，重新编译视图并重启常驻进程。
修正旧 DB::select(DB::raw(...)) 与新版数据库接口的不兼容。

## 上游 19 个提交

|提交|内容|最终处置|
|---|---|---|
|3cfb3f0d318e|面板下发 TLS|保留本地证书服务、指纹、管理端与已有测试|
|25ab8e081c3d|证书 pin|保留 PCS/SPKI 和 Sing-box 版本门槛，不向订阅输出私钥|
|aa87ceefda73|MGate/Paytaro|保留两者和历史配置，不采纳删除 MGate，补充安全防护|
|acc8d31ff99c|版本号|统一为本地 1.7.5.20261101，资源仍采用内容哈希|
|8661511bd5ef|XFF 展示|保留受支持传输限制，收紧实际面板代理信任|
|77dca7373f40|二维码支付|保留 EPayQrcode、PaytaroQR、受控 Laravel 桥接及浏览器组件|
|8b7a672cd388|合并/前端冲突|逐项合并支付与排序，保留本地布局、可访问性、CSS/JS，不覆盖整个 Umi|
|6e323e629aa4|独立前端域名|可信 FRONTEND_URL/Origin 实现，拒绝任意 Forwarded Host 决定支付返回地址|
|99f8526eddb7|支付编辑|正确回显渠道，网关配置隔离、空值保留、丢弃过期响应，重新打开重置取消草稿|
|9e940653ea31|永久用户日期|Shadowrocket null 到期显示长期有效，普通日期保持|
|c51301b3c92f|Mihomo KEM|Meta/Nyanpasu/Verge 的 Reality 加入 support-x25519mlkem768，普通 TLS 不添加|
|ee45b54c4683|历史合并|按功能归类验证，不执行整体覆盖|
|70371f756b39|移除 demo|本地已无默认演示账户说明，继续保持|
|209e0d0d0436|历史合并|按功能验证，不伪称 Git 历史已合并|
|495e96d82c88|MX 统计/排序|保留本地统计与账本，补充类型/ID 校验、复制数组、过滤索引映射、唯一行键与拖动手柄|
|e695843d1303|认证类型漏洞|移植并扩展缓存、会话和实时权限检查|
|3cb990215f82|停用 Staff|完成，不注册相关路由|
|cfabf395644b|支付安全|融合本地事务、锁、配置/金额/状态绑定及并发验证|
|f8fb72b4d2e9|上游头|本轮审查边界，功能按以上记录处置|

## CVE 和依赖审计

原始结果：[14 条基线公告](security/composer-audit-baseline-20261101.json)。
升级结果：[最终 Composer audit](security/composer-audit-upgraded-20261101.json)。
2026-11-01 最终锁文件审计为 **0 条公告、0 个弃用包，无忽略项**。后续新公告仍需持续复查。

|公告|版本与处置|
|---|---|
|PHP CVE-2026-109618 / CVE-2026-109619|PHP 8.4.27 修复版，构建门槛阻止旧版本|
|PHP CVE-2026-115560|按官方 10 月 23 日建议关闭 session.upload_progress；这是配置缓解，不声称 8.4.27 已有后续代码修复|
|Laravel CVE-2026-114509、CVE-2026-102279、CVE-2025-27515、PKSA-m5cs-t1y6-qpcs、PKSA-3r5d-mb8f-1qw9|13.39.0，认证/调试页/文件验证/临时签名/邮件 CRLF 公告不再命中；未以业务主要用 JWT 为由豁免旧框架|
|Commonmark PKSA-m2dq-1fhr-29b1 / PKSA-m4t9-vsgq-8khn|2.10.7，HTML 过滤和表格扫描公告不再命中|
|Flysystem CVE-2026-102601|3.36.0，路径规范化公告不再命中|
|HttpFoundation CVE-2026-108082|7.4.22，Cache-Control 解析公告不再命中|
|Mime CVE-2026-45070|7.4.22，邮件参数注入公告不再命中；实际模板经内存邮件传输验证|
|Yaml CVE-2026-109413、CVE-2026-45304、CVE-2026-45305、CVE-2026-45133|6.4.49，替换 4.4.x-dev；递归、别名膨胀与正则公告不再命中|
|JWT PKSA-y2cr-5h3j-g3ys / CVE-2025-45769|影响 <7.0.0，已锁定 7.1.0 不适用，删除旧忽略项|

JSON 保存原始公告链接、影响范围和日期。容器 OS、Nginx、Redis 以当次实际镜像 Trivy 报告为准，
不能从 Composer 零公告推断整个服务器无漏洞。

实际镜像扫描另外记录了 [274 条原镜像包级基线](security/container-audit-baseline-20261101.json)，
其中包含开发头文件映射到的内核公告，不等于容器正在运行相应内核代码。
本批将 PHP 基础系统迁移至 Alpine 3.24，构建后移除编译器/开发头文件；
Nginx 两种镜像固定 1.30.6 并安装底层安全更新，处理 brotli 与 curl 公告。没有据此增加扫描忽略。
修正后的实际镜像在 [CI 41297617432](https://github.com/basumobai/v2board-for-Mundo-X-build/actions/runs/41297617432)
扫描均为 **0 条 HIGH/CRITICAL**：PHP、标准 Nginx、非 root Nginx、Redis；该次整体部署检查仍失败，
不能将单项扫描通过当作完整 CI 通过。扫描 JSON、镜像记录和 SBOM 保存在其 runtime-security-and-sbom 产物中。

## Web 和容器加固

- CORS 只接受白名单/应用/可信前端，拒绝 Referer 推导和任意 Origin 反射，凭据默认关闭；预检不进入控制器。
  支付通知地址来自 BACKEND_URL 或已保存的网关配置。
- 代理默认仅信任 loopback，通过 TRUSTED_PROXIES 扩展；隔离部署额外仅信任本实例 gateway DNS 地址。
  本地健康检查不解析尚未启动的 gateway，避免 Nginx 等待 PHP 健康时发生 DNS 启动依赖循环。
  外层代理必须清除并重设 X-Forwarded-*。HTTPS 与真实 IP 不能依靠客户端自报。
- 加入 nosniff、SAMEORIGIN、Referrer/Permissions Policy、基础 CSP、HTTPS HSTS 与 API no-store。
  保留现有 React/主题行为，没有宣称实现严格脚本 nonce CSP。
- 管理接口默认每分钟 600 次，回调按配置/来源 IP 每分钟 1200 次；.env 可调整，超限返回 429/Retry-After。
- Redis Socket 777→770，明确共享 REDIS_GID；默认服务 no-new-privileges。
  私有配置原子写入继承目录所有者，保留维护账号备份权限，不向其他用户开放读取。
- 默认 compose 保留 host 网络与宿主机 MySQL 兼容；可选 compose.hardened.yaml 提供每实例 bridge、
  非 root、只读源码/rootfs、明确可写目录、cap_drop、独立维护容器及仅 loopback 入口。
  网络切换前必须验证 MySQL 地址与授权，见[隔离部署](hardened-deployment.md)。
- 备份读取有效数据库和网络配置，支持 host/bridge mysqldump、无网络且数据卷只读的 Redis 归档工具。
  保留排空队列、一致性快照和失败停止规则，不加入生产 reset --hard 或 down -v。

## 验证和交付

- 本地 PHPUnit 94 项 / 687 条断言，其中 2 项 MySQL 并发由 CI 执行；本地 PHP 8.3.6 仅为合成测试进程，
  不是发布运行时证明。CI 和容器使用 PHP 8.4.27。
- CI 使用真实 MySQL 8.4 多进程行锁测试，并在实际 MySQL/Redis/AdapterMan/Horizon 双实例中检查身份交替、
  即时撤权、原始 HMAC 和充值幂等。SQLite 不代替真实锁验证。
- 27 项 Node 测试覆盖安装、更新失败、配置/主题保留、资源与支付；浏览器覆盖桌面/手机真实产物、
  支付切换竞态、过滤后拖动、节点创建/旧响应、无白屏和资源版本。
- 两种部署分别演练更新和 MySQL/Redis 备份，SQL 快照恢复到独立空库验证；down -v 只用于明确临时 CI 数据清理。
- Composer audit 阻断公告；Trivy HIGH/CRITICAL 包含未修复项，产出应用和镜像 CycloneDX SBOM、JSON 和镜像 ID/digest。
  CodeQL 扫描自有 JavaScript 与 Actions，**不支持 PHP**。Action 固定完整 SHA，权限按作业授予，Dependabot 每周提出更新。
- GitHub PR 最新完整检查是最终验收依据，失败/未执行项不能计通过。新公告和不同构建仍须重新扫描。
  独立运行的 PHP 演练在异常时显式返回非零，避免框架控制台异常处理器把失败记录成成功。

## 上线和回滚

依照 [safe-update.md](safe-update.md)，保留同一维护点的 MySQL、Redis、源码、主题与密钥。
重建镜像并重启全部 PHP 常驻进程，记录 php -v、镜像 ID/digest 和扩展版本。
框架升级和隔离网络迁移分别安排维护点，禁止未验证 MySQL 就改变生产网络。

部署方在脱敏副本验证后，核对支付配置、历史订单、证书链、手续费、真实小额支付、客户端握手、MX 节点报告、
余额及跨账期流量。检查线上日志后决定是否轮换凭据，不能仅凭静态风险断定已经遭受攻击。
故障时暂停扩大流量，恢复同一批整套快照；不能只回滚代码、余额或 Redis 的一部分。
回滚版本仍须保留认证与支付修复。

## 官方参考

- [PHP 8.4.27](https://www.php.net/releases/8_4_27.php) 与 [安全补充公告](https://www.php.net/archive/2026.php)
- [Laravel 13 升级](https://laravel.com/docs/13.x/upgrade) 与 [支持周期](https://laravel.com/docs/13.x/releases)
- [TokenGuard 公告](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
- [调试页 XSS 公告](https://github.com/laravel/framework/security/advisories/GHSA-jh5r-qr3c-85q8)
- [CodeQL 语言范围](https://docs.github.com/en/code-security/concepts/code-scanning/codeql/codeql-code-scanning)
- [BTCPay 集成](https://docs.btcpayserver.org/Development/ecommerce-integration-guide/)
- [CoinPayments IPN 示例](https://www.coinpayments.net/downloads/cpipn.phps)
