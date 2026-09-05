<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

class DeploymentSettings
{
    public static function fromEnvironment(): array
    {
        $settings = [];
        foreach (['WEB_PORT' => [6600, 65535], 'GATEWAY_PORT' => [7001, 65535],
            'WEB_WORKERS' => [2, 128], 'WEB_MAX_REQUESTS' => [6600, 10000000]] as $key => $range) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $settings[$key] = self::positiveInteger($key, $value === false || $value === '' ? $range[0] : $value, $range[1]);
        }
        if ($settings['WEB_PORT'] === $settings['GATEWAY_PORT']) {
            throw new InvalidArgumentException('WEB_PORT 与 GATEWAY_PORT 不能相同');
        }
        return $settings;
    }

    public static function positiveInteger(string $name, $value, int $maximum): int
    {
        if (!preg_match('/^[1-9][0-9]*$/D', (string)$value)
            || strlen((string)$value) > 10 || (int)$value > $maximum) {
            throw new InvalidArgumentException("{$name} 必须是 1–{$maximum} 的整数");
        }
        return (int)$value;
    }

    public static function assertPortsAvailable(array $ports): void
    {
        $sockets = [];
        try {
            foreach ($ports as $port) {
                $port = self::positiveInteger('端口', $port, 65535);
                $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $code, $message);
                if ($socket === false) {
                    throw new RuntimeException("本机端口 {$port} 不可用，请换一个空闲端口后重试");
                }
                $sockets[] = $socket;
            }
        } finally {
            foreach ($sockets as $socket) {
                fclose($socket);
            }
        }
    }
}
