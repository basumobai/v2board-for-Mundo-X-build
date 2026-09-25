<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetTraffic extends Command
{
    private const LOCK_KEY = 'v2board_traffic_accounting';

    protected $builder;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:traffic';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量清空';

    public function __construct()
    {
        parent::__construct();
        $this->builder = User::whereNotNull('expired_at')
            ->where('expired_at', '>', time());
    }

    public function handle()
    {
        $lock = Cache::lock(self::LOCK_KEY, 3600);
        try {
            $lock->block(60);
        } catch (LockTimeoutException $e) {
            Log::error('等待流量结算锁超时，未执行流量重置');
            return 1;
        }

        try {
            // Legacy Redis counters must be settled before the reset boundary.
            // New reports use their queued receipt time and the per-user boundary.
            app(TrafficUpdate::class)->settleBeforeReset();
            $plansByMethod = Plan::query()
                ->get(['id', 'reset_traffic_method'])
                ->groupBy(function ($plan) {
                    return $plan->reset_traffic_method === null
                        ? 'default'
                        : (string)$plan->reset_traffic_method;
                });

            foreach ($plansByMethod as $method => $plans) {
                $resetMethod = $method === 'default'
                    ? (int)config('v2board.reset_traffic_method', 0)
                    : (int)$method;
                $builder = (clone $this->builder)->whereIn('plan_id', $plans->pluck('id')->all());

                switch ($resetMethod) {
                    case 0:
                        $this->resetByMonthFirstDay($builder);
                        break;
                    case 1:
                        $this->resetByExpireDay($builder);
                        break;
                    case 2:
                        break;
                    case 3:
                        $this->resetByYearFirstDay($builder);
                        break;
                    case 4:
                        $this->resetByExpireYear($builder);
                        break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('流量重置延后，待结算流量或数据库操作失败', ['exception' => $e]);
            return 1;
        } finally {
            $lock->release();
        }

        return 0;
    }

    private function resetByExpireYear($builder): void
    {
        $today = date('m-d');
        $builder->select(['id', 'expired_at'])->chunkById(500, function ($users) use ($today) {
            $ids = [];
            foreach ($users as $user) {
                if (date('m-d', $user->expired_at) === $today) {
                    $ids[] = $user->id;
                }
            }
            $this->resetUsers($ids);
        });
    }

    private function resetByYearFirstDay($builder): void
    {
        if (date('md') !== '0101') {
            return;
        }
        $this->retryTransaction(function () use ($builder) {
            $builder->where('traffic_reset_cycle', '!=', (int)date('Ymd'))
                ->update($this->resetValues());
        });
    }

    private function resetByMonthFirstDay($builder): void
    {
        if (date('d') !== '01') {
            return;
        }
        $this->retryTransaction(function () use ($builder) {
            $builder->where('traffic_reset_cycle', '!=', (int)date('Ymd'))
                ->update($this->resetValues());
        });
    }

    private function resetByExpireDay($builder): void
    {
        $lastDay = date('t');
        $today = date('d');
        $now = time();

        $builder->select(['id', 'expired_at'])->chunkById(500, function ($users) use ($lastDay, $today, $now) {
            $ids = [];
            foreach ($users as $user) {
                $expireDay = date('d', $user->expired_at);
                $isResetDay = $expireDay === $today
                    || ($today === $lastDay && $expireDay >= $lastDay);
                if ($isResetDay && $now < $user->expired_at - 2160000) {
                    $ids[] = $user->id;
                }
            }
            $this->resetUsers($ids);
        });
    }

    private function resetUsers(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $this->retryTransaction(function () use ($ids) {
            User::whereIn('id', $ids)
                ->where('traffic_reset_cycle', '!=', (int)date('Ymd'))
                ->update($this->resetValues());
        });
    }

    private function resetValues(): array
    {
        return [
            'u' => 0,
            'd' => 0,
            'traffic_reset_at' => (int)round(microtime(true) * 1000000),
            'traffic_reset_cycle' => (int)date('Ymd')
        ];
    }

    private function retryTransaction(callable $callback): void
    {
        try {
            DB::transaction($callback, 3);
        } catch (\Throwable $e) {
            Log::error('用户流量重置失败', ['exception' => $e]);
            try {
                (new TelegramService())->sendMessageWithAdmin(
                    date('Y/m/d H:i:s') . '用户流量重置失败：' . $e->getMessage()
                );
            } catch (\Throwable $notificationError) {
                Log::warning('流量重置失败通知发送失败', ['exception' => $notificationError]);
            }
            throw $e;
        }
    }
}
