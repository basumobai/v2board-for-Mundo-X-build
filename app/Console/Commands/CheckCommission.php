<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckCommission extends Command
{
    protected $signature = 'check:commission';
    protected $description = '返佣服务';

    public function handle()
    {
        $this->autoCheck();
        $this->autoPayCommission();
    }

    public function autoCheck()
    {
        if ((int)config('v2board.commission_auto_check_enable', 1)) {
            Order::where('commission_status', 0)
                ->whereNotNull('invite_user_id')
                ->whereIn('status', [3, 4])
                ->where('updated_at', '<=', strtotime('-3 day'))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

    public function autoPayCommission()
    {
        Order::where('commission_status', 1)
            ->whereNotNull('invite_user_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $candidate) {
                    try {
                        DB::transaction(function () use ($candidate) {
                            // The order row is the claim: only one scheduler can
                            // distribute this order's commission at a time.
                            $order = Order::whereKey($candidate->id)
                                ->lockForUpdate()
                                ->first();
                            if (!$order || (int)$order->commission_status !== 1
                                || !$order->invite_user_id) {
                                return;
                            }

                            $this->payHandle($order->invite_user_id, $order);
                            $order->commission_status = 2;
                            $order->saveOrFail();
                        }, 3);
                    } catch (\Throwable $e) {
                        Log::error('返佣失败', [
                            'order_id' => $candidate->id,
                            'exception' => $e
                        ]);
                    }
                }
            });
    }

    public function payHandle($inviteUserId, Order $order)
    {
        $commissionShareLevels = (int)config('v2board.commission_distribution_enable', 0)
            ? [
                0 => (int)config('v2board.commission_distribution_l1'),
                1 => (int)config('v2board.commission_distribution_l2'),
                2 => (int)config('v2board.commission_distribution_l3')
            ]
            : [0 => 100];
        $visited = [];
        $remainingCommission = max(
            0,
            (int)$order->commission_balance
                - (int)CommissionLog::where('trade_no', $order->trade_no)->sum('get_amount')
        );

        for ($level = 0; $level < 3 && $inviteUserId; $level++) {
            $inviteUserId = (int)$inviteUserId;
            if ($inviteUserId <= 0 || isset($visited[$inviteUserId])) {
                break;
            }
            $visited[$inviteUserId] = true;

            $inviter = User::whereKey($inviteUserId)->lockForUpdate()->first();
            if (!$inviter) {
                break;
            }
            $nextInviteUserId = $inviter->invite_user_id;
            $share = max(0, $commissionShareLevels[$level] ?? 0);
            $commissionBalance = min(
                $remainingCommission,
                intdiv((int)$order->commission_balance * $share, 100)
            );

            if ($commissionBalance > 0) {
                $alreadyPaid = CommissionLog::where('trade_no', $order->trade_no)
                    ->where('invite_user_id', $inviteUserId)
                    ->exists();

                if (!$alreadyPaid) {
                    if ((int)config('v2board.withdraw_close_enable', 0)) {
                        $inviter->balance += $commissionBalance;
                    } else {
                        $inviter->commission_balance += $commissionBalance;
                    }
                    $inviter->saveOrFail();

                    CommissionLog::create([
                        'invite_user_id' => $inviteUserId,
                        'user_id' => $order->user_id,
                        'trade_no' => $order->trade_no,
                        'order_amount' => $order->total_amount,
                        'get_amount' => $commissionBalance
                    ]);
                    $remainingCommission -= $commissionBalance;
                }
            }

            $inviteUserId = $nextInviteUserId;
        }

        $order->actual_commission_balance = (int)CommissionLog::where('trade_no', $order->trade_no)
            ->sum('get_amount');
        return true;
    }
}
