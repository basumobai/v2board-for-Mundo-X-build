<?php

namespace App\Support;

final class TrustedOrigins
{
    public static function origin($url): ?string
    {
        if (!is_string($url) || preg_match('/[\s\x00-\x1f\x7f]/', $url)) return null;
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['https', 'http'], true)) return null;
        if (!preg_match('/\A[a-z0-9.-]+\z/', $host) && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return null;
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) return null;
        return $scheme . '://' . $host . (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port);
    }

    public static function allows($origin): bool
    {
        $canonical = self::origin($origin);
        if ($canonical === null || rtrim(strtolower($origin), '/') !== $canonical) return false;
        $urls = array_merge((array) config('cors.allowed_origins', []), [
            config('app.url'), config('v2board.app_url'), config('security.frontend_url'),
        ]);
        foreach ($urls as $url) {
            if (self::origin($url) === $canonical) return true;
        }
        return false;
    }

    public static function frontendBase($origin = null): string
    {
        $urls = [config('security.frontend_url'), config('v2board.app_url'), config('app.url')];
        if (self::allows($origin)) {
            foreach ($urls as $url) {
                if (self::origin($url) === self::origin($origin)) return self::baseUrl($url);
            }
            return self::origin($origin);
        }
        foreach ($urls as $url) if (($base = self::baseUrl($url)) !== null) return $base;
        throw new \RuntimeException('A trusted application URL is required');
    }

    public static function baseUrl($url): ?string
    {
        $origin = self::origin($url);
        return $origin === null ? null : $origin . rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/');
    }
}
