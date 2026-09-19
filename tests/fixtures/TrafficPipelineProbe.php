<?php

use App\Jobs\TrafficFetchJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$suffix = bin2hex(random_bytes(6));
$serverId = 2000000000;
$userId = DB::table('v2_user')->insertGetId([
    'email' => 'traffic-probe-' . $suffix . '@example.test',
    'password' => 'not-used',
    'uuid' => '00000000-0000-4000-8000-' . $suffix,
    'token' => str_pad($suffix, 32, '0'),
    'transfer_enable' => 1073741824,
    'created_at' => time(),
    'updated_at' => time()
]);

try {
    Redis::del('v2board_upload_traffic', 'v2board_download_traffic', 'v2board_traffic_batches');

    $job = new TrafficFetchJob(
        [$userId => [100, 200]],
        ['id' => $serverId, 'rate' => 1.5],
        'vmess'
    );
    $job->handle();
    $job->handle();

    assertSameValue(150, (int)Redis::hget('v2board_upload_traffic', $userId), 'Redis upload idempotency failed');
    assertSameValue(300, (int)Redis::hget('v2board_download_traffic', $userId), 'Redis download idempotency failed');

    $userStat = DB::table('v2_stat_user')->where('user_id', $userId)->first();
    assertSameValue(100, (int)$userStat->u, 'User upload statistics were duplicated');
    assertSameValue(200, (int)$userStat->d, 'User download statistics were duplicated');
    $serverStat = DB::table('v2_stat_server')->where('server_id', $serverId)->first();
    assertSameValue(100, (int)$serverStat->u, 'Server upload statistics were duplicated');
    assertSameValue(200, (int)$serverStat->d, 'Server download statistics were duplicated');

    assertSameValue(0, Artisan::call('traffic:update'), 'Initial traffic settlement failed');
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(150, (int)$user->u, 'Settled upload is incorrect');
    assertSameValue(300, (int)$user->d, 'Settled download is incorrect');

    assertSameValue(0, Artisan::call('traffic:update'), 'Empty traffic settlement failed');
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(150, (int)$user->u, 'An empty retry duplicated upload');
    assertSameValue(300, (int)$user->d, 'An empty retry duplicated download');

    Redis::hincrby('v2board_upload_traffic', $userId, 25);
    assertSameValue(0, Artisan::call('traffic:update'), 'Upload-only settlement failed');
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(175, (int)$user->u, 'Upload-only traffic was lost');
    assertSameValue(300, (int)$user->d, 'Upload-only settlement changed download');

    Redis::hincrby('v2board_upload_traffic', $userId, 40);
    Redis::hincrby('v2board_download_traffic', $userId, 60);
    DB::statement('RENAME TABLE v2_traffic_batch TO v2_traffic_batch_probe_hold');
    try {
        assertSameValue(1, Artisan::call('traffic:update'), 'A failed DB settlement was not reported');
        assertSameValue(1, (int)Redis::zcard('v2board_traffic_batches'), 'Failed traffic batch was not retained');
        $user = DB::table('v2_user')->where('id', $userId)->first();
        assertSameValue(175, (int)$user->u, 'Failed settlement partially changed upload');
        assertSameValue(300, (int)$user->d, 'Failed settlement partially changed download');
    } finally {
        DB::statement('RENAME TABLE v2_traffic_batch_probe_hold TO v2_traffic_batch');
    }

    assertSameValue(0, Artisan::call('traffic:update'), 'Retained traffic batch did not recover');
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(215, (int)$user->u, 'Recovered upload is incorrect');
    assertSameValue(360, (int)$user->d, 'Recovered download is incorrect');
    assertSameValue(0, (int)Redis::zcard('v2board_traffic_batches'), 'Recovered batch was not acknowledged');
} finally {
    if (DB::getSchemaBuilder()->hasTable('v2_traffic_batch_probe_hold')) {
        DB::statement('RENAME TABLE v2_traffic_batch_probe_hold TO v2_traffic_batch');
    }
    DB::table('v2_stat_user')->where('user_id', $userId)->delete();
    DB::table('v2_stat_server')->where('server_id', $serverId)->delete();
    DB::table('v2_node_report')->where('server_id', $serverId)->delete();
    DB::table('v2_user')->where('id', $userId)->delete();
    Redis::hdel('v2board_upload_traffic', $userId);
    Redis::hdel('v2board_download_traffic', $userId);
}

fwrite(STDOUT, "Traffic pipeline idempotency and recovery checks passed.\n");
