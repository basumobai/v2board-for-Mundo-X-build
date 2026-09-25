<?php

namespace App\Services;

use RuntimeException;

class RuntimeConfigService
{
    private const FILE_HASH_CHECK_INTERVAL = 1.0;
    private static $workerReloadRequested = false;
    private static $arrayFileCache = [];
    private static $lastFileHashCheckAt = [];

    public function refreshV2boardConfig(): array
    {
        $config = $this->loadV2boardConfig();
        config(['v2board' => $config]);

        return $config;
    }

    public function loadV2boardConfig(): array
    {
        return $this->loadArrayFile(
            base_path('config/v2board.php'),
            (array) config('v2board', [])
        );
    }

    public function saveV2boardConfig(array $config): void
    {
        $this->writeArrayFile(base_path('config/v2board.php'), $config);
        config(['v2board' => $config]);
        $this->deleteLaravelConfigCache();
    }

    public function loadThemeConfig(string $theme): array
    {
        return $this->loadArrayFile(
            base_path("config/theme/{$theme}.php"),
            (array) config("theme.{$theme}", [])
        );
    }

    public function saveThemeConfig(string $theme, array $config): void
    {
        $this->writeArrayFile(base_path("config/theme/{$theme}.php"), $config);
        config(["theme.{$theme}" => $config]);
        $this->deleteLaravelConfigCache();
    }

    public function requestWorkerReload(): void
    {
        self::$workerReloadRequested = true;
    }

    public function pullWorkerReloadRequest(): bool
    {
        $requested = self::$workerReloadRequested;
        self::$workerReloadRequested = false;

        return $requested;
    }

    private function loadArrayFile(string $path, array $fallback): array
    {
        clearstatcache(true, $path);
        $now = microtime(true);
        $cached = self::$arrayFileCache[$path] ?? null;
        $lastCheckAt = self::$lastFileHashCheckAt[$path] ?? 0.0;

        // Configuration is still refreshed quickly after an edit, but a
        // long-lived worker no longer reads and hashes the file on every
        // request. The one-second bound is deliberately short because admin
        // settings are expected to become visible without a worker restart.
        // The production AdapterMan worker already has an explicit reload
        // path; throttle only that hot request path. CLI and test callers keep
        // the original immediate same-size edit detection semantics.
        $throttle = defined('isWEBMAN') && isWEBMAN;
        if ($throttle && $cached !== null && ($now - $lastCheckAt) < self::FILE_HASH_CHECK_INTERVAL) {
            return $cached['config'];
        }

        self::$lastFileHashCheckAt[$path] = $now;
        if (!is_file($path)) {
            unset(self::$arrayFileCache[$path]);
            return $fallback;
        }

        // Content hashes detect atomic replacements AND same-second, same-size
        // manual edits. Unchanged requests no longer invalidate shared OPcache
        // or repeatedly evaluate the PHP configuration file.
        $hash = hash_file('sha256', $path);
        $cached = self::$arrayFileCache[$path] ?? null;
        if ($hash !== false && $cached !== null && $cached['hash'] === $hash) {
            return $cached['config'];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $config = require $path;
        if (!is_array($config)) {
            unset(self::$arrayFileCache[$path]);
            unset(self::$lastFileHashCheckAt[$path]);
            return $fallback;
        }
        if ($hash !== false) {
            self::$arrayFileCache[$path] = ['hash' => $hash, 'config' => $config];
        }
        return $config;
    }

    private function writeArrayFile(string $path, array $config): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create configuration directory: {$directory}");
        }

        $temporaryPath = tempnam($directory, '.v2board-config-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Unable to create a temporary configuration file in: {$directory}");
        }

        $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                throw new RuntimeException("Unable to write configuration file: {$path}");
            }

            $existingPermissions = is_file($path) ? @fileperms($path) : false;
            $permissions = $existingPermissions === false ? 0664 : ($existingPermissions & 0777);
            @chmod($temporaryPath, $permissions);

            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException("Unable to replace configuration file: {$path}");
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        clearstatcache(true, $path);
        unset(self::$arrayFileCache[$path]);
        unset(self::$lastFileHashCheckAt[$path]);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private function deleteLaravelConfigCache(): void
    {
        $cachedConfigPath = app()->getCachedConfigPath();
        if (is_file($cachedConfigPath)) {
            @unlink($cachedConfigPath);
        }
    }
}
