<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData
        ]);
        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        // Laravel Cache accepts arrays as multi-get input. Never let request
        // values choose that code path or use an unrelated application cache key.
        if (!is_string($jwt) || trim($jwt) === '') return false;
        $cacheKey = self::authCacheKey($jwt);
        try {
            $auth = Cache::get($cacheKey);
            if (!is_array($auth) || !isset($auth['user']['id'], $auth['session'])) {
                $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
                if (!isset($data['id'], $data['session'])
                    || !is_int($data['id']) || $data['id'] < 1
                    || !is_string($data['session']) || $data['session'] === '') return false;
                if (!self::checkSession($data['id'], $data['session'])) return false;
                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff'
                ])
                    ->find($data['id']);
                if (!$user) return false;
                $auth = ['user' => $user->toArray(), 'session' => $data['session']];
                Cache::put($cacheKey, $auth, 3600);
            }
            // A cached JWT must stop working as soon as its session is revoked.
            if (!self::checkSession($auth['user']['id'], $auth['session'])) {
                Cache::forget($cacheKey);
                return false;
            }
            return $auth['user'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function authCacheKey(string $jwt): string
    {
        return 'AUTH_TOKEN_' . hash('sha256', $jwt);
    }

    private static function checkSession($userId, $session)
    {
        $sessions = (array)Cache::get(CacheKey::get("USER_SESSIONS", $userId)) ?? [];
        return is_string($session) && array_key_exists($session, $sessions);
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        if (!is_string($sessionId) || $sessionId === '') return false;
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        $jwt = $sessions[$sessionId]['auth_data'] ?? null;
        if (is_string($jwt)) {
            Cache::forget(self::authCacheKey($jwt));
            Cache::forget($jwt); // Remove entries created before the upgrade too.
        }
        unset($sessions[$sessionId]);
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if (isset($meta['auth_data']) && is_string($meta['auth_data'])) {
                Cache::forget(self::authCacheKey($meta['auth_data']));
                Cache::forget($meta['auth_data']);
            }
        }
        return Cache::forget($cacheKey);
    }
}
