<?php

namespace Tests\Unit;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\EPay;
use App\Payments\EPayQrcode;
use App\Payments\Epusdt;
use App\Payments\Paytaro;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\PaymentCallback;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesSecurityDatabase;
use Tests\TestCase;

class PaymentSecurityTest extends TestCase
{
    use CreatesSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSecurityDatabase();
        config(['v2board.telegram_bot_enable' => 0, 'v2board.deposit_bounus' => []]);
        Queue::fake();
    }

    public function testPaymentSelectorsAreBoundToTheGatewayType(): void
    {
        $payment = $this->payment();
        $this->assertSame($payment->id, (new PaymentService('Paytaro', null, $payment->uuid))->getPaymentId());
        foreach ([['Epusdt', null, $payment->uuid], ['Epusdt', $payment->id, null], ['Paytaro', null, 'missing']] as $args) {
            try {
                new PaymentService(...$args);
                $this->fail('A mismatched payment configuration was loaded');
            } catch (HttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
        }
    }

    public function testEmptySecretsAndNestedSignaturePayloadsAreRejected(): void
    {
        $params = ['pid' => 'app-1', 'out_trade_no' => 'order-1', 'trade_no' => 'gateway-1', 'trade_status' => 'TRADE_SUCCESS'];
        $params['sign'] = $this->sign($params, '');
        foreach ([new Paytaro(['pid' => 'app-1', 'key' => '']), new EPay(['pid' => 'app-1', 'key' => '']), new EPayQrcode(['pid' => 'app-1', 'key' => ''])] as $adapter) $this->assertFalse($adapter->notify($params));
        $epusdt = ['order_id' => 'order-1', 'trade_id' => 'gateway-1', 'status' => 2];
        $pairs = $epusdt;
        ksort($pairs);
        $epusdt['signature'] = md5(urldecode(http_build_query($pairs)));
        $this->assertFalse((new Epusdt(['epusdt_token' => '']))->notify($epusdt));
        $params['sign'] = ['nested'];
        $this->assertFalse((new Paytaro(['pid' => 'app-1', 'key' => 'secret']))->notify($params));
    }

    public function testSignedAmountIncludesHandlingFeeAndWrongConfigurationCannotPayOrder(): void
    {
        $payment = $this->payment();
        $user = User::create(['balance' => 1000]);
        $order = Order::create(['user_id' => $user->id, 'payment_id' => $payment->id, 'trade_no' => 'order-amount', 'handling_amount' => 25]);
        $service = new OrderService($order);
        $this->assertFalse($service->paid('gateway-1', $payment->id + 1, 525));
        $this->assertFalse($service->paid('gateway-1', $payment->id, 500));
        $this->assertSame(0, (int) $order->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertTrue($service->paid('gateway-1', $payment->id, 525));
        $this->assertTrue((new OrderService($order->fresh()))->paid('gateway-1', $payment->id, 525));
        $this->assertFalse((new OrderService($order->fresh()))->paid('gateway-1', $payment->id + 1, 525));
        Queue::assertPushed(OrderHandleJob::class, 1);
        $this->assertTrue((new OrderService($order))->open());
        $this->assertTrue((new OrderService($order))->open());
        $this->assertSame(1500, (int) $user->fresh()->balance);
    }

    public function testCallbackRouteRejectsBadSignatureAmountAndWrongPaymentAndAcceptsDuplicates(): void
    {
        $payment = $this->payment();
        $other = $this->payment('payment-other');
        $user = User::create(['balance' => 1000]);
        $order = Order::create(['user_id' => $user->id, 'payment_id' => $payment->id, 'trade_no' => 'order-callback']);
        $url = '/api/v1/guest/payment/notify/Paytaro/' . $payment->uuid;
        $params = ['pid' => 'app-1', 'out_trade_no' => $order->trade_no, 'trade_no' => 'gateway-1', 'trade_status' => 'TRADE_SUCCESS', 'money' => '5.00'];
        $params['sign'] = 'wrong';
        $this->withoutMiddleware()->post($url, $params)->assertStatus(500);
        $params['money'] = '0.01';
        $params['sign'] = $this->sign($params, 'secret');
        $this->post($url, $params)->assertStatus(500);
        $params['money'] = '5.00';
        $params['sign'] = $this->sign($params, 'secret');
        $this->post('/api/v1/guest/payment/notify/Paytaro/' . $other->uuid, $params)->assertStatus(500);
        $this->assertSame(0, (int) $order->fresh()->status);
        $this->post($url, $params)->assertOk()->assertSee('success');
        $this->post($url, $params)->assertOk();
        Queue::assertPushed(OrderHandleJob::class, 1);
        $this->assertSame(1, (int) $order->fresh()->status);
    }

    public function testDecimalAmountsAreParsedExactlyOrRejected(): void
    {
        foreach (['0' => 0, '0.01' => 1, '12.3' => 1230, '12.34' => 1234] as $input => $expected) $this->assertSame($expected, PaymentCallback::amountMinor($input));
        foreach (['', '-1', '1e2', '1.001', [], null, 'NaN', '12.34x'] as $input) $this->assertNull(PaymentCallback::amountMinor($input));
    }

    private function payment(string $uuid = 'payment-main'): Payment
    {
        return Payment::create(['uuid' => $uuid, 'payment' => 'Paytaro', 'enable' => 1, 'config' => ['pid' => 'app-1', 'key' => 'secret']]);
    }

    private function sign(array $params, string $secret): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        return md5(urldecode(http_build_query($params)) . $secret);
    }
}
