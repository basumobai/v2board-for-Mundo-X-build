<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerShadowsocks;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/*
 * Tidal Lab Shadowsocks
 * Github: https://github.com/tokumeikoi/tidalab-ss
 */
class ShadowsocksTidalabController extends Controller
{
    public function __construct(Request $request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            abort(500, 'token is null');
        }
        if ($token !== config('v2board.server_token')) {
            abort(500, 'token is error');
        }
    }

    // 后端获取用户
    public function user(Request $request)
    {
        $nodeId = $request->input('node_id');
        $server = ServerShadowsocks::find($nodeId);
        if (!$server) {
            abort(500, 'fail');
        }
        Cache::put(CacheKey::get('SERVER_SHADOWSOCKS_LAST_CHECK_AT', $server->id), time(), 3600);
        $cacheKey = 'SERVER_LEGACY_USER_PAYLOAD:' . sha1(json_encode([
            'shadowsocks',
            (int)$server->id,
            (int)$server->updated_at,
            $server->group_id
        ]));
        $payload = Cache::remember($cacheKey, 15, function () use ($server) {
            $users = (new ServerService())->getAvailableUsers($server->group_id);
            $result = [];
            foreach ($users as $user) {
                $result[] = [
                    'id' => $user->id,
                    'port' => $server->server_port,
                    'cipher' => $server->cipher,
                    'secret' => $user->uuid
                ];
            }
            $body = json_encode(['data' => $result]);

            return [
                'body' => $body,
                'etag' => sha1($body)
            ];
        });

        if (strpos((string)$request->header('If-None-Match'), $payload['etag']) !== false) {
            return response('', 304)->header('ETag', '"' . $payload['etag'] . '"');
        }

        return response($payload['body'], 200, ['Content-Type' => 'application/json'])
            ->header('ETag', '"' . $payload['etag'] . '"');
    }

    // 后端提交数据
    public function submit(Request $request)
    {
//         Log::info('serverSubmitData:' . $request->input('node_id') . ':' . request()->getContent() ?: json_encode($_POST));
        $server = ServerShadowsocks::find($request->input('node_id'));
        if (!$server) {
            return response([
                'ret' => 0,
                'msg' => 'server is not found'
            ]);
        }
        $data = request()->getContent() ?: json_encode($_POST);
        $data = json_decode($data, true);
        Cache::put(CacheKey::get('SERVER_SHADOWSOCKS_ONLINE_USER', $server->id), count($data), 3600);
        Cache::put(CacheKey::get('SERVER_SHADOWSOCKS_LAST_PUSH_AT', $server->id), time(), 3600);
        $userService = new UserService();
        $formatData = [];

        foreach ($data as $item) {
            $formatData[$item['user_id']] = [$item['u'], $item['d']];
        }
        $userService->trafficFetch($server->toArray(), 'shadowsocks', $formatData);

        return response([
            'ret' => 1,
            'msg' => 'ok'
        ]);
    }
}
