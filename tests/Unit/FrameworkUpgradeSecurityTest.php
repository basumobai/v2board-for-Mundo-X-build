<?php

namespace Tests\Unit;

use App\Http\Middleware\CORS;
use App\Jobs\SendEmailJob;
use App\Logging\MysqlLoggerHandler;
use App\Protocols\Shadowrocket;
use App\Support\TrustedOrigins;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Monolog\Logger;
use Tests\CreatesSecurityDatabase;
use Tests\TestCase;

class FrameworkUpgradeSecurityTest extends TestCase
{
    use CreatesSecurityDatabase;

    public function testCallbackRateLimitKeepsPaymentConfigurationsIndependent(): void
    {
        config(['security.payment_callbacks_per_minute' => 2]);
        \Illuminate\Support\Facades\Route::post('/probe/notify/{uuid}', fn () => response('ok'))->middleware('throttle:payment-callbacks');
        $this->post('/probe/notify/first')->assertOk();
        $this->post('/probe/notify/first')->assertOk();
        $this->post('/probe/notify/first')->assertStatus(429)->assertHeader('Retry-After');
        $this->post('/probe/notify/second')->assertOk();
    }

    public function testSymfonyMailerRendersTheExistingChineseTemplate(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'panel@example.test']);
        $sent = Mail::send('mail.default.verify', ['name' => 'Mundo', 'code' => '123456', 'url' => 'https://panel.example.test'],
            fn ($message) => $message->to('user@example.test')->subject('验证邮件'));
        $this->assertNotNull($sent);
        $this->assertSame('验证邮件', $sent->getSymfonySentMessage()->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('123456', $sent->getSymfonySentMessage()->getOriginalMessage()->getHtmlBody());
        $this->assertSame(1, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count());
    }

    public function testCorsAllowsOnlyExplicitOriginsAndHandlesPreflight(): void
    {
        config(['cors.allowed_origins' => ['https://frontend.example'], 'app.url' => 'https://backend.example',
            'v2board.app_url' => null, 'security.frontend_url' => null, 'cors.supports_credentials' => false]);
        foreach (['https://evil.example', 'null', 'https://frontend.example.evil.test', 'https://frontend.example/path'] as $origin) {
            $request = Request::create('/', 'OPTIONS', [], [], [], ['HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
            $response = (new CORS())->handle($request, fn () => $this->fail('Preflight entered controller'));
            $this->assertSame(403, $response->getStatusCode());
            $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        }
        $request = Request::create('/', 'OPTIONS', [], [], [], ['HTTP_ORIGIN' => 'https://frontend.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $response = (new CORS())->handle($request, fn () => $this->fail('Preflight entered controller'));
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://frontend.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertContains('Origin', $response->getVary());
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_REFERER' => 'https://evil.example/']);
        $response = (new CORS())->handle($request, fn () => response('ok'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function testPaymentReturnUrlRejectsUntrustedOriginAndPreservesConfiguredSubpath(): void
    {
        config(['security.frontend_url' => 'https://frontend.example/panel/?old=1#stale', 'cors.allowed_origins' => [],
            'app.url' => 'https://backend.example', 'v2board.app_url' => null]);
        $this->assertSame('https://frontend.example/panel', TrustedOrigins::frontendBase('https://evil.example'));
        $this->assertSame('https://frontend.example', TrustedOrigins::frontendBase('https://frontend.example'));
        foreach (['javascript:alert(1)', "https://example.test\r\nX: injected", 'https://user:pass@example.test', '//example.test'] as $invalid) {
            $this->assertNull(TrustedOrigins::origin($invalid));
        }
    }

    public function testMonologThreeDatabaseHandlerRedactsNestedSecretsAndQueryTokens(): void
    {
        $this->createSecurityDatabase();
        Schema::dropIfExists('v2_log');
        Schema::create('v2_log', function (Blueprint $table) {
            $table->increments('id');
            foreach (['title', 'level', 'host', 'uri', 'method', 'ip', 'data', 'context'] as $key) $table->text($key)->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        $this->app->instance('request', Request::create('https://app.example/api/test?auth_data=query-secret', 'POST', [
            'nested' => ['password' => 'password-secret', 'api_key' => 'key-secret', 'normal' => 'kept'],
        ]));
        $logger = new Logger('security', [new MysqlLoggerHandler()]);
        $logger->error('Request failed', ['token' => 'context-secret', 'exception' => new \RuntimeException('exception-secret')]);
        $row = (array) DB::table('v2_log')->first();
        $this->assertSame('/api/test', $row['uri']);
        $serialized = json_encode($row);
        foreach (['query-secret', 'password-secret', 'key-secret', 'context-secret', 'exception-secret'] as $secret) $this->assertStringNotContainsString($secret, $serialized);
        $this->assertStringContainsString('kept', $serialized);
        $this->assertSame('ERROR', $row['level']);
    }

    public function testSmtpOverridesAreRestoredAfterSuccessAndFailure(): void
    {
        $this->createSecurityDatabase();
        Schema::dropIfExists('v2_mail_log');
        Schema::create('v2_mail_log', function (Blueprint $table) {
            $table->increments('id');
            foreach (['email', 'subject', 'template_name', 'error'] as $key) $table->text($key)->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        config(['v2board.email_host' => 'smtp.example.test', 'v2board.email_password' => 'job-secret']);
        $original = config('mail');
        foreach ([false, true] as $fail) {
            Mail::shouldReceive('purge')->with('smtp')->twice();
            Mail::shouldReceive('send')->once()->andReturnUsing(function () use ($fail) {
                $this->assertSame('smtp', config('mail.default'));
                $this->assertSame('job-secret', config('mail.mailers.smtp.password'));
                if ($fail) throw new \RuntimeException('SMTP fixture failure');
            });
            try {
                $result = (new SendEmailJob(['email' => 'user@example.test', 'subject' => 'Test', 'template_name' => 'verify', 'template_value' => []]))->handle();
                $this->assertFalse($fail);
                $this->assertArrayNotHasKey('config', $result);
                $this->assertStringNotContainsString('job-secret', json_encode($result));
            } catch (\RuntimeException $error) {
                $this->assertTrue($fail);
                $this->assertSame('SMTP fixture failure', $error->getMessage());
            }
            $this->assertSame($original, config('mail'));
        }
        $this->assertSame(2, DB::table('v2_mail_log')->count());
    }

    public function testPermanentShadowrocketSubscriptionDoesNotShowEpochDate(): void
    {
        $user = ['u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => null];
        $this->assertStringContainsString('长期有效', base64_decode((new Shadowrocket($user, []))->handle()));
        $user['expired_at'] = 1900000000;
        $this->assertStringContainsString(date('Y-m-d', 1900000000), base64_decode((new Shadowrocket($user, []))->handle()));
    }
}
