<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            // Some adapters acknowledge non-settlement events with plain text.
            // Such acknowledgements must never enter the paid-order path.
            if (is_string($verify)) return $verify;
            if (!is_array($verify)) abort(500, 'verify error');
            foreach (['trade_no', 'callback_no'] as $field) {
                if (!isset($verify[$field]) || (!is_string($verify[$field]) && !is_int($verify[$field]))
                    || trim((string) $verify[$field]) === '') abort(500, 'verify error');
            }
            if (array_key_exists('amount_minor', $verify) && !is_int($verify['amount_minor'])) abort(500, 'verify error');
            if (!$this->handle($verify, $paymentService->getPaymentId())) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Throwable $e) {
            abort(500, 'fail');
        }
    }

    private function handle(array $verify, int $paymentId)
    {
        $order = Order::where('trade_no', (string) $verify['trade_no'])->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        $alreadyPaid = in_array((int) $order->status, [1, 3], true);
        $orderService = new OrderService($order);
        if (!$orderService->paid((string) $verify['callback_no'], $paymentId, $verify['amount_minor'] ?? null)) {
            return false;
        }
        if ($alreadyPaid) return true;
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
