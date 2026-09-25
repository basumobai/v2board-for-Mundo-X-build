<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TrafficUpdate extends Command
{
    private const UPLOAD_KEY = 'v2board_upload_traffic';
    private const DOWNLOAD_KEY = 'v2board_download_traffic';
    private const PENDING_KEY = 'v2board_traffic_batches';
    private const LOCK_KEY = 'v2board_traffic_accounting';
    private const MAX_BATCH_AGE = 30 * 86400;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量更新任务';

    /**
     * Atomically rotate active Redis counters into immutable batches, then
     * apply each batch once in MySQL. A failed DB write leaves the batch in
     * Redis for the next scheduler run instead of losing reported traffic.
     */
    public function handle()
    {
        $lock = Cache::lock(self::LOCK_KEY, 300);
        if (!$lock->get()) {
            return 0;
        }

        try {
            $this->settle(100);
        } catch (\Throwable $e) {
            Log::error('流量更新失败', [
                'exception' => $e
            ]);
            return 1;
        } finally {
            $lock->release();
        }

        return 0;
    }

    /** Called by reset:traffic while it owns the same lock. */
    public function settleBeforeReset(): void
    {
        $this->settle(1000);
        if (Redis::zcard(self::PENDING_KEY) > 0) {
            throw new \RuntimeException('Unsettled legacy traffic; reset deferred');
        }
    }

    private function settle(int $limit): void
    {
        $this->rotateActiveCounters();
        $processed = 0;
        while ($processed < $limit) {
            $batchIds = Redis::zrange(self::PENDING_KEY, 0, min(99, $limit - $processed - 1));
            if (!$batchIds) {
                return;
            }
            foreach ($batchIds as $batchId) {
                $this->processBatch((string)$batchId);
                $processed++;
            }
        }
    }

    private function rotateActiveCounters(): void
    {
        $batchId = bin2hex(random_bytes(16));
        $uploadBatchKey = $this->batchKey($batchId, 'u');
        $downloadBatchKey = $this->batchKey($batchId, 'd');
        $script = <<<'LUA'
local hasUpload = redis.call('EXISTS', KEYS[1])
local hasDownload = redis.call('EXISTS', KEYS[2])
if hasUpload == 0 and hasDownload == 0 then
    return 0
end

if hasUpload == 1 then
    redis.call('RENAME', KEYS[1], KEYS[4])
end
if hasDownload == 1 then
    redis.call('RENAME', KEYS[2], KEYS[5])
end
redis.call('ZADD', KEYS[3], ARGV[1], ARGV[2])
return 1
LUA;

        Redis::eval(
            $script,
            5,
            self::UPLOAD_KEY,
            self::DOWNLOAD_KEY,
            self::PENDING_KEY,
            $uploadBatchKey,
            $downloadBatchKey,
            time(),
            $batchId
        );
    }

    private function processBatch(string $batchId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $batchId)) {
            throw new \RuntimeException('Invalid traffic batch identifier');
        }

        $createdAt = (int)Redis::zscore(self::PENDING_KEY, $batchId);
        if ($createdAt <= 0 || $createdAt < time() - self::MAX_BATCH_AGE) {
            throw new \RuntimeException('Traffic batch is too old for automatic settlement');
        }

        $uploadKey = $this->batchKey($batchId, 'u');
        $downloadKey = $this->batchKey($batchId, 'd');
        $traffic = $this->normalizeTraffic(
            Redis::hgetall($uploadKey),
            Redis::hgetall($downloadKey)
        );

        if (empty($traffic) && !DB::table('v2_traffic_batch')->where('batch_id', $batchId)->exists()) {
            throw new \RuntimeException('Pending traffic batch lost its Redis counters');
        }

        DB::transaction(function () use ($batchId, $traffic) {
            $inserted = DB::table('v2_traffic_batch')->insertOrIgnore([
                'batch_id' => $batchId,
                'created_at' => time()
            ]);

            if ($inserted !== 1 || empty($traffic)) {
                return;
            }

            foreach (array_chunk($traffic, 500) as $chunk) {
                $this->applyChunk($chunk);
            }
        }, 3);

        // If the worker stops after the DB commit, the ledger above makes
        // this cleanup safe to repeat on the next run.
        Redis::pipeline(function ($pipe) use ($uploadKey, $downloadKey, $batchId) {
            $pipe->del($uploadKey, $downloadKey);
            $pipe->zrem(self::PENDING_KEY, $batchId);
        });
    }

    private function normalizeTraffic(array $uploads, array $downloads): array
    {
        $traffic = [];
        foreach ([0 => $uploads, 1 => $downloads] as $direction => $values) {
            foreach ($values as $userId => $bytes) {
                if (!is_numeric($userId) || (int)$userId <= 0 || !is_numeric($bytes)) {
                    continue;
                }
                $bytes = (int)$bytes;
                if ($bytes < 0) {
                    continue;
                }
                $userId = (int)$userId;
                if (!isset($traffic[$userId])) {
                    $traffic[$userId] = [$userId, 0, 0];
                }
                $traffic[$userId][$direction + 1] = $bytes;
            }
        }

        return array_values($traffic);
    }

    private function applyChunk(array $traffic): void
    {
        $uploadCases = [];
        $downloadCases = [];
        $uploadBindings = [];
        $downloadBindings = [];
        $ids = [];

        foreach ($traffic as $item) {
            [$userId, $upload, $download] = $item;
            $uploadCases[] = 'WHEN ? THEN ?';
            $uploadBindings[] = $userId;
            $uploadBindings[] = $upload;
            $downloadCases[] = 'WHEN ? THEN ?';
            $downloadBindings[] = $userId;
            $downloadBindings[] = $download;
            $ids[] = $userId;
        }

        $time = time();
        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE v2_user SET' .
            ' u = u + CASE id ' . implode(' ', $uploadCases) . ' ELSE 0 END,' .
            ' d = d + CASE id ' . implode(' ', $downloadCases) . ' ELSE 0 END,' .
            ' t = ?, updated_at = ?' .
            ' WHERE id IN (' . $idPlaceholders . ')';

        DB::update(
            $sql,
            array_merge($uploadBindings, $downloadBindings, [$time, $time], $ids)
        );
    }

    private function batchKey(string $batchId, string $direction): string
    {
        return 'v2board_traffic_batch:' . $batchId . ':' . $direction;
    }
}
