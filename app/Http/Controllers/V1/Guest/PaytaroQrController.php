<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PaytaroQrController extends Controller
{
    public function handle(Request $request)
    {
        $this->loadBridge();

        $uuid = (string) $request->query('uuid', '');
        $action = (string) $request->query('action', '');

        if ($action === 'status') {
            [$status, $body] = paytaro_qr_status_response($uuid);

            return response($body, $status, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        if ($action === '' || $action === 'page') {
            $statusUrl = 'pay.php?action=status&uuid=' . rawurlencode($uuid);
            [$status, $body] = paytaro_qr_page_response($uuid, $statusUrl);

            return response($body, $status, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        return response('not found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function loadBridge(): void
    {
        if (!defined('PAYTARO_QR_EMBED')) {
            define('PAYTARO_QR_EMBED', true);
        }

        require_once public_path('paytaro-qr/pay.php');
    }
}
