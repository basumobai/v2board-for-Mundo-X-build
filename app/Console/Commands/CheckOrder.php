<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use Illuminate\Console\Command;
use App\Models\Order;

class CheckOrder extends Command
{
    protected $signature = 'check:order';
    protected $description = '订单检查任务';

    public function handle()
    {
        $now = time();

        // Unpaid orders do not need a queue attempt until they have expired.
        // Select only the trade number and stream in bounded chunks.
        Order::where(function ($query) use ($now) {
            $query->where('status', 1)
                ->orWhere(function ($query) use ($now) {
                    $query->where('status', 0)
                        ->where('created_at', '<=', $now - 2 * 3600);
                });
        })
            ->select(['id', 'trade_no'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    OrderHandleJob::dispatch($order->trade_no);
                }
            });
    }
}
