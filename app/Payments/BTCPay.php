<?php

namespace App\Payments;

use App\Support\PaymentCallback;
use Illuminate\Support\Facades\Http;

class BTCPay {
    private $config;
    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'btcpay_url' => [
                'label' => 'API接口所在网址(包含最后的斜杠)',
                'description' => '',
                'type' => 'input',
            ],
            'btcpay_storeId' => [
                'label' => 'storeId',
                'description' => '',
                'type' => 'input',
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'description' => '个人设置中的API KEY(非商店设置中的)',
                'type' => 'input',
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order) {

        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);

        $ret_raw = self::_curlPost($this->config['btcpay_url'] . 'api/v1/stores/' . $this->config['btcpay_storeId'] . '/invoices', $params_string);

        $ret = @json_decode($ret_raw, true);
        
        if(empty($ret['checkoutLink'])) {
            abort(500, "error!");
        }
        return [
            'type' => 1, // Redirect to url
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params) {
        if (!is_string($this->config['btcpay_webhook_key'] ?? null) || trim($this->config['btcpay_webhook_key']) === '') return false;
        $payload = request()->getContent();
        if (!is_string($payload) || $payload === '') return false;
        $signraturHeader = (string) request()->header('BTCPay-Sig', '');
        $json_param = json_decode($payload, true);

        $computedSignature = "sha256=" . \hash_hmac('sha256', $payload, $this->config['btcpay_webhook_key']);

        if (!self::hashEqual($signraturHeader, $computedSignature)) {
            abort(400, 'HMAC signature does not match');
            return false;
        }

        if (!is_array($json_param)) return false;
        if (($json_param['type'] ?? '') !== 'InvoiceSettled') return 'success';
        $invoiceId = $json_param['invoiceId'] ?? null;
        if (!is_string($invoiceId) || $invoiceId === '') return false;
        // Reconcile the signed event against the configured store's invoice.
        foreach (['btcpay_api_key', 'btcpay_storeId', 'btcpay_url'] as $field) {
            if (!is_string($this->config[$field] ?? null) || trim($this->config[$field]) === '') return false;
        }
        $response = Http::timeout(15)->withToken($this->config['btcpay_api_key'], 'token')->acceptJson()->get(
            rtrim($this->config['btcpay_url'], '/') . '/api/v1/stores/' . rawurlencode($this->config['btcpay_storeId']) . '/invoices/' . rawurlencode($invoiceId)
        );
        if (!$response->successful()) return false;
        $invoiceDetail = $response->json();
        if (!is_array($invoiceDetail) || ($invoiceDetail['status'] ?? '') !== 'Settled'
            || !in_array($invoiceDetail['additionalStatus'] ?? '', ['None', 'PaidOver', 'PaidLate', 'Marked'], true)
            || ($invoiceDetail['id'] ?? '') !== $invoiceId || ($invoiceDetail['currency'] ?? '') !== 'CNY') return false;
        $out_trade_no = $invoiceDetail['metadata']['orderId'] ?? null;
        $amount = PaymentCallback::amountMinor($invoiceDetail['amount'] ?? null);
        if (!is_string($out_trade_no) || $out_trade_no === '' || $amount === null) return false;
        return [
            'trade_no' => $out_trade_no,
            'callback_no' => $invoiceId,
            'amount_minor' => $amount,
        ];
    }


    private function _curlPost($url,$params=false){
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, array('Authorization:' .'token '.$this->config['btcpay_api_key'], 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }


    /**
     * @param string $str1
     * @param string $str2
     * @return bool
     */
    private function hashEqual($str1, $str2)
    {   

        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
    
}

