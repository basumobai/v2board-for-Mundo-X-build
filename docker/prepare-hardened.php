<?php

// Maintenance container only. Never traverse symlinks or accept arbitrary paths.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    throw new RuntimeException('Run the permissions maintenance container as root');
}
$ids = [];
foreach (['APP_UID', 'APP_GID'] as $key) {
    $value = getenv($key) ?: '1000';
    if (!preg_match('/\A[1-9][0-9]{0,8}\z/', $value)) {
        throw new InvalidArgumentException($key . ' must be a positive non-root ID');
    }
    $ids[] = (int) $value;
}
[$uid, $gid] = $ids;
$root = dirname(__DIR__);
function preparePath(string $path, int $uid, int $gid): void
{
    if (is_link($path)) throw new RuntimeException('Writable paths must not be symlinks');
    $mode = is_dir($path) ? 02770 : (0660 | (fileperms($path) & 0111));
    if (!chown($path, $uid) || !chgrp($path, $gid) || !chmod($path, $mode)) {
        throw new RuntimeException('Cannot prepare writable path: ' . $path);
    }
}
foreach (['storage', 'bootstrap/cache', 'config', 'public/theme', 'resources/rules'] as $relative) {
    $path = $root . '/' . $relative;
    if (!is_dir($path) && !mkdir($path, 0770, true)) throw new RuntimeException('Cannot create ' . $relative);
    preparePath($path, $uid, $gid);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        if ($item->isLink()) continue;
        preparePath($item->getPathname(), $uid, $gid);
    }
}
foreach (['.env', 'config/v2board.php'] as $relative) {
    $path = $root . '/' . $relative;
    if (is_file($path)) {
        preparePath($path, $uid, $gid);
        chmod($path, 0640);
    }
}
echo "Writable directories prepared for {$uid}:{$gid}\n";
