<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MessagePack\Packer;

class UniProxyController extends Controller
{
    private $nodeType;
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    public function __construct(Request $request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            abort(500, 'token is null');
        }
        if ($token !== config('v2board.server_token')) {
            abort(500, 'token is error');
        }
        $this->nodeType = $request->input('node_type');
        if ($this->nodeType === 'v2ray') $this->nodeType = 'vmess';
        if ($this->nodeType === 'hysteria2') $this->nodeType = 'hysteria';
        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, $this->nodeType);
        if (!$this->nodeInfo) abort(500, 'server is not exist');
    }

    // 后端获取用户
    public function user(Request $request)
    {
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);

        $messagePack = strpos((string)$request->header('X-Response-Format'), 'msgpack') !== false;
        $groupIds = array_values(array_unique(array_map('intval', (array)$this->nodeInfo->group_id)));
        sort($groupIds, SORT_NUMERIC);
        $payloadKey = 'SERVER_USER_PAYLOAD:' . sha1(json_encode([$groupIds, $messagePack]));
        $payload = Cache::remember($payloadKey, 15, function () use ($groupIds, $messagePack) {
            $users = $this->serverService->getAvailableUsers($groupIds)
                ->map(function ($user) {
                    return array_filter($user->toArray(), function ($value) {
                        return !is_null($value);
                    });
                })->toArray();
            $response = ['users' => $users];
            $body = $messagePack
                ? (new Packer())->pack($response)
                : json_encode($response);

            return [
                'body' => $body,
                'etag' => sha1($body)
            ];
        });

        if (strpos((string)$request->header('If-None-Match'), $payload['etag']) !== false) {
            return response('', 304)->header('ETag', '"' . $payload['etag'] . '"');
        }

        $contentType = $messagePack ? 'application/x-msgpack' : 'application/json';
        return response($payload['body'], 200, ['Content-Type' => $contentType])
            ->header('ETag', '"' . $payload['etag'] . '"');
    }

    // 后端提交数据
    public function push(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // JSON decoding error
            return response([
                'error' => 'Invalid traffic data'
            ], 400);
        }
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_ONLINE_USER', $this->nodeInfo->id), count($data), 3600);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_PUSH_AT', $this->nodeInfo->id), time(), 3600);
        $userService = new UserService();
        $userService->trafficFetch($this->nodeInfo->toArray(), $this->nodeType, $data);

        return response([
            'data' => true
        ]);
    }

    // 后端获取在线数据
    public function alivelist(Request $request)
    {
        $alive = Cache::remember('ALIVE_LIST', 60, function () {
            $userService = new UserService();
            $users = $userService->getDeviceLimitedUsers();

            if ($users->isEmpty()) {
                return [];
            }

            $keys = [];
            $idMap = [];
            foreach ($users as $user) {
                $key = 'ALIVE_IP_USER_' . $user->id;
                $keys[] = $key;
                $idMap[$key] = $user->id;
            }

            $results = Cache::many($keys);
            $alive = [];
            foreach ($results as $key => $data) {
                if (is_array($data) && isset($data['alive_ip'])) {
                    $alive[$idMap[$key]] = $data['alive_ip'];
                }
            }
            return $alive;
        });
        return response()->json(['alive' => (object)$alive]);
    }

    // 后端提交在线数据
    public function alive(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if (empty($data)) {
            return response([
                'data' => true
            ]);
        }
        if (!is_array($data)) {
            return response([
                'error' => 'Invalid online data format'
            ], 400);
        }
        $lock = Cache::lock('ALIVE_IP_UPDATE_LOCK', 15);
        try {
            $lock->block(5, function () use ($data) {
                $this->updateAliveCache($data);
            });
        } catch (LockTimeoutException $e) {
            return response([
                'error' => 'Online data is busy, retry later'
            ], 503);
        }

        return response([
            'data' => true
        ]);
    }

    private function updateAliveCache(array $data): void
    {
        $updateAt = time();
        $cacheKeys = [];
        $keyMap = [];
        foreach ($data as $uid => $ips) {
            if (!is_numeric($uid) || (int)$uid <= 0 || !is_array($ips)) {
                continue;
            }
            $key = 'ALIVE_IP_USER_' . (int)$uid;
            $cacheKeys[] = $key;
            $keyMap[(int)$uid] = $key;
        }
        if (empty($cacheKeys)) {
            return;
        }

        $cachedData = Cache::many($cacheKeys);
        $updates = [];
        foreach ($data as $uid => $ips) {
            $uid = (int)$uid;
            if (!isset($keyMap[$uid]) || !is_array($ips)) {
                continue;
            }
            $key = $keyMap[$uid];
            $ipData = $cachedData[$key] ?? [];
            if (!is_array($ipData)) {
                $ipData = [];
            }
            $ipData[$this->nodeType . ':' . $this->nodeId] = [
                'aliveips' => $ips,
                'lastupdateAt' => $updateAt
            ];

            foreach ($ipData as $node => $oldData) {
                if ($node !== 'alive_ip' && is_array($oldData)
                    && $updateAt - ($oldData['lastupdateAt'] ?? 0) > 100) {
                    unset($ipData[$node]);
                }
            }

            $count = 0;
            if ((int)config('v2board.device_limit_mode', 0) === 1) {
                $ipMap = [];
                foreach ($ipData as $node => $nodeData) {
                    if ($node === 'alive_ip' || !is_array($nodeData) || !isset($nodeData['aliveips'])) {
                        continue;
                    }
                    foreach ($nodeData['aliveips'] as $ipNodeId) {
                        if (!is_scalar($ipNodeId)) {
                            continue;
                        }
                        $ipMap[explode('_', $ipNodeId)[0]] = true;
                    }
                }
                $count = count($ipMap);
            } else {
                foreach ($ipData as $node => $nodeData) {
                    if ($node !== 'alive_ip' && is_array($nodeData) && isset($nodeData['aliveips'])) {
                        $count += count($nodeData['aliveips']);
                    }
                }
            }
            $ipData['alive_ip'] = $count;
            $updates[$key] = $ipData;
        }

        Cache::putMany($updates, 120);
        Cache::forget('ALIVE_LIST');
    }

    // 后端获取配置
    public function config(Request $request)
    {
        switch ($this->nodeType) {
            case 'shadowsocks':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'cipher' => $this->nodeInfo->cipher,
                    'obfs' => $this->nodeInfo->obfs,
                    'obfs_settings' => $this->nodeInfo->obfs_settings
                ];

                if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
                }
                if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
                }
                break;
            case 'vmess':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->networkSettings,
                    'tls' => $this->nodeInfo->tls
                ];
                break;
            case 'vless':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'tls' => $this->nodeInfo->tls,
                    'flow' => $this->nodeInfo->flow,
                    'tls_settings' => $this->nodeInfo->tls_settings,
                    'encryption' => $this->nodeInfo->encryption,
                    'encryption_settings' => $this->nodeInfo->encryption_settings
                ];
                break;
            case 'trojan':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                ];
                break;
            case 'tuic':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'congestion_control' => $this->nodeInfo->congestion_control,
                    'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
                ];
                break;
            case 'hysteria':
                $response = [
                    'version' => $this->nodeInfo->version,
                    'host' => $this->nodeInfo->host,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'up_mbps' => $this->nodeInfo->up_mbps,
                    'down_mbps' => $this->nodeInfo->down_mbps
                ];
                if ($this->nodeInfo->version == 1) {
                    $response['obfs'] = $this->nodeInfo->obfs_password ?? null;
                } elseif ($this->nodeInfo->version == 2) {
                    if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
                        $response['ignore_client_bandwidth'] = true;
                    } else {
                        $response['ignore_client_bandwidth'] = false;
                    }
                    $response['obfs'] = $this->nodeInfo->obfs ?? null;
                    $response['obfs-password'] = $this->nodeInfo->obfs_password ?? null;
                }
                break;
            case 'anytls':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'padding_scheme' => $this->nodeInfo->padding_scheme
                ];
                break;
            case 'mx':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'listen_ip' => $this->nodeInfo->listen_ip,
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'network_settings' => $this->nodeInfo->network_settings,
                    'protocol' => 'mx',
                    'tls' => $this->nodeInfo->tls,
                    'tls_settings' => $this->nodeInfo->tls_settings,
                    'tlsSettings' => $this->nodeInfo->tls_settings,
                    'server_name' => $this->nodeInfo->server_name ?: ($this->nodeInfo->tls_settings['server_name'] ?? null),
                    'allow_insecure' => $this->nodeInfo->allow_insecure,
                ];
                break;
        }
        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60)
        ];
        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }
        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match'), $eTag) !== false) {
            abort(304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }
}
