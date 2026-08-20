<?php

namespace App\Services;

use RuntimeException;

class RuntimeConfigService
{
    private static $workerReloadRequested = false;

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
        if (!is_file($path)) {
            return $fallback;
        }

        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $config = require $path;

        return is_array($config) ? $config : $fallback;
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
