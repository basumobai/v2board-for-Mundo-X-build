# 用户端视觉与更新验收

默认主题采用浅色实底内容，玻璃仅用于浅色侧栏和顶栏。已有 theme_sidebar/theme_header 的 dark 配置继续生效，使用实色深蓝灰与浅色文字；default/green/black/darkblue 四种强调色保留。登录页只有一层实色卡片和阴影；普通内容不统一裁切 overflow，避免裁掉下拉与焦点。

自定义 CSS/JS 通过内容哈希更新 URL，动态 Umi 主题链接也带同一版本，覆盖样式位于其后，Dark Reader 节点保持原有顺序。已有主题配置与 custom_html 不重置；其他主题沿用各自资源，仅在其存在自定义资源时计算版本。

`node tests/frontend.browser.mjs` 渲染仓库已有 React 构建，通过隔离 API fixture 检查 1440px/390px 登录、订阅仪表盘、购买页、订单、个人中心、订阅重置确认弹窗、手机导航及 1.5 倍缩放仿真；验证四主题色、深浅导航、降低透明度、减少动画，以及暗亮模式刷新后持久化。CI 保存截图。viewport 允许用户缩放，不设置 user-scalable=no 或 maximum-scale=1。

部署后仍须使用实际账户核对套餐、订单、订阅链接、余额和主题；真机捏合手势、软键盘及系统偏好不由浏览器仿真替代。更新遵循 [安全更新说明](safe-update.md)，无需重装或重建配置。
