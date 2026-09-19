<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PerformanceArchitectureTest extends TestCase
{
    public function testTrafficReportUsesOneQueuePayloadAndIdempotencyLedgers(): void
    {
        $userService = $this->read('app/Services/UserService.php');
        $trafficJob = $this->read('app/Jobs/TrafficFetchJob.php');
        $trafficUpdate = $this->read('app/Console/Commands/TrafficUpdate.php');

        $this->assertSame(1, substr_count($userService, '::dispatch($data, $server, $protocol'));
        $this->assertStringNotContainsString('StatUserJob::dispatch', $userService);
        $this->assertStringNotContainsString('StatServerJob::dispatch', $userService);
        $this->assertStringContainsString("DB::table('v2_node_report')->insertOrIgnore", $trafficJob);
        $this->assertStringContainsString("redis.call('SISMEMBER'", $trafficJob);
        $this->assertStringContainsString("'v2board_traffic_reports:legacy'", $trafficJob);
        $this->assertStringContainsString('$this->server = [', $trafficJob);
        $this->assertStringContainsString("redis.call('RENAME'", $trafficUpdate);
        $this->assertStringContainsString("DB::table('v2_traffic_batch')->insertOrIgnore", $trafficUpdate);
        $this->assertStringNotContainsString("ini_set('memory_limit', -1)", $trafficUpdate);
    }

    public function testInstallAndUpgradeSchemasContainHotPathIndexes(): void
    {
        foreach (['database/install.sql', 'database/update.sql'] as $path) {
            $sql = $this->read($path);
            $this->assertStringContainsString('v2_node_report', $sql, $path);
            $this->assertStringContainsString('v2_traffic_batch', $sql, $path);
            $this->assertStringContainsString('idx_group_access', $sql, $path);
            $this->assertStringContainsString('idx_auto_renewal', $sql, $path);
            $this->assertStringContainsString('idx_commission_queue', $sql, $path);
        }
    }

    public function testWorkersNoLongerUseArtificialUnboundedRuntimeSettings(): void
    {
        $mailJob = $this->read('app/Jobs/SendEmailJob.php');
        $scheduler = $this->read('docker/scheduler.sh');
        $resetTraffic = $this->read('app/Console/Commands/ResetTraffic.php');

        $this->assertStringNotContainsString('sleep(2)', $mailJob);
        $this->assertStringContainsString('throw $e;', $mailJob);
        $this->assertStringNotContainsString('sleep 60', $scheduler);
        $this->assertStringContainsString('60 - now % 60', $scheduler);
        $this->assertStringNotContainsString("ini_set('memory_limit', -1)", $resetTraffic);
        $this->assertStringContainsString('chunkById(500', $resetTraffic);
    }

    public function testRemainingBulkAndStatisticsPathsUseBoundedIndexedWork(): void
    {
        $resetUser = $this->read('app/Console/Commands/ResetUser.php');
        $statistics = $this->read('app/Services/StatisticalService.php');

        $this->assertStringNotContainsString("ini_set('memory_limit', -1)", $resetUser);
        $this->assertStringNotContainsString('User::all()', $resetUser);
        $this->assertStringContainsString('chunkById(500', $resetUser);
        $this->assertStringNotContainsString("StatServer::where('created_at'", $statistics);
        $this->assertStringContainsString("StatServer::where('record_type', 'd')", $statistics);
    }

    public function testLegacyNodeUserEndpointsCacheSerializedPayloads(): void
    {
        foreach ([
            'app/Http/Controllers/V1/Server/TrojanTidalabController.php',
            'app/Http/Controllers/V1/Server/ShadowsocksTidalabController.php',
            'app/Http/Controllers/V1/Server/DeepbworkController.php'
        ] as $path) {
            $controller = $this->read($path);
            $this->assertStringNotContainsString("ini_set('memory_limit', -1)", $controller, $path);
            $this->assertStringContainsString('SERVER_LEGACY_USER_PAYLOAD:', $controller, $path);
            $this->assertStringContainsString('Cache::remember', $controller, $path);
        }
    }

    private function read(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
