<?php

namespace App\Payments;

use \Curl\Curl;
use App\Support\PaymentCallback;

class BEasyPaymentUSDT {
    private $config;
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'bepusdt_url' => [
                'label' => 'API 地址',
                'description' => '您的 BEPUSDT API 接口地址(例如: https://xxx.com)',
                'type' => 'input',
            ],
            'bepusdt_apitoken' => [
                'label' => 'API Token',
                'description' => '您的 BEPUSDT API Token',
                'type' => 'input',
            ],
            'bepusdt_trade_type' => [
                'label' => '交易类型',
                'description' => '您的 BEPUSDT 交易类型',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'amount' => $order['total_amount'] / 100,
            'trade_type' => $this->config['bepusdt_trade_type'],
            'notify_url' => $order['notify_url'],
            'order_id' => $order['trade_no'],
            'redirect_url' => $order['return_url']
        ];
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['bepusdt_apitoken'];
        $params['signature'] = md5($str);

        $curl = new Curl();
        $curl->setUserAgent('BEPUSDT');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOpt(CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $curl->post($this->config['bepusdt_url'] . '/api/v1/order/create-transaction', json_encode($params));
        $result = $curl->response;
        $curl->close();

        if (!isset($result->status_code) || $result->status_code != 200) {
            abort(500, "Failed to create order. Error: {$result->message}");
        }

        $paymentURL = $result->data->payment_url;
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $paymentURL
        ];
    }

    public function notify($params)
    {
        if (trim((string) ($this->config['bepusdt_apitoken'] ?? '')) === ''
            || !PaymentCallback::isFlatPayload($params) || !is_string($params['signature'] ?? null)) return false;
        $sign = $params['signature'];
        unset($params['signature']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['bepusdt_apitoken'];
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            return false;
        }
        $status = $params['status'] ?? null;
        // 1: pending 2: success 3: expired
        if ($status != 2) {
            return false;
        }
        if (empty($params['order_id']) || empty($params['trade_id'])) return false;
        $verified = [
            'trade_no' => $params['order_id'],
            'callback_no' => $params['trade_id'],
            'custom_result' => 'ok'
        ];
        if (array_key_exists('amount', $params)) {
            $amount = PaymentCallback::amountMinor($params['amount']);
            if ($amount === null) return false;
            $verified['amount_minor'] = $amount;
        }
        return $verified;
    }
}
