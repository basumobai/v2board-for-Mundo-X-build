<?php

namespace App\Support;

class FrontendAssets
{
    /** Detect updates even when an archive preserves file size and mtime. */
    public static function version(array $paths, string $fallback): string
    {
        $hashes = [];
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            if (!is_file($path)) {
                $hashes[] = 'missing';
                continue;
            }
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                return $fallback;
            }
            $hashes[] = $hash;
        }

        return substr(hash('sha256', implode(':', $hashes)), 0, 20);
    }
}
