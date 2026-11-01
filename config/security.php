<?php

return [
    'trusted_proxy_host' => env('TRUSTED_PROXY_HOST'),
    'frontend_url' => env('FRONTEND_URL'),
    'backend_url' => env('BACKEND_URL', env('APP_URL', 'http://localhost')),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '127.0.0.1,::1'))))),
];
