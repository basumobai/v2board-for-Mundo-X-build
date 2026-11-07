<?php

return [
    'admin_requests_per_minute' => max(1, (int) env('ADMIN_REQUESTS_PER_MINUTE', 600)),
    'payment_callbacks_per_minute' => max(1, (int) env('PAYMENT_CALLBACKS_PER_MINUTE', 1200)),
    'trusted_proxy_host' => env('TRUSTED_PROXY_HOST'),
    'frontend_url' => env('FRONTEND_URL'),
    'backend_url' => env('BACKEND_URL') ?: env('APP_URL', 'http://localhost'),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '127.0.0.1,::1'))))),
];
