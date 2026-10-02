<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function failSmoke(string $message): never
{
    fwrite(STDERR, "Admin asset smoke check failed: {$message}\n");
    exit(1);
}

/**
 * @return array{status:int,headers:array<string,string>,body:string,bytes:int}
 */
function requestUrl(string $url, array $requestHeaders = [], bool $captureBody = false): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        failSmoke("cannot initialize cURL for {$url}");
    }

    $responseHeaders = [];
    $body = '';
    $bytes = 0;
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                return $length;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $responseHeaders[strtolower($name)] = $value;
            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$bytes, $captureBody): int {
            $bytes += strlen($chunk);
            if ($captureBody) {
                $body .= $chunk;
            }
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($ok === false) {
        failSmoke("request failed for {$url}: {$error}");
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $body,
        'bytes' => $bytes,
    ];
}

function assetFromHtml(string $html, string $filename): string
{
    $quoted = preg_quote($filename, '~');
    if (!preg_match('~(?:href|src)="(?<url>/assets/admin/' . $quoted . '\?v=[^"]+)"~', $html, $match)) {
        failSmoke("admin HTML does not reference {$filename} with a version query");
    }
    return $match['url'];
}

function assetVersion(string $url): string
{
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query)) {
        failSmoke("asset URL has no query string: {$url}");
    }
    parse_str($query, $params);
    $version = $params['v'] ?? null;
    if (!is_string($version) || $version === '') {
        failSmoke("asset URL has no version value: {$url}");
    }
    return $version;
}

$gatewayPort = (int) (getenv('GATEWAY_PORT') ?: 7001);
if ($gatewayPort < 1 || $gatewayPort > 65535) {
    failSmoke('invalid GATEWAY_PORT');
}

$securePath = (string) (config('v2board.secure_path')
    ?: config('v2board.frontend_admin_path')
    ?: hash('crc32b', (string) config('app.key')));
$securePath = trim($securePath, '/');
if ($securePath === '') {
    failSmoke('admin secure path is empty');
}

$baseUrl = "http://127.0.0.1:{$gatewayPort}";
$admin = requestUrl("{$baseUrl}/{$securePath}", [], true);
if ($admin['status'] !== 200 || $admin['body'] === '') {
    failSmoke("admin shell returned HTTP {$admin['status']}");
}

$customCss = assetFromHtml($admin['body'], 'custom.css');
$customJs = assetFromHtml($admin['body'], 'custom.js');
$umiJs = assetFromHtml($admin['body'], 'umi.js');

$overrideVersion = assetVersion($customCss);
if (assetVersion($customJs) !== $overrideVersion) {
    failSmoke('custom.css and custom.js must share one override version');
}
if (assetVersion($umiJs) === $overrideVersion) {
    failSmoke('umi.js must use a bundle version independent from custom overrides');
}

foreach ([
    $customCss => 'text/css',
    $customJs => 'javascript',
    $umiJs => 'javascript',
] as $asset => $expectedType) {
    $response = requestUrl($baseUrl . $asset);
    if ($response['status'] !== 200 || $response['bytes'] === 0) {
        failSmoke("{$asset} returned HTTP {$response['status']} or an empty body");
    }
    $contentType = strtolower($response['headers']['content-type'] ?? '');
    if (!str_contains($contentType, $expectedType)) {
        failSmoke("{$asset} returned unexpected Content-Type {$contentType}");
    }
}

$compressed = requestUrl($baseUrl . $umiJs, ['Accept-Encoding: gzip']);
if ($compressed['status'] !== 200 || strtolower($compressed['headers']['content-encoding'] ?? '') !== 'gzip') {
    failSmoke('umi.js is not gzip-compressed through the gateway');
}

printf(
    "Admin shell assets passed: override=%s bundle=%s gzip=yes\n",
    $overrideVersion,
    assetVersion($umiJs)
);
