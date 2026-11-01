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
use App\Payments\AlipayF2F;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\PaymentCallback;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
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

    public function testEveryShippedGatewayRejectsEmptyCallbackCredentials(): void
    {
        foreach (glob(app_path('Payments/*.php')) as $file) {
            $method = pathinfo($file, PATHINFO_FILENAME);
            $payment = Payment::create(['uuid' => 'empty-' . $method, 'payment' => $method, 'enable' => 1, 'config' => []]);
            $this->assertFalse((new PaymentService($method, null, $payment->uuid))->notify([]), $method);
        }
    }

    public function testAlipayCallbackCannotUseAnotherApplicationId(): void
    {
        $adapter = new AlipayF2F(['app_id' => 'own-app', 'public_key' => 'not-needed-for-rejected-app', 'private_key' => '']);
        $this->assertFalse($adapter->notify(['app_id' => 'other-app', 'sign' => 'signed-by-provider',
            'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => 'order-1', 'trade_no' => 'gateway-1']));
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
        foreach (['0' => 0, '0.01' => 1, '12.3' => 1230, '12.34' => 1234, '12.34000000' => 1234] as $input => $expected) $this->assertSame($expected, PaymentCallback::amountMinor($input));
        foreach (['', '-1', '1e2', '1.001', [], null, 'NaN', '12.34x'] as $input) $this->assertNull(PaymentCallback::amountMinor($input));
    }

    public function testSignedCoinbaseCreatedEventCannotSettleAnOrder(): void
    {
        $payment = Payment::create(['uuid' => 'coinbase-main', 'payment' => 'Coinbase', 'enable' => 1, 'config' => ['coinbase_webhook_key' => 'secret']]);
        $order = Order::create(['user_id' => User::create([])->id, 'payment_id' => $payment->id, 'trade_no' => 'coinbase-order']);
        $event = ['event' => ['id' => 'coinbase-event', 'type' => 'charge:created', 'data' => [
            'metadata' => ['outTradeNo' => $order->trade_no], 'pricing' => ['local' => ['amount' => '5.00', 'currency' => 'CNY']],
        ]]];
        $this->sendSignedJson($payment, $event, 'X-Cc-Webhook-Signature')->assertOk();
        $this->assertSame(0, (int) $order->fresh()->status);
        Queue::assertNothingPushed();
        $event['event']['type'] = 'charge:confirmed';
        $this->sendSignedJson($payment, $event, 'X-Cc-Webhook-Signature')->assertOk();
        $this->assertSame(1, (int) $order->fresh()->status);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    public function testCoinpaymentsVerifiesRawBodyCurrencyAndAmount(): void
    {
        $payment = Payment::create(['uuid' => 'coinpayments-main', 'payment' => 'CoinPayments', 'enable' => 1, 'config' => [
            'coinpayments_ipn_secret' => 'secret', 'coinpayments_merchant_id' => 'merchant-1', 'coinpayments_currency' => 'CNY',
        ]]);
        $order = Order::create(['user_id' => User::create([])->id, 'payment_id' => $payment->id, 'trade_no' => 'cp-order']);
        // Keep an unsorted body and an encoded plus sign: reserializing either
        // changes the bytes and must not be part of HMAC verification.
        $params = ['txn_id' => 'gateway+1', 'item_number' => $order->trade_no, 'merchant' => 'merchant-1', 'status' => '100', 'currency1' => 'USD', 'amount1' => '5.00000000'];
        $send = function () use ($payment, &$params) {
            $body = http_build_query($params);
            return $this->withoutMiddleware()->call('POST', '/api/v1/guest/payment/notify/CoinPayments/' . $payment->uuid,
                [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_HMAC' => hash_hmac('sha512', $body, 'secret')], $body);
        };
        $send()->assertStatus(500);
        $params['currency1'] = 'CNY';
        $params['amount1'] = '0.01000000';
        $send()->assertStatus(500);
        $this->assertSame(0, (int) $order->fresh()->status);
        $params['amount1'] = '5.00000000';
        $send()->assertOk();
        $this->assertSame(1, (int) $order->fresh()->status);
        $this->assertSame('gateway+1', $order->fresh()->callback_no);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    public function testBtcpayRequiresSettledInvoiceAndChecksItsAuthenticatedAmount(): void
    {
        $payment = Payment::create(['uuid' => 'btcpay-main', 'payment' => 'BTCPay', 'enable' => 1, 'config' => [
            'btcpay_webhook_key' => 'secret', 'btcpay_api_key' => 'api-secret', 'btcpay_storeId' => 'store-1', 'btcpay_url' => 'https://btc.example/',
        ]]);
        $order = Order::create(['user_id' => User::create([])->id, 'payment_id' => $payment->id, 'trade_no' => 'btc-order']);
        $event = ['type' => 'InvoiceCreated', 'invoiceId' => 'invoice-1'];
        $invoice = [];
        $url = 'https://btc.example/api/v1/stores/store-1/invoices/invoice-1';
        Http::fake(function ($request) use (&$invoice, $url) {
            $this->assertSame($url, $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'token api-secret'));
            return Http::response($invoice);
        });
        $this->sendSignedJson($payment, $event, 'BTCPay-Sig', 'sha256=')->assertOk();
        Http::assertNothingSent();
        $this->assertSame(0, (int) $order->fresh()->status);
        $event['type'] = 'InvoiceSettled';
        $invoice = ['id' => 'invoice-1', 'status' => 'New', 'additionalStatus' => 'None', 'currency' => 'CNY', 'amount' => '5.00', 'metadata' => ['orderId' => $order->trade_no]];
        $this->sendSignedJson($payment, $event, 'BTCPay-Sig', 'sha256=')->assertStatus(500);
        $invoice['status'] = 'Settled';
        $invoice['amount'] = '0.01';
        $this->sendSignedJson($payment, $event, 'BTCPay-Sig', 'sha256=')->assertStatus(500);
        $invoice['amount'] = '5.00';
        $this->sendSignedJson($payment, $event, 'BTCPay-Sig', 'sha256=')->assertOk();
        $this->assertSame(1, (int) $order->fresh()->status);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    private function sendSignedJson(Payment $payment, array $event, string $header, string $prefix = '')
    {
        // Whitespace belongs to the signed bytes too; adapters must not trim it.
        $body = " \n" . json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
        return $this->withoutMiddleware()->call('POST', '/api/v1/guest/payment/notify/' . $payment->payment . '/' . $payment->uuid,
            [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_' . strtoupper(str_replace('-', '_', $header)) => $prefix . hash_hmac('sha256', $body, 'secret')], $body);
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
