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
        // Recover links produced by the old skip control before the hash router starts.
        if (window.location.hash === '#main-container') {
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + '#/dashboard');
        }
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            ui_version: '{{$admin_ui_version}}',
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
<script src="/assets/admin/umi.js?v={{$admin_ui_version}}"></script>
<script src="/assets/admin/custom.js?v={{$admin_ui_version}}"></script>
</body>

</html>
