<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckRenewal extends Command
{
    protected $signature = 'check:renewal';
    protected $description = '自动续费';

    public function handle()
    {
        $now = time();
        User::where('auto_renewal', 1)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<', $now + 2 * 86400)
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $candidate) {
                    try {
                        $this->renewUser((int)$candidate->id);
                    } catch (\Throwable $e) {
                        // Keep auto renewal enabled for transient failures so a
                        // later run can retry instead of silently disabling it.
                        Log::error('用户自动续费失败', [
                            'user_id' => $candidate->id,
                            'exception' => $e
                        ]);
                    }
                }
            });
    }

    private function renewUser(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $user = User::whereKey($userId)->lockForUpdate()->first();
            $now = time();
            if (!$user || !$user->auto_renewal || !$user->plan_id
                || !$user->expired_at || $user->expired_at <= $now
                || $user->expired_at >= $now + 2 * 86400) {
                return;
            }

            $latestOrder = Order::where('user_id', $user->id)
                ->whereNotIn('period', ['reset_price', 'onetime_price', 'deposit'])
                ->where('status', 3)
                ->orderByDesc('id')
                ->first(['period']);
            if (!$latestOrder || !isset(OrderService::STR_TO_TIME[$latestOrder->period])) {
                $this->disableAutoRenewal($user, 'No valid renewable order');
                return;
            }

            $plan = Plan::find($user->plan_id);
            $period = $latestOrder->period;
            $price = $plan ? $plan->getAttribute($period) : null;
            if (!$plan || !$plan->renew || !is_numeric($price) || $price < 0) {
                $this->disableAutoRenewal($user, 'Plan cannot be renewed');
                return;
            }
            $price = (int)$price;
            if ($user->balance < $price) {
                $this->disableAutoRenewal($user, 'Insufficient balance');
                return;
            }

            $nextExpiredAt = $this->getTime($period, (int)$user->expired_at);
            if (!$nextExpiredAt) {
                $this->disableAutoRenewal($user, 'Unsupported renewal period');
                return;
            }

            $order = new Order();
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $period;
            $order->trade_no = Helper::generateOrderNo();
            $order->balance_amount = $price;
            $order->total_amount = 0;
            $order->type = 2;
            $order->status = 3;

            $user->balance -= $price;
            $user->expired_at = $nextExpiredAt;
            $user->saveOrFail();
            $order->saveOrFail();
        }, 3);
    }

    private function disableAutoRenewal(User $user, string $reason): void
    {
        $user->auto_renewal = 0;
        $user->saveOrFail();
        Log::info('已关闭用户自动续费', [
            'user_id' => $user->id,
            'reason' => $reason
        ]);
    }

    private function getTime(string $period, int $timestamp)
    {
        if (!isset(OrderService::STR_TO_TIME[$period])) {
            return null;
        }
        if ($timestamp < time()) {
            $timestamp = time();
        }
        $months = OrderService::STR_TO_TIME[$period];
        return strtotime('+' . $months . ' month', $timestamp);
    }
}
