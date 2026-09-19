<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class StatServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;

    public $tries = 3;
    public $timeout = 60;

    public function __construct(array $data, array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    /**
     * Add one node report to its daily aggregate atomically.
     */
    public function handle()
    {
        $recordAt = strtotime(date('Y-m-d'));
        $now = time();
        $u = 0;
        $d = 0;

        foreach ($this->data as $trafficData) {
            if (!is_array($trafficData) || !isset($trafficData[0], $trafficData[1])) {
                continue;
            }
            $u += (int)$trafficData[0];
            $d += (int)$trafficData[1];
        }

        DB::statement(
            'INSERT INTO v2_stat_server ' .
            '(server_id,server_type,u,d,record_type,record_at,created_at,updated_at) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?)' .
            ' ON DUPLICATE KEY UPDATE' .
            ' u = u + VALUES(u),' .
            ' d = d + VALUES(d),' .
            ' updated_at = VALUES(updated_at)',
            [
                (int)$this->server['id'],
                $this->protocol,
                $u,
                $d,
                $this->recordType,
                $recordAt,
                $now,
                $now
            ]
        );
    }
}
