<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManageController extends Controller
{
    private const SERVER_MODELS = [
        'shadowsocks' => \App\Models\ServerShadowsocks::class,
        'vmess' => \App\Models\ServerVmess::class,
        'vless' => \App\Models\ServerVless::class,
        'trojan' => \App\Models\ServerTrojan::class,
        'tuic' => \App\Models\ServerTuic::class,
        'hysteria' => \App\Models\ServerHysteria::class,
        'anytls' => \App\Models\ServerAnytls::class,
        'mx' => \App\Models\ServerMx::class,
        'v2node' => \App\Models\ServerV2node::class,
    ];

    public function getNodes(Request $request)
    {
        $serverService = new ServerService();
        return response([
            'data' => $serverService->getAllServers()
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '5m');
        $serverTypes = array_keys(self::SERVER_MODELS);
        $params = $request->only($serverTypes);
        if (empty($params)) {
            foreach ($serverTypes as $serverType) {
                if (isset($_POST[$serverType])) {
                    $params[$serverType] = $_POST[$serverType];
                }
            }
        }

        DB::transaction(function () use ($params) {
            foreach ($params as $serverType => $sorts) {
                if (!isset(self::SERVER_MODELS[$serverType]) || !is_array($sorts)) {
                    continue;
                }

                $model = self::SERVER_MODELS[$serverType];
                foreach ($sorts as $id => $sort) {
                    $server = $model::find($id);
                    if (!$server || !$server->update(['sort' => $sort])) {
                        abort(500, '保存失败');
                    }
                }
            }
        });

        return response([
            'data' => true
        ]);
    }
}
