<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data = [];
    protected $server = [];
    protected $serverId = 0;
    protected $serverRate = 1.0;
    protected $protocol = '';
    protected $reportId;
    protected $reportedAt;
    protected $consolidated = false;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [1, 5];

    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data = $this->normalizeData($data);
        $this->serverId = (int)($server['id'] ?? 0);
        $this->serverRate = $this->normalizeRate($server['rate'] ?? 1);
        // Keep only the fields understood by workers from the previous
        // release. This preserves rolling-deploy compatibility without
        // serializing the full server model into every queue payload.
        $this->server = [
            'id' => $this->serverId,
            'rate' => $this->serverRate
        ];
        $this->protocol = (string)$protocol;
        $this->reportId = bin2hex(random_bytes(16));
        $this->reportedAt = time();
        $this->consolidated = true;
    }

    /**
     * Persist one node report exactly once per destination.
     *
     * Redis and MySQL cannot share a transaction, so each side has its own
     * idempotency marker. A retry can therefore finish an interrupted report
     * without incrementing traffic or statistics twice.
     */
    public function handle()
    {
        $this->upgradeLegacyPayload();
        if (empty($this->data)) {
            return;
        }
        if ($this->serverId <= 0 || $this->protocol === '') {
            throw new \InvalidArgumentException('Invalid server metadata in traffic report');
        }

        $this->incrementTrafficOnce();
        if ($this->consolidated) {
            $this->persistStatisticsOnce();
        }
    }

    private function incrementTrafficOnce(): void
    {
        $arguments = [$this->reportId, count($this->data)];
        foreach ($this->data as $userId => $trafficData) {
            $arguments[] = (string)$userId;
            $arguments[] = (int)round($trafficData[0] * $this->serverRate);
            $arguments[] = (int)round($trafficData[1] * $this->serverRate);
        }

        // Legacy payloads do not contain a stable report timestamp. Keep
        // their IDs in one short-lived migration set so a retry that crosses
        // an hour boundary cannot increment Redis twice.
        $dedupeKey = $this->consolidated
            ? 'v2board_traffic_reports:' . gmdate('YmdH', $this->reportedAt)
            : 'v2board_traffic_reports:legacy';
        $script = <<<'LUA'
if redis.call('SISMEMBER', KEYS[1], ARGV[1]) == 1 then
    return 0
end

local count = tonumber(ARGV[2])
local offset = 3
for i = 1, count do
    local userId = ARGV[offset]
    local upload = tonumber(ARGV[offset + 1])
    local download = tonumber(ARGV[offset + 2])
    if upload ~= 0 then
        redis.call('HINCRBY', KEYS[2], userId, upload)
    end
    if download ~= 0 then
        redis.call('HINCRBY', KEYS[3], userId, download)
    end
    offset = offset + 3
end

redis.call('SADD', KEYS[1], ARGV[1])
redis.call('EXPIRE', KEYS[1], 172800)
return 1
LUA;

        Redis::eval(
            $script,
            3,
            $dedupeKey,
            'v2board_upload_traffic',
            'v2board_download_traffic',
            ...$arguments
        );
    }

    private function persistStatisticsOnce(): void
    {
        $server = [
            'id' => $this->serverId,
            'rate' => $this->serverRate
        ];
        $recordAt = strtotime(date('Y-m-d', $this->reportedAt));

        DB::transaction(function () use ($server, $recordAt) {
            $inserted = DB::table('v2_node_report')->insertOrIgnore([
                'report_id' => $this->reportId,
                'server_id' => $this->serverId,
                'server_type' => $this->protocol,
                'created_at' => $this->reportedAt
            ]);

            if ($inserted !== 1) {
                return;
            }

            (new StatUserJob($this->data, $server, $this->protocol, 'd', $recordAt))->persist();
            (new StatServerJob($this->data, $server, $this->protocol, 'd', $recordAt))->persist();
        }, 3);
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];
        foreach ($data as $userId => $trafficData) {
            if (!is_numeric($userId) || (int)$userId <= 0 || !is_array($trafficData)
                || !isset($trafficData[0], $trafficData[1])
                || !is_numeric($trafficData[0]) || !is_numeric($trafficData[1])) {
                continue;
            }

            $upload = (float)$trafficData[0];
            $download = (float)$trafficData[1];
            if (!is_finite($upload) || !is_finite($download) || $upload < 0 || $download < 0) {
                continue;
            }

            $userId = (int)$userId;
            if (!isset($normalized[$userId])) {
                $normalized[$userId] = [0, 0];
            }
            $normalized[$userId][0] += (int)round($upload);
            $normalized[$userId][1] += (int)round($download);
        }

        return array_filter($normalized, function ($trafficData) {
            return $trafficData[0] !== 0 || $trafficData[1] !== 0;
        });
    }

    private function normalizeRate($rate): float
    {
        if (!is_numeric($rate)) {
            return 1.0;
        }
        $rate = (float)$rate;
        return is_finite($rate) && $rate >= 0 ? $rate : 1.0;
    }

    /**
     * Jobs queued before this release still contain the full $server payload
     * and have separate StatUserJob/StatServerJob entries. Process only their
     * Redis portion so a rolling deployment cannot double-write statistics.
     */
    private function upgradeLegacyPayload(): void
    {
        if ($this->consolidated) {
            return;
        }

        $this->data = $this->normalizeData((array)$this->data);
        $this->serverId = (int)($this->server['id'] ?? 0);
        $this->serverRate = $this->normalizeRate($this->server['rate'] ?? 1);
        $this->reportedAt = time();
        $jobId = $this->job ? $this->job->getJobId() : null;
        $this->reportId = $jobId
            ? substr(hash('sha256', 'legacy-traffic:' . $jobId), 0, 32)
            : bin2hex(random_bytes(16));
    }
}
