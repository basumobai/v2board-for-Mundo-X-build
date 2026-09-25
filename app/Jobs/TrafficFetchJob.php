<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
    protected $reportedAtMicros;
    protected $consolidated = false;

    // Reject stale manual replays before the report ledger's retention ends.
    private const MAX_REPORT_AGE = 30 * 86400;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [1, 5];

    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data = $this->normalizeData($data);
        $this->serverId = (int)($server['id'] ?? 0);
        $this->serverRate = $this->normalizeRate($server['rate'] ?? 1);
        // Keep old payload fields for deserialization; deployment must drain
        // old workers before new producers start because old workers do not
        // write the consolidated report's statistics.
        $this->server = [
            'id' => $this->serverId,
            'rate' => $this->serverRate
        ];
        $this->protocol = (string)$protocol;
        $this->reportId = bin2hex(random_bytes(16));
        $this->reportedAt = time();
        $this->reportedAtMicros = (int)round(microtime(true) * 1000000);
        $this->consolidated = true;
    }

    /**
     * New reports charge the user and record statistics in one MySQL transaction.
     * Only legacy queued jobs use the old Redis settlement path.
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

        if ($this->consolidated) {
            if ($this->reportedAt < time() - self::MAX_REPORT_AGE) {
                throw new \RuntimeException('Traffic report is too old for automatic settlement');
            }
            $this->persistReportOnce();
        } else {
            Cache::lock('v2board_traffic_accounting', 120)->block(30, function () {
                // Old jobs can arrive after a subscription reset. Charge only
                // users whose current accounting period includes this report.
                $resetTimes = DB::table('v2_user')->whereIn('id', array_keys($this->data))
                    ->pluck('traffic_reset_at', 'id');
                $this->data = array_filter($this->data, function ($bytes, $userId) use ($resetTimes) {
                    return isset($resetTimes[$userId])
                        && (int)$resetTimes[$userId] < $this->reportedAt * 1000000;
                }, ARRAY_FILTER_USE_BOTH);
                if ($this->data) {
                    $this->incrementTrafficOnce();
                }
            });
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

        $dedupeKey = 'v2board_traffic_reports:legacy:' . gmdate('YmdH', $this->reportedAt);
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
redis.call('EXPIRE', KEYS[1], 2678400)
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

    private function persistReportOnce(): void
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

            $this->chargeUsers();
            (new StatUserJob($this->data, $server, $this->protocol, 'd', $recordAt))->persist();
            (new StatServerJob($this->data, $server, $this->protocol, 'd', $recordAt))->persist();
        }, 3);
    }

    private function chargeUsers(): void
    {
        $traffic = [];
        foreach ($this->data as $userId => $bytes) {
            $traffic[] = [
                (int)$userId,
                (int)round($bytes[0] * $this->serverRate),
                (int)round($bytes[1] * $this->serverRate)
            ];
        }
        foreach (array_chunk($traffic, 500) as $chunk) {
            $uploadCases = [];
            $downloadCases = [];
            $uploadBindings = [];
            $downloadBindings = [];
            $ids = [];
            foreach ($chunk as [$id, $upload, $download]) {
                $uploadCases[] = 'WHEN ? THEN ?';
                array_push($uploadBindings, $id, $upload);
                $downloadCases[] = 'WHEN ? THEN ?';
                array_push($downloadBindings, $id, $download);
                $ids[] = $id;
            }
            $now = time();
            DB::update(
                'UPDATE v2_user SET' .
                ' u = u + CASE id ' . implode(' ', $uploadCases) . ' ELSE 0 END,' .
                ' d = d + CASE id ' . implode(' ', $downloadCases) . ' ELSE 0 END,' .
                ' t = ?, updated_at = ?' .
                ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' .
                ' AND traffic_reset_at < ?',
                array_merge($uploadBindings, $downloadBindings, [$now, $now], $ids,
                    [$this->reportedAtMicros ?: $this->reportedAt * 1000000])
            );
        }
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
        $payload = $this->job ? json_decode($this->job->getRawBody(), true) : null;
        $this->reportedAt = isset($payload['pushedAt']) ? (int)$payload['pushedAt'] : 0;
        $jobId = $this->job ? $this->job->getJobId() : null;
        if (!$jobId || $this->reportedAt <= 0 || $this->reportedAt < time() - self::MAX_REPORT_AGE) {
            throw new \RuntimeException('Legacy traffic job needs manual reconciliation');
        }
        $this->reportId = substr(hash('sha256', 'legacy-traffic:' . $jobId), 0, 32);
    }
}
