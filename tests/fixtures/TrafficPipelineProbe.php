<?php

use App\Jobs\TrafficFetchJob;
use App\Support\PerformanceSchema;
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
    PerformanceSchema::ensure();
    PerformanceSchema::ensure(); // Upgrade must be safe to rerun.

    // An existing but incomplete ledger must stop the upgrade before workers start.
    DB::statement('ALTER TABLE v2_node_report DROP COLUMN server_type');
    try {
        try {
            PerformanceSchema::ensure();
            throw new RuntimeException('Incomplete report ledger passed the schema gate');
        } catch (RuntimeException $expected) {
            assertSameValue('Unexpected definition for v2_node_report.server_type',
                $expected->getMessage(), 'Incomplete report ledger was not rejected');
        }
    } finally {
        DB::statement('ALTER TABLE v2_node_report ADD COLUMN server_type char(11) NOT NULL AFTER server_id');
    }
    DB::statement('ALTER TABLE v2_node_report DROP INDEX created_at');
    DB::statement('ALTER TABLE v2_stat_user DROP INDEX server_rate_user_id_record_at');
    PerformanceSchema::ensure(); // Repair missing cleanup and aggregation indexes.
    assertSameValue(1, count(DB::select('SHOW INDEX FROM v2_node_report WHERE Key_name = ?', ['created_at'])),
        'Report cleanup index was not restored');
    assertSameValue(3, count(DB::select('SHOW INDEX FROM v2_stat_user WHERE Key_name = ?',
        ['server_rate_user_id_record_at'])), 'User statistic unique index was not restored');

    $job = new TrafficFetchJob(
        [$userId => [100, 200]],
        ['id' => $serverId, 'rate' => 1.5],
        'vmess'
    );
    $job->handle();
    $job->handle();

    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(150, (int)$user->u, 'Direct MySQL charge was duplicated');
    assertSameValue(300, (int)$user->d, 'Direct MySQL charge was duplicated');
    assertSameValue(0, (int)Redis::hlen('v2board_upload_traffic'), 'New report used legacy Redis settlement');

    $userStat = DB::table('v2_stat_user')->where('user_id', $userId)->first();
    assertSameValue(100, (int)$userStat->u, 'User upload statistics were duplicated');
    assertSameValue(200, (int)$userStat->d, 'User download statistics were duplicated');
    $serverStat = DB::table('v2_stat_server')->where('server_id', $serverId)->first();
    assertSameValue(100, (int)$serverStat->u, 'Server upload statistics were duplicated');
    assertSameValue(200, (int)$serverStat->d, 'Server download statistics were duplicated');

    // If the report ledger is unavailable, the entire charge must roll back.
    $failedJob = new TrafficFetchJob([$userId => [11, 13]], ['id' => $serverId, 'rate' => 1], 'vmess');
    DB::statement('RENAME TABLE v2_node_report TO v2_node_report_probe_hold');
    try {
        try {
            $failedJob->handle();
            throw new RuntimeException('Missing report ledger did not stop the report');
        } catch (\Illuminate\Database\QueryException $expected) {
            // Database failure is expected; no partial charge may commit.
        }
        $user = DB::table('v2_user')->where('id', $userId)->first();
        assertSameValue(150, (int)$user->u, 'Failed report partially charged upload');
    } finally {
        DB::statement('RENAME TABLE v2_node_report_probe_hold TO v2_node_report');
    }
    $failedJob->handle();
    $failedJob->handle();
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(161, (int)$user->u, 'Recovered report charged more than once');
    assertSameValue(313, (int)$user->d, 'Recovered report charged more than once');

    // A report accepted before a reset may be processed afterwards, but it
    // cannot debit the new accounting period. Statistics still include it.
    $lateJob = new TrafficFetchJob([$userId => [7, 9]], ['id' => $serverId, 'rate' => 1], 'vmess');
    DB::table('v2_user')->where('id', $userId)->update([
        'u' => 0, 'd' => 0,
        'traffic_reset_at' => (int)round(microtime(true) * 1000000) + 1000000,
        'traffic_reset_cycle' => (int)date('Ymd')
    ]);
    $lateJob->handle();
    $lateJob->handle();
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(0, (int)$user->u, 'Late report debited the new period');
    assertSameValue(0, (int)$user->d, 'Late report debited the new period');
    DB::table('v2_user')->where('id', $userId)->update(['traffic_reset_at' => 0]);

    assertSameValue(0, Artisan::call('traffic:update'), 'Empty traffic settlement failed');
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(0, (int)$user->u, 'An empty legacy settlement changed upload');
    assertSameValue(0, (int)$user->d, 'An empty legacy settlement changed download');

    Redis::hincrby('v2board_upload_traffic', $userId, 25);
    assertSameValue(0, Artisan::call('traffic:update'), 'Upload-only settlement failed');
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(25, (int)$user->u, 'Upload-only legacy traffic was lost');
    assertSameValue(0, (int)$user->d, 'Upload-only settlement changed download');

    Redis::hincrby('v2board_upload_traffic', $userId, 40);
    Redis::hincrby('v2board_download_traffic', $userId, 60);
    DB::statement('RENAME TABLE v2_traffic_batch TO v2_traffic_batch_probe_hold');
    try {
        assertSameValue(1, Artisan::call('traffic:update'), 'A failed DB settlement was not reported');
        assertSameValue(1, (int)Redis::zcard('v2board_traffic_batches'), 'Failed traffic batch was not retained');
        $user = DB::table('v2_user')->where('id', $userId)->first();
        assertSameValue(25, (int)$user->u, 'Failed settlement partially changed upload');
        assertSameValue(0, (int)$user->d, 'Failed settlement partially changed download');
    } finally {
        DB::statement('RENAME TABLE v2_traffic_batch_probe_hold TO v2_traffic_batch');
    }

    $batchId = Redis::zrange('v2board_traffic_batches', 0, 0)[0];
    assertSameValue(0, Artisan::call('traffic:update'), 'Retained traffic batch did not recover');
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(65, (int)$user->u, 'Recovered upload is incorrect');
    assertSameValue(60, (int)$user->d, 'Recovered download is incorrect');
    assertSameValue(0, (int)Redis::zcard('v2board_traffic_batches'), 'Recovered batch was not acknowledged');

    // Simulate a stop after MySQL COMMIT but before Redis acknowledgement.
    Redis::hset('v2board_traffic_batch:' . $batchId . ':u', $userId, 40);
    Redis::zadd('v2board_traffic_batches', time(), $batchId);
    assertSameValue(0, Artisan::call('traffic:update'), 'Committed batch retry failed');
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(65, (int)$user->u, 'Committed batch was charged twice');

    // A reset must process all legacy batches, including more than one
    // scheduler's 100-batch limit, before clearing the allowance.
    for ($i = 0; $i < 105; $i++) {
        $id = bin2hex(random_bytes(16));
        Redis::hset('v2board_traffic_batch:' . $id . ':u', $userId, 1);
        Redis::zadd('v2board_traffic_batches', time(), $id);
    }
    app(\App\Console\Commands\TrafficUpdate::class)->settleBeforeReset();
    $user = DB::table('v2_user')->where('id', $userId)->first();
    assertSameValue(170, (int)$user->u, 'Reset barrier missed old batches');
    assertSameValue(0, (int)Redis::zcard('v2board_traffic_batches'), 'Reset barrier left old batches');
} finally {
    if (DB::getSchemaBuilder()->hasTable('v2_traffic_batch_probe_hold')) {
        DB::statement('RENAME TABLE v2_traffic_batch_probe_hold TO v2_traffic_batch');
    }
    if (DB::getSchemaBuilder()->hasTable('v2_node_report_probe_hold')) {
        DB::statement('RENAME TABLE v2_node_report_probe_hold TO v2_node_report');
    }
    DB::table('v2_stat_user')->where('user_id', $userId)->delete();
    DB::table('v2_stat_server')->where('server_id', $serverId)->delete();
    DB::table('v2_node_report')->where('server_id', $serverId)->delete();
    DB::table('v2_user')->where('id', $userId)->delete();
    Redis::hdel('v2board_upload_traffic', $userId);
    Redis::hdel('v2board_download_traffic', $userId);
}

fwrite(STDOUT, "Traffic pipeline idempotency and recovery checks passed.\n");
