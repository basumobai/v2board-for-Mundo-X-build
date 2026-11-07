<?php

namespace App\Payments;

use App\Support\PaymentCallback;

class CoinPayments {
    private $config;
    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'coinpayments_merchant_id' => [
                'label' => 'Merchant ID',
                'description' => '商户 ID，填写您在 Account Settings 中得到的 ID',
                'type' => 'input',
            ],
            'coinpayments_ipn_secret' => [
                'label' => 'IPN Secret',
                'description' => '通知密钥，填写您在 Merchant Settings 中自行设置的值',
                'type' => 'input',
            ],
            'coinpayments_currency' => [
                'label' => '货币代码',
                'description' => '填写您的货币代码（大写），建议与 Merchant Settings 中的值相同',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {

        // IPN notifications are slow, when the transaction is successful, we should return to the user center to avoid user confusion
        $parseUrl = parse_url($order['return_url']);
        $port = isset($parseUrl['port']) ? ":{$parseUrl['port']}" : '';
        $successUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}{$port}";

        $params = [
            'cmd' => '_pay_simple',
            'reset' => 1,
            'merchant' => $this->config['coinpayments_merchant_id'],
            'item_name' => $order['trade_no'],
            'item_number' => $order['trade_no'],
            'want_shipping' => 0,
            'currency' => $this->config['coinpayments_currency'],
            'amountf' => sprintf('%.2f', $order['total_amount'] / 100),
            'success_url' => $successUrl,
            'cancel_url' => $order['return_url'],
            'ipn_url' => $order['notify_url']
        ];

        $params_string = http_build_query($params);

        return [
            'type' => 1, // Redirect to url
            'data' =>  'https://www.coinpayments.net/index.php?' . $params_string
        ];
    }

    public function notify($params)
    {
        $secret = $this->config['coinpayments_ipn_secret'] ?? null;
        if (!is_string($secret) || trim($secret) === '') return false;
        $body = request()->getContent();
        if (!is_string($body) || $body === '') return false;
        $signHeader = (string) request()->header('HMAC', '');
        $hmac = hash_hmac('sha512', $body, trim($secret));
        if (!hash_equals($hmac, $signHeader)) {
            abort(400, 'HMAC signature does not match');
        }
        // Only parse the authenticated body; query parameters are not signed.
        parse_str($body, $params);
        if (!PaymentCallback::isFlatPayload($params)
            || (string) ($params['merchant'] ?? '') !== trim((string) ($this->config['coinpayments_merchant_id'] ?? ''))
            || empty($params['merchant']) || empty($params['item_number']) || empty($params['txn_id'])
            || !preg_match('/\A-?[0-9]+\z/', (string) ($params['status'] ?? ''))) return false;
        if (strtoupper((string) ($params['currency1'] ?? '')) !== strtoupper((string) ($this->config['coinpayments_currency'] ?? ''))
            || empty($params['currency1'])) return false;
        $amount = PaymentCallback::amountMinor($params['amount1'] ?? null);
        if ($amount === null) return false;
        $status = (int) $params['status'];
        if ($status >= 100 || $status == 2) {
            // payment is complete or queued for nightly payout, success
            return [
                'trade_no' => $params['item_number'],
                'callback_no' => $params['txn_id'],
                'amount_minor' => $amount,
                'custom_result' => 'IPN OK'
            ];
        } else if ($status < 0) {
            //payment error, this is usually final but payments will sometimes be reopened if there was no exchange rate conversion or with seller consent
            abort(500, 'Payment Timed Out or Error');
        } else {
            //payment is pending, you can optionally add a note to the order page
            return('IPN OK: pending');
        }
    }
}
