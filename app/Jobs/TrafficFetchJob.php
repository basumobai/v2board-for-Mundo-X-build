<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 10;

    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Aggregate a node report with one Redis pipeline instead of one
     * network round trip per user.
     */
    public function handle()
    {
        $rate = (float)($this->server['rate'] ?? 1);

        Redis::pipeline(function ($pipe) use ($rate) {
            foreach ($this->data as $userId => $trafficData) {
                if (!is_numeric($userId) || !is_array($trafficData)
                    || !isset($trafficData[0], $trafficData[1])) {
                    continue;
                }

                $upload = (int)round((float)$trafficData[0] * $rate);
                $download = (int)round((float)$trafficData[1] * $rate);

                if ($upload !== 0) {
                    $pipe->hincrby('v2board_upload_traffic', (string)$userId, $upload);
                }
                if ($download !== 0) {
                    $pipe->hincrby('v2board_download_traffic', (string)$userId, $download);
                }
            }
        });
    }
}
