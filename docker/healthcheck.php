<?php

// Deliberately avoids booting a second Laravel application for each probe.
$port = getenv(($argv[1] ?? 'web') === 'gateway' ? 'GATEWAY_PORT' : 'WEB_PORT') ?: 6600;
$socket = @fsockopen('127.0.0.1', (int)$port, $code, $message, 2);
if (!$socket) {
    exit(1);
}
stream_set_timeout($socket, 2);
fwrite($socket, "GET /healthz HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$status = fgets($socket);
fclose($socket);
exit(is_string($status) && preg_match('~^HTTP/1\.[01] 200(?: |\r|\n)~', $status) ? 0 : 1);
