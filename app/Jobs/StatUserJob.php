<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;
    protected $recordAt;

    public $tries = 3;
    public $timeout = 60;

    public function __construct(array $data, array $server, $protocol, $recordType = 'd', $recordAt = null)
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
        $this->recordAt = $recordAt;
    }

    /**
     * Add traffic to the daily aggregate atomically.
     *
     * The old implementation first loaded all existing rows and then issued
     * one SELECT plus one UPDATE per user. Apart from being N+1, two workers
     * could read the same counters and overwrite each other's increments.
     */
    public function handle()
    {
        DB::transaction(function () {
            $this->persist();
        }, 3);
    }

    /**
     * Persist inside the caller's transaction when this job is consolidated
     * into TrafficFetchJob. Kept public for backward-compatible queued jobs.
     */
    public function persist(): void
    {
        $recordAt = $this->recordAt ?: strtotime(date('Y-m-d'));
        $now = time();
        $rows = [];

        foreach ($this->data as $userId => $trafficData) {
            if (!is_numeric($userId) || !is_array($trafficData)
                || !isset($trafficData[0], $trafficData[1])) {
                continue;
            }

            $rows[] = [
                (int)$userId,
                (float)($this->server['rate'] ?? 1),
                (int)$trafficData[0],
                (int)$trafficData[1],
                $this->recordType,
                $recordAt,
                $now,
                $now
            ];
        }

        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->upsertChunk($chunk);
        }
    }

    private function upsertChunk(array $rows): void
    {
        $columns = [
            'user_id',
            'server_rate',
            'u',
            'd',
            'record_type',
            'record_at',
            'created_at',
            'updated_at'
        ];
        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(',', array_fill(0, count($rows), $rowPlaceholder));
        $bindings = [];

        foreach ($rows as $row) {
            foreach ($row as $value) {
                $bindings[] = $value;
            }
        }

        DB::statement(
            'INSERT INTO v2_stat_user (' . implode(',', $columns) . ') VALUES ' . $placeholders .
            ' ON DUPLICATE KEY UPDATE' .
            ' u = u + VALUES(u),' .
            ' d = d + VALUES(d),' .
            ' updated_at = VALUES(updated_at)',
            $bindings
        );
    }
}
