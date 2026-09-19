<?php
namespace App\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatisticalService {
    protected $userStats;
    protected $startAt;
    protected $endAt;
    protected $serverStats;

    public function setStartAt($timestamp) {
        $this->startAt = $timestamp;
    }

    public function setEndAt($timestamp) {
        $this->endAt = $timestamp;
    }

    public function setServerStats() {
        $this->serverStats = Cache::get("stat_server_{$this->startAt}");
        $this->serverStats = json_decode($this->serverStats, true) ?? [];
        if (!is_array($this->serverStats)) {
            $this->serverStats = [];
        }
    }

    public function setUserStats() {
        $this->userStats = Cache::get("stat_user_{$this->startAt}");
        $this->userStats = json_decode($this->userStats, true) ?? [];
        if (!is_array($this->userStats)) {
            $this->userStats = [];
        }
    }

    public function generateStatData(): array
    {
        $startAt = $this->startAt;
        $endAt = $this->endAt;
        if (!$startAt || !$endAt) {
            $startAt = strtotime(date('Y-m-d'));
            $endAt = strtotime('+1 day', $startAt);
        }
        $createdOrders = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->selectRaw('COUNT(*) AS aggregate_count, COALESCE(SUM(total_amount), 0) AS aggregate_total')
            ->first();
        $paidOrders = Order::where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->selectRaw('COUNT(*) AS aggregate_count, COALESCE(SUM(total_amount), 0) AS aggregate_total')
            ->first();
        $commissions = CommissionLog::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->selectRaw('COUNT(*) AS aggregate_count, COALESCE(SUM(get_amount), 0) AS aggregate_total')
            ->first();
        $users = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->selectRaw('COUNT(*) AS aggregate_count, SUM(invite_user_id IS NOT NULL) AS invite_count')
            ->first();
        $transferUsed = StatServer::where('record_type', 'd')
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->selectRaw('COALESCE(SUM(u), 0) + COALESCE(SUM(d), 0) AS total')
            ->value('total');

        return [
            'order_count' => (int)$createdOrders->aggregate_count,
            'order_total' => (int)$createdOrders->aggregate_total,
            'paid_count' => (int)$paidOrders->aggregate_count,
            'paid_total' => (int)$paidOrders->aggregate_total,
            'commission_count' => (int)$commissions->aggregate_count,
            'commission_total' => (int)$commissions->aggregate_total,
            'register_count' => (int)$users->aggregate_count,
            'invite_count' => (int)$users->invite_count,
            'transfer_used_total' => $transferUsed ?? 0
        ];
    }

    public function statServer($serverId, $serverType, $u, $d)
    {
        $this->serverStats[$serverType] = $this->serverStats[$serverType] ?? [];
        if (isset($this->serverStats[$serverType][$serverId])) {
            $this->serverStats[$serverType][$serverId][0] += $u;
            $this->serverStats[$serverType][$serverId][1] += $d;
        } else {
            $this->serverStats[$serverType][$serverId] = [$u, $d];
        }
        Cache::put("stat_server_{$this->startAt}", json_encode($this->serverStats), 6000);
    }

    public function statUser($rate, $userId, $u, $d)
    {
        $this->userStats[$rate] = $this->userStats[$rate] ?? [];
        if (isset($this->userStats[$rate][$userId])) {
            $this->userStats[$rate][$userId][0] += $u;
            $this->userStats[$rate][$userId][1] += $d;
        } else {
            $this->userStats[$rate][$userId] = [$u, $d];
        }
        Cache::put("stat_user_{$this->startAt}", json_encode($this->userStats), 6000);
    }

    public function getStatUserByUserID($userId): array
    {
        $stats = [];
        foreach (array_keys($this->userStats) as $rate) {
            if (!isset($this->userStats[$rate][$userId])) continue;
            $stats[] = [
                'record_at' => $this->startAt,
                'server_rate' => $rate,
                'u' => $this->userStats[$rate][$userId][0],
                'd' => $this->userStats[$rate][$userId][1],
                'user_id' => $userId
            ];
        }
        return $stats;
    }

    public function getStatUser()
    {
        $stats = [];
        foreach ($this->userStats as $k => $v) {
            foreach (array_keys($v) as $userId) {
                if (isset($v[$userId])) {
                    $stats[] = [
                        'server_rate' => $k,
                        'u' => $v[$userId][0],
                        'd' => $v[$userId][1],
                        'user_id' => $userId
                    ];
                }
            }
        }
        return $stats;
    }


    public function getStatServer()
    {
        $stats = [];
        foreach ($this->serverStats as $serverType => $v) {
            foreach (array_keys($v) as $serverId) {
                if (isset($v[$serverId])) {
                    $stats[] = [
                        'server_id' => $serverId,
                        'server_type' => $serverType,
                        'u' => $v[$serverId][0],
                        'd' => $v[$serverId][1],
                    ];
                }
            }
        }
        return $stats;
    }

    public function clearStatUser()
    {
        Cache::forget("stat_user_{$this->startAt}");
    }

    public function clearStatServer()
    {
        Cache::forget("stat_server_{$this->startAt}");
    }

    public function getStatRecord($type)
    {
        switch ($type) {
            case "paid_total": {
                return Stat::select([
                    '*',
                    DB::raw('paid_total / 100 as paid_total')
                ])
                    ->where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            case "commission_total": {
                return Stat::select([
                    '*',
                    DB::raw('commission_total / 100 as commission_total')
                ])
                    ->where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            case "register_count": {
                return Stat::where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
        }
    }

    public function getRanking($type, $limit = 20)
    {
        switch ($type) {
            case 'server_traffic_rank': {
                return $this->buildServerTrafficRank($limit);
            }
            case 'user_consumption_rank': {
                return $this->buildUserConsumptionRank($limit);
            }
            case 'invite_rank': {
                return $this->buildInviteRank($limit);
            }
        }
    }

    private function buildInviteRank($limit)
    {
        $stats = User::select([
            'invite_user_id',
            DB::raw('count(*) as count')
        ])
            ->where('created_at', '>=', $this->startAt)
            ->where('created_at', '<', $this->endAt)
            ->whereNotNull('invite_user_id')
            ->groupBy('invite_user_id')
            ->orderBy('count', 'DESC')
            ->limit($limit)
            ->get();

        $users = User::whereIn('id', $stats->pluck('invite_user_id')->toArray())->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['invite_user_id']])) continue;
            $stats[$k]['email'] = $users[$v['invite_user_id']]['email'];
        }
        return $stats;
    }

    private function buildUserConsumptionRank($limit)
    {
        $stats = StatUser::select([
            'user_id',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_at', '>=', $this->startAt)
            ->where('record_at', '<', $this->endAt)
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
        $users = User::whereIn('id', $stats->pluck('user_id')->toArray())->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['user_id']])) continue;
            $stats[$k]['email'] = $users[$v['user_id']]['email'];
        }
        return $stats;
    }

    private function buildServerTrafficRank($limit)
    {
        return StatServer::select([
            'server_id',
            'server_type',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_at', '>=', $this->startAt)
            ->where('record_at', '<', $this->endAt)
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
    }
}
