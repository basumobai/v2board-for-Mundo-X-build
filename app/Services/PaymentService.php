<?php

namespace App\Services;


use App\Models\Payment;

class PaymentService
{
    private const CALLBACK_KEYS = [
        'AlipayF2F' => 'public_key',
        'BEasyPaymentUSDT' => 'bepusdt_apitoken',
        'BTCPay' => 'btcpay_webhook_key',
        'CoinPayments' => 'coinpayments_ipn_secret',
        'Coinbase' => 'coinbase_webhook_key',
        'EPay' => 'key', 'EPayQrcode' => 'key', 'Epusdt' => 'epusdt_token',
        'MGate' => 'mgate_app_secret', 'Paytaro' => 'key', 'PaytaroQR' => 'key',
        'StripeALL' => 'stripe_webhook_key', 'StripeAlipay' => 'stripe_webhook_key',
        'StripeCheckout' => 'stripe_webhook_key', 'StripeCredit' => 'stripe_webhook_key',
        'StripeWepay' => 'stripe_webhook_key', 'WechatPayNative' => 'api_key',
    ];

    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        if (!is_string($method) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $method)) {
            abort(400, 'invalid payment method');
        }
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) abort(500, 'gate is not found');
        $payment = null;
        if ($id !== null && $uuid !== null) abort(400, 'ambiguous payment selector');
        if ($id !== null) {
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
                abort(400, 'invalid payment id');
            }
            $payment = Payment::where('id', $id)->where('payment', $method)->first();
        }
        if ($uuid !== null) {
            if (!is_string($uuid) || trim($uuid) === '') abort(400, 'invalid payment uuid');
            $payment = Payment::where('uuid', $uuid)->where('payment', $method)->first();
        }
        if (($id !== null || $uuid !== null) && !$payment) abort(404, 'payment is not found');
        if ($payment && $payment['payment'] !== $method) abort(404, 'payment is not found');
        $this->config = [];
        if (isset($payment)) {
            $this->config = is_array($payment['config']) ? $payment['config'] : [];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        };
        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (empty($this->config['enable'])) abort(500, 'gate is not enable');
        $adapter = (new \ReflectionClass($this->class))->getShortName();
        $key = self::CALLBACK_KEYS[$adapter] ?? null;
        if ($key !== null && (!is_string($this->config[$key] ?? null) || trim($this->config[$key]) === '')) return false;
        return $this->payment->notify($params);
    }

    public function getPaymentId(): int
    {
        return (int) ($this->config['id'] ?? 0);
    }

    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => url('/#/order/' . $order['trade_no']),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }
}
