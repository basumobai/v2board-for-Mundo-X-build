<?php

// Synthetic accounts and orders, real AdapterMan HTTP and Horizon processing.
// CI checkout only; never run this probe on a live panel.
if (getenv('CI') !== 'true') throw new RuntimeException('Requires disposable CI deployment');
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Payment;
use App\Models\Order;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function checkRuntime(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function runtimeHttp(string $path, ?string $token = null, ?string $body = null, array $headers = []): array
{
    $curl = curl_init('http://127.0.0.1:' . (getenv('WEB_PORT') ?: '6600') . $path);
    if ($token !== null) $headers[] = 'Authorization: ' . $token;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    checkRuntime(is_string($response), 'HTTP probe failed: ' . $error);
    return [$status, $response];
}

$suffix = bin2hex(random_bytes(6));
$users = []; $payment = null; $order = null;
try {
    foreach ([1, 0] as $admin) {
        $user = User::create(['email' => 'runtime-' . $admin . '-' . $suffix . '@example.test',
            'password' => 'not-used', 'uuid' => (string) Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin, 'balance' => 1000]);
        $service = new AuthService($user);
        $users[] = [$user, $service, $service->generateAuthData(Request::create('/'))['auth_data']];
    }
    foreach ([$users[0], $users[1], $users[0]] as [$user, $service, $token]) {
        [$status, $body] = runtimeHttp('/api/v1/user/info', $token);
        checkRuntime($status === 200 && (json_decode($body, true)['data']['email'] ?? null) === $user->email,
            'Long-lived worker leaked authentication state or failed Laravel upgrade');
        foreach (['password', 'password_salt', 'password_algo'] as $secret) {
            checkRuntime(!array_key_exists($secret, json_decode($body, true)['data']), 'Sensitive user field exposed');
        }
    }
    $securePath = config('v2board.secure_path') ?: config('v2board.frontend_admin_path') ?: hash('crc32b', config('app.key'));
    [$status] = runtimeHttp('/api/v1/' . $securePath . '/payment/getPaymentMethods', $users[0][2]);
    checkRuntime($status === 200, 'Valid administrator route failed');
    DB::table('v2_user')->where('id', $users[0][0]->id)->update(['is_admin' => 0]);
    [$status] = runtimeHttp('/api/v1/' . $securePath . '/payment/getPaymentMethods', $users[0][2]);
    checkRuntime($status === 403, 'Cached administrator privileges survived revocation');
    DB::table('v2_user')->where('id', $users[1][0]->id)->update(['banned' => 1]);
    [$status] = runtimeHttp('/api/v1/user/info', $users[1][2]);
    checkRuntime($status === 403, 'Banned user retained access');

    $payment = Payment::create(['uuid' => 'probe-' . $suffix, 'name' => 'CI only', 'payment' => 'Coinbase',
        'enable' => 1, 'config' => ['coinbase_webhook_key' => 'ci-secret-' . $suffix]]);
    $order = Order::create(['user_id' => $users[0][0]->id, 'payment_id' => $payment->id,
        'trade_no' => 'probe-' . $suffix, 'plan_id' => 0, 'period' => 'deposit', 'type' => 9, 'total_amount' => 500, 'status' => 0]);
    $event = ['event' => ['id' => 'event-' . $suffix, 'type' => 'charge:created', 'data' => [
        'metadata' => ['outTradeNo' => $order->trade_no], 'pricing' => ['local' => ['amount' => '5.00', 'currency' => 'CNY']],
    ]]];
    $send = function (bool $valid = true) use (&$event, $payment, $suffix) {
        $body = " \n" . json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
        return runtimeHttp('/api/v1/guest/payment/notify/Coinbase/' . $payment->uuid, null, $body,
            ['X-Cc-Webhook-Signature: ' . ($valid ? hash_hmac('sha256', $body, 'ci-secret-' . $suffix) : 'invalid')]);
    };
    [$status] = $send(false);
    checkRuntime($status >= 400, 'Invalid webhook signature accepted');
    [$status] = $send();
    checkRuntime($status === 200 && (int) $order->fresh()->status === 0, 'Created event settled order');
    $event['event']['type'] = 'charge:confirmed';
    [$status] = $send();
    checkRuntime($status === 200, 'Confirmed raw-byte HMAC webhook rejected');
    for ($i = 0; $i < 40 && (int) $order->fresh()->status !== 3; $i++) usleep(250000);
    checkRuntime((int) $order->fresh()->status === 3, 'Horizon did not open paid deposit');
    checkRuntime((int) $users[0][0]->fresh()->balance === 1500, 'Deposit amount incorrect');
    for ($i = 0; $i < 3; $i++) {
        [$status] = $send();
        checkRuntime($status === 200, 'Duplicate notification was not acknowledged');
    }
    checkRuntime((int) $users[0][0]->fresh()->balance === 1500, 'Duplicate notification credited twice');
    echo "AdapterMan identity isolation, live revocation, raw HMAC and Horizon deposit idempotency passed.\n";
} finally {
    if ($order) $order->delete();
    if ($payment) $payment->delete();
    foreach ($users as [$user, $service]) { $service->removeAllSession(); $user->delete(); }
}
