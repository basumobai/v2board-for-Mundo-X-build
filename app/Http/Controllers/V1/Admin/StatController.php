<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerVmess;
use App\Models\ServerVless;
use App\Models\ServerAnytls;
use App\Models\ServerMx;
use App\Models\ServerV2node;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getOverride(Request $request)
    {
        return Cache::remember('ADMIN_STAT_OVERRIDE', 30, function () {
            return ['data' => [
                'online_user' => User::where('t','>=', time() - 600)
                    ->count(),
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'day_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->where('reply_status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
            ]];
        });
    }

    public function getOrder(Request $request)
    {
        $statistics = Stat::where('record_type', 'd')
            ->limit(31)
            ->orderBy('record_at', 'DESC')
            ->get()
            ->toArray();
        $result = [];
        foreach ($statistics as $statistic) {
            $date = date('m-d', $statistic['record_at']);
            $result[] = [
                'type' => '注册人数',
                'date' => $date,
                'value' => $statistic['register_count']
            ];
            $result[] = [
                'type' => '收款金额',
                'date' => $date,
                'value' => $statistic['paid_total'] / 100
            ];
            $result[] = [
                'type' => '收款笔数',
                'date' => $date,
                'value' => $statistic['paid_count']
            ];
            $result[] = [
                'type' => '佣金金额(已发放)',
                'date' => $date,
                'value' => $statistic['commission_total'] / 100
            ];
            $result[] = [
                'type' => '佣金笔数(已发放)',
                'date' => $date,
                'value' => $statistic['commission_count']
            ];
        }
        $result = array_reverse($result);
        return [
            'data' => $result
        ];
    }

    public function getServerLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        return ['data' => $this->getServerRank($startAt, $endAt)];
    }

    public function getServerTodayRank()
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        return ['data' => $this->getServerRank($startAt, $endAt)];
    }

    public function getUserTodayRank()
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        return ['data' => $this->getUserRank($startAt, $endAt)];
    }

    public function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        return ['data' => $this->getUserRank($startAt, $endAt)];
    }

    private function getServerRank(int $startAt, int $endAt): array
    {
        $serverNames = $this->getServerNames();
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->toArray();

        foreach ($statistics as $index => $statistic) {
            $statistics[$index]['server_name'] = $serverNames[$statistic['server_type']][$statistic['server_id']] ?? 'null';
            $statistics[$index]['total'] = $statistic['total'] / 1073741824;
        }
        return $statistics;
    }

    private function getUserRank(int $startAt, int $endAt): array
    {
        $statistics = StatUser::select([
            'user_id',
            DB::raw('SUM((u + d) * server_rate) AS total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(15)
            ->get();
        $emails = User::whereIn('id', $statistics->pluck('user_id')->all())
            ->pluck('email', 'id');

        return $statistics->map(function ($statistic) use ($emails) {
            return [
                'user_id' => $statistic->user_id,
                'email' => $emails[$statistic->user_id] ?? 'null',
                'total' => $statistic->total / 1073741824
            ];
        })->all();
    }

    private function getServerNames(): array
    {
        return Cache::remember('ADMIN_SERVER_NAME_MAP', 60, function () {
            $vmess = ServerVmess::whereNull('parent_id')->pluck('name', 'id')->all();
            return [
                'shadowsocks' => ServerShadowsocks::whereNull('parent_id')->pluck('name', 'id')->all(),
                'v2ray' => $vmess,
                'vmess' => $vmess,
                'trojan' => ServerTrojan::whereNull('parent_id')->pluck('name', 'id')->all(),
                'vless' => ServerVless::whereNull('parent_id')->pluck('name', 'id')->all(),
                'tuic' => ServerTuic::whereNull('parent_id')->pluck('name', 'id')->all(),
                'hysteria' => ServerHysteria::whereNull('parent_id')->pluck('name', 'id')->all(),
                'anytls' => ServerAnytls::whereNull('parent_id')->pluck('name', 'id')->all(),
                'mx' => ServerMx::whereNull('parent_id')->pluck('name', 'id')->all(),
                'v2node' => ServerV2node::whereNull('parent_id')->pluck('name', 'id')->all()
            ];
        });
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $builder = StatUser::orderBy('record_at', 'DESC')->where('user_id', $request->input('user_id'));

        $total = $builder->count();
        $records = $builder->forPage($current, $pageSize)
            ->get();
        return [
            'data' => $records,
            'total' => $total
        ];
    }

}
