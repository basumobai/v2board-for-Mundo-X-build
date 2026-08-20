<?php

require_once __DIR__ . '/vendor/autoload.php';

use Adapterman\Adapterman;
use App\Services\RuntimeConfigService;
use Workerman\Worker;

putenv('APP_RUNNING_IN_CONSOLE=false');
define('MAX_REQUEST', 6600);
define('isWEBMAN', true);

Adapterman::init();

$ncpu = substr_count((string)@file_get_contents('/proc/cpuinfo'), "\nprocessor")+1;

$http_worker                = new Worker('http://127.0.0.1:6600');
$http_worker->count         = $ncpu * 2;
$http_worker->name          = 'AdapterMan';

$http_worker->onWorkerStart = static function () {
    //init();
    require __DIR__.'/start.php';
};

$http_worker->onMessage = static function ($connection, $request) {
    static $request_count = 0;
    $connection->send(run());
    $runtimeConfig = app(RuntimeConfigService::class);
    if ($runtimeConfig->pullWorkerReloadRequest() && defined('SIGUSR2')) {
        @posix_kill(posix_getppid(), SIGUSR2);
    }
    if (++$request_count > MAX_REQUEST) {
        Worker::stopAll();
    }
};

Worker::runAll();
