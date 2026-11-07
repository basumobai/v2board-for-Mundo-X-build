<?php

require_once __DIR__ . '/vendor/autoload.php';

use Adapterman\Adapterman;
use App\Services\RuntimeConfigService;
use App\Support\DeploymentSettings;
use Workerman\Worker;

// Workerman binds before Laravel boots; support .env for non-Docker starts too.
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$settings = DeploymentSettings::fromEnvironment();

putenv('APP_RUNNING_IN_CONSOLE=false');
define('MAX_REQUEST', $settings['WEB_MAX_REQUESTS']);
define('isWEBMAN', true);

Adapterman::init();

Worker::$pidFile = __DIR__ . '/storage/framework/workerman.pid';
Worker::$logFile = __DIR__ . '/storage/logs/workerman.log';
Worker::$stdoutFile = __DIR__ . '/storage/logs/workerman-stdout.log';
$http_worker                = new Worker('http://' . DeploymentSettings::bindAddress() . ':' . $settings['WEB_PORT']);
$http_worker->count         = $settings['WEB_WORKERS'];
$http_worker->name          = 'AdapterMan';

$http_worker->onWorkerStart = static function () {
    //init();
    require __DIR__.'/start.php';
};

$http_worker->onMessage = static function ($connection, $request) {
    static $request_count = 0;
    $connection->send(run([
        'REMOTE_ADDR' => $connection->getRemoteIp(),
        'REMOTE_PORT' => $connection->getRemotePort(),
        'SERVER_ADDR' => $connection->getLocalIp(),
    ]));
    $runtimeConfig = app(RuntimeConfigService::class);
    if ($runtimeConfig->pullWorkerReloadRequest() && defined('SIGUSR2')) {
        @posix_kill(posix_getppid(), SIGUSR2);
    }
    if (++$request_count >= MAX_REQUEST) {
        Worker::stopAll();
    }
};

Worker::runAll();
