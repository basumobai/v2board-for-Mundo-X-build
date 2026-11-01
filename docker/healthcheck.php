<?php

// Deliberately avoids booting a second Laravel application for each probe.
$gateway = ($argv[1] ?? 'web') === 'gateway';
$port = $gateway ? (getenv('GATEWAY_HEALTH_PORT') ?: getenv('GATEWAY_PORT') ?: 7001) : (getenv('WEB_PORT') ?: 6600);
$host = $gateway ? (getenv('GATEWAY_HOST') ?: '127.0.0.1') : '127.0.0.1';
$socket = @fsockopen($host, (int)$port, $code, $message, 2);
if (!$socket) {
    exit(1);
}
stream_set_timeout($socket, 2);
fwrite($socket, "GET /healthz HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$status = fgets($socket);
fclose($socket);
exit(is_string($status) && preg_match('~^HTTP/1\.[01] 200(?: |\r|\n)~', $status) ? 0 : 1);
