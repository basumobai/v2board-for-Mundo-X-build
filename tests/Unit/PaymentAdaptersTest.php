<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\PaymentController;
use App\Payments\EPayQrcode;
use App\Payments\Paytaro;
use App\Payments\PaytaroQR;
use Tests\TestCase;

class PaymentAdaptersTest extends TestCase
{
    private $originalAppSecretHeader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAppSecretHeader = $_SERVER['HTTP_X_APP_SECRET'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalAppSecretHeader === null) {
            unset($_SERVER['HTTP_X_APP_SECRET']);
        } else {
            $_SERVER['HTTP_X_APP_SECRET'] = $this->originalAppSecretHeader;
        }

        parent::tearDown();
    }

    public function testPaytaroCreatesSignedCheckoutAndVerifiesCallback(): void
    {
        $payment = new Paytaro(['pid' => 'app-1', 'key' => 'secret']);
        $result = $payment->pay([
            'trade_no' => 'order-1',
            'total_amount' => 1234,
            'notify_url' => 'https://panel.example/api/notify',
            'return_url' => 'https://panel.example/#/order/order-1',
        ]);

        $this->assertSame(1, $result['type']);
        parse_str((string) parse_url($result['data'], PHP_URL_QUERY), $query);
        $this->assertSame('12.34', $query['money']);
        $this->assertSame($this->sign($query, 'secret'), $query['sign']);

        $callback = [
            'pid' => 'app-1',
            'out_trade_no' => 'order-1',
            'trade_no' => 'gateway-1',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $callback['sign'] = $this->sign($callback, 'secret');
        $callback['sign_type'] = 'MD5';

        $this->assertSame([
            'trade_no' => 'order-1',
            'callback_no' => 'gateway-1',
            'custom_result' => 'success',
        ], $payment->notify($callback));

        $callback['pid'] = 'another-app';
        $callback['sign'] = $this->sign($callback, 'secret');
        $this->assertFalse($payment->notify($callback));
    }

    public function testEPayQrcodeVerifiesSignatureMerchantAndStatus(): void
    {
        $payment = new EPayQrcode([
            'url' => 'https://pay.example',
            'pid' => '1001',
            'key' => 'secret',
            'type' => 'alipay',
        ]);
        $callback = [
            'pid' => '1001',
            'out_trade_no' => 'order-2',
            'trade_no' => 'gateway-2',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $callback['sign'] = $this->sign($callback, 'secret');
        $callback['sign_type'] = 'MD5';

        $this->assertSame([
            'trade_no' => 'order-2',
            'callback_no' => 'gateway-2',
        ], $payment->notify($callback));

        $callback['trade_status'] = 'WAIT_BUYER_PAY';
        $callback['sign'] = $this->sign($callback, 'secret');
        $this->assertFalse($payment->notify($callback));
    }

    public function testPaytaroQrRequiresSecretAndSuccessfulStatus(): void
    {
        $payment = new PaytaroQR([
            'pid' => 'app-2',
            'key' => 'qr-secret',
            'method_uuid' => '11111111-1111-1111-1111-111111111111',
        ]);
        $callback = [
            'merchant_no' => 'order-3',
            'callback_no' => 'gateway-3',
            'status' => 'PAID',
        ];

        $_SERVER['HTTP_X_APP_SECRET'] = 'wrong';
        $this->assertFalse($payment->notify($callback));

        $_SERVER['HTTP_X_APP_SECRET'] = 'qr-secret';
        $this->assertSame([
            'trade_no' => 'order-3',
            'callback_no' => 'gateway-3',
            'custom_result' => 'success',
        ], $payment->notify($callback));

        $callback['status'] = 'UNPAID';
        $this->assertFalse($payment->notify($callback));
    }

    public function testAdminPaymentDiscoveryKeepsMgateAlongsideNewAdapters(): void
    {
        $response = (new PaymentController())->getPaymentMethods();
        $methods = $response->getData(true)['data'];

        $this->assertContains('MGate', $methods);
        $this->assertContains('Paytaro', $methods);
        $this->assertContains('PaytaroQR', $methods);
        $this->assertContains('EPayQrcode', $methods);
        $this->assertStringStartsWith('Paytaro', $methods[0]);
        $this->assertStringStartsWith('Paytaro', $methods[1]);
    }

    public function testPaytaroQrLaravelBridgeRejectsInvalidUuidWithoutUpstreamCall(): void
    {
        $response = $this->withoutMiddleware()
            ->get('/paytaro-qr/pay.php?uuid=invalid');

        $response->assertStatus(400);
        $response->assertHeader('Cache-Control', 'no-store');
        $response->assertSee('订单参数无效');
    }

    private function sign(array $params, string $secret): string
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, static function ($value) {
            return $value !== '' && $value !== null;
        });
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return strtolower(md5(implode('&', $pairs) . $secret));
    }
}
