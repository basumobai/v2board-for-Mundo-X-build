<?php

namespace Tests\Unit;

use App\Http\Middleware\Admin;
use App\Http\Middleware\Staff;
use App\Http\Middleware\User as UserMiddleware;
use App\Http\Controllers\V1\Staff\UserController;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesSecurityDatabase;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use CreatesSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSecurityDatabase();
        Cache::flush();
        config(['app.key' => str_repeat('security-test-key', 4)]);
    }

    public function testMalformedTokensNeverReachCache(): void
    {
        Cache::spy();
        foreach ([null, false, true, 1, 1.5, [], ['is_staff' => '1'], ['is_admin' => '1'], (object) ['is_staff' => 1], '', " \t\n"] as $token) {
            $this->assertFalse(AuthService::decryptAuthData($token));
        }
        Cache::shouldNotHaveReceived('get');
        Cache::shouldNotHaveReceived('has');
    }

    public function testEveryAuthenticationMiddlewareRejectsArrayTokens(): void
    {
        foreach ([new UserMiddleware(), new Admin(), new Staff()] as $middleware) {
            try {
                $middleware->handle(Request::create('/', 'GET', ['auth_data' => ['is_staff' => 1, 'is_admin' => 1]]), function () {
                    $this->fail('Malformed authentication reached the controller');
                });
                $this->fail('Malformed authentication was accepted');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
    }

    public function testValidTokensWorkAndRevocationInvalidatesWarmCache(): void
    {
        $user = User::create(['email' => 'admin@example.test', 'is_admin' => 1]);
        $service = new AuthService($user);
        $auth = $service->generateAuthData(Request::create('/'));
        $this->assertSame($user->id, AuthService::decryptAuthData($auth['auth_data'])['id']);
        $this->assertSame($user->id, AuthService::decryptAuthData($auth['auth_data'])['id']);
        $request = Request::create('/', 'GET');
        $request->headers->set('authorization', $auth['auth_data']);
        $this->assertSame('ok', (new Admin())->handle($request, function () { return 'ok'; }));
        $session = array_keys($service->getSessions())[0];
        $this->assertTrue($service->removeSession($session));
        $this->assertFalse(AuthService::decryptAuthData($auth['auth_data']));
    }

    public function testUnrelatedCacheEntriesAndBadJwtClaimsCannotAuthenticate(): void
    {
        Cache::put('not-a-jwt', ['id' => 1, 'is_admin' => 1, 'is_staff' => 1], 3600);
        $this->assertFalse(AuthService::decryptAuthData('not-a-jwt'));
        foreach ([['id' => [], 'session' => 'x'], ['id' => 1, 'session' => []], ['id' => 1], []] as $claims) {
            $jwt = JWT::encode($claims, config('app.key'), 'HS256');
            $this->assertFalse(AuthService::decryptAuthData($jwt));
        }
    }

    public function testRemovingAllSessionsRejectsAllCachedTokens(): void
    {
        $user = User::create([]);
        $service = new AuthService($user);
        $first = $service->generateAuthData(Request::create('/'))['auth_data'];
        $second = $service->generateAuthData(Request::create('/'))['auth_data'];
        $this->assertIsArray(AuthService::decryptAuthData($first));
        $this->assertIsArray(AuthService::decryptAuthData($second));
        $service->removeAllSession();
        $this->assertFalse(AuthService::decryptAuthData($first));
        $this->assertFalse(AuthService::decryptAuthData($second));
    }

    public function testNormalUserCannotEnterAdminOrStaffMiddleware(): void
    {
        $auth = (new AuthService(User::create([])))->generateAuthData(Request::create('/'));
        foreach ([new Admin(), new Staff()] as $middleware) {
            try {
                $middleware->handle(Request::create('/', 'GET', ['auth_data' => $auth['auth_data']]), function () {
                    $this->fail('Normal user escalated privileges');
                });
                $this->fail('Normal user was accepted');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
    }

    public function testStaffRoutesAreNotRegisteredAndUserFieldsAreAllowlisted(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $this->assertFalse(strpos($route->uri(), 'api/v1/staff/') === 0, $route->uri());
        }
        $user = User::create(['password' => 'hash', 'password_salt' => 'salt', 'password_algo' => 'algo', 'token' => 'subscription-secret']);
        $this->assertArrayNotHasKey('password', $user->toArray());
        $response = (new UserController())->getUserInfoById(Request::create('/', 'GET', ['id' => $user->id]));
        $data = json_decode($response->getContent(), true)['data'];
        foreach (['password', 'password_algo', 'password_salt', 'token', 'remember_token', 'is_admin', 'is_staff'] as $field) $this->assertArrayNotHasKey($field, $data);
        $this->assertSame($user->id, $data['id']);
    }
}
