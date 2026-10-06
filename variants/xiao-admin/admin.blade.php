@php
    // Xiao's web route supplies no FrontendAssets service or UI cache keys.
    // Compute independent keys here so this view works with its unchanged backend.
    $uiHashes = [];
    foreach (['custom.css', 'custom.js'] as $asset) {
        $assetPath = public_path('assets/admin/' . $asset);
        $uiHashes[] = is_file($assetPath) ? hash_file('sha256', $assetPath) : 'missing';
    }
    $admin_ui_version = substr(hash('sha256', implode(':', $uiHashes)), 0, 20);
    $bundlePath = public_path('assets/admin/umi.js');
    $admin_bundle_version = is_file($bundlePath) ? substr(hash_file('sha256', $bundlePath), 0, 20) : $version;
    $theme_header = config('v2board.frontend_theme_header', 'light');
@endphp
<!DOCTYPE html>
<html lang="zh-CN" data-mundo-theme="{{$theme_color}}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#f3f6fb">
    <meta name="color-scheme" content="light">
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$version}}">
    <link id="mundo-admin-overrides" rel="stylesheet" href="/assets/admin/custom.css?v={{$admin_ui_version}}">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        // Guard the legacy skip fragment before the hash router sees it. Keep the
        // listener alive so same-document edits/pasted old URLs are recovered too.
        (function () {
            function recoverLegacySkipRoute() {
                if (window.location.hash === '#main-container') {
                    window.history.replaceState(
                        window.history.state,
                        '',
                        window.location.pathname + window.location.search + '#/dashboard'
                    );
                }
            }

            recoverLegacySkipRoute();
            window.addEventListener('hashchange', recoverLegacySkipRoute, true);
        }());
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            ui_version: '{{$admin_ui_version}}',
            bundle_version: '{{$admin_bundle_version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body class="mundo-admin-shell">
<button class="mundo-skip-link" type="button">跳到主要内容</button>
<noscript>
    <div class="mundo-noscript" role="alert">管理员界面需要启用 JavaScript 才能使用。</div>
</noscript>
<div id="root"></div>
<script src="/assets/admin/vendors.async.js?v={{$version}}"></script>
<script src="/assets/admin/components.async.js?v={{$version}}"></script>
<script src="/assets/admin/umi.js?v={{$admin_bundle_version}}"></script>
<script src="/assets/admin/custom.js?v={{$admin_ui_version}}"></script>
</body>

</html>
