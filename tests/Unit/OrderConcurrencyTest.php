<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\User\UserController;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\User\UserTransfer;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\CreatesSecurityDatabase;
use Tests\TestCase;

/** Real InnoDB locks and independent connections; SQLite cannot prove this. */
class OrderConcurrencyTest extends TestCase
{
    use CreatesSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('SECURITY_MYSQL_DATABASE') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires a dedicated MySQL test database and pcntl');
        }
        $this->createSecurityDatabase(true);
        config(['v2board.deposit_bounus' => []]);
        Queue::fake();
    }

    public function testSimultaneousCallbacksAndFulfilmentCreditDepositOnlyOnce(): void
    {
        $user = User::create(['balance' => 1000]);
        $order = Order::create(['user_id' => $user->id, 'payment_id' => 7, 'trade_no' => 'parallel-deposit']);
        $this->race(function () use ($order) {
            $service = new OrderService(Order::findOrFail($order->id));
            if (!$service->paid('gateway-parallel', 7, 500) || !$service->open()) throw new \RuntimeException('Settlement failed');
        });
        $this->assertSame(1500, (int) $user->fresh()->balance);
        $this->assertSame(3, (int) $order->fresh()->status);
    }

    public function testPaymentAndCancellationRaceNeverBothCreditAndRefund(): void
    {
        $user = User::create(['balance' => 1000]);
        $order = Order::create(['user_id' => $user->id, 'payment_id' => 7, 'trade_no' => 'parallel-cancel', 'balance_amount' => 250]);
        $this->race(function ($worker) use ($order) {
            $service = new OrderService(Order::findOrFail($order->id));
            if ($worker % 2 === 0) {
                $service->cancel();
            } elseif ($service->paid('gateway-parallel', 7, 500)) {
                $service->open();
            }
        });
        $status = (int) $order->fresh()->status;
        $this->assertContains($status, [2, 3]);
        $this->assertSame($status === 2 ? 1250 : 1500, (int) $user->fresh()->balance);
    }

    public function testConcurrentCommissionTransfersCannotOverspendOrLoseDepositCredit(): void
    {
        $user = User::create(['balance' => 1000, 'commission_balance' => 500]);
        $deposit = Order::create(['user_id' => $user->id, 'payment_id' => 7, 'trade_no' => 'transfer-concurrent-deposit']);
        $this->race(function ($worker) use ($user, $deposit) {
            if ($worker % 2 === 0) {
                $service = new OrderService(Order::findOrFail($deposit->id));
                if (!$service->paid('transfer-race', 7, 500) || !$service->open()) throw new \RuntimeException('Deposit failed');
                return;
            }
            $request = UserTransfer::create('/', 'POST', ['transfer_amount' => 300, 'user' => ['id' => $user->id]]);
            try {
                (new UserController())->transfer($request);
            } catch (HttpException $error) {
                if ($error->getMessage() !== __('Insufficient commission balance')) throw $error;
            }
        });
        $this->assertSame(200, (int) $user->fresh()->commission_balance);
        $this->assertSame(1800, (int) $user->fresh()->balance);
        $this->assertSame(1, Order::where('callback_no', '佣金划转 Commission transfer')->count());
        $this->assertSame(3, (int) $deposit->fresh()->status);
    }

    public function testPasswordResetCannotMatchAnotherMailboxUnderMysqlAccentCollation(): void
    {
        $original = password_hash('original-password', PASSWORD_DEFAULT);
        $user = User::create(['email' => 'person@gmáil.com', 'password' => $original]);
        $this->assertSame($user->id, User::where('email', 'person@gmail.com')->first()->id);
        $key = CacheKey::get('EMAIL_VERIFY_CODE', 'person@gmail.com');
        Cache::put($key, '123456', 600);
        $request = AuthForget::create('/', 'POST', ['email' => 'person@gmail.com', 'email_code' => '123456', 'password' => 'new-password']);
        try {
            (new AuthController())->forget($request);
            $this->fail('Reset changed another mailbox');
        } catch (HttpException $error) {
            $this->assertSame(__('Incorrect email verification code'), $error->getMessage());
        }
        $this->assertSame($original, $user->fresh()->password);
        $this->assertSame('123456', Cache::get($key));
    }

    private function race(callable $action): void
    {
        // Close inherited PDO handles before forking. Every worker reconnects.
        DB::disconnect('mysql');
        $gate = tempnam(sys_get_temp_dir(), 'v2board-race-');
        unlink($gate);
        $children = [];
        try {
            for ($worker = 0; $worker < 6; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) throw new \RuntimeException('Cannot fork a test worker');
                if ($pid === 0) {
                    try {
                        DB::reconnect('mysql');
                        $deadline = microtime(true) + 15;
                        while (!file_exists($gate)) {
                            if (microtime(true) >= $deadline) throw new \RuntimeException('Barrier timeout');
                            usleep(1000);
                        }
                        $action($worker);
                        DB::disconnect('mysql');
                        exit(0);
                    } catch (\Throwable $error) {
                        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            touch($gate);
            $failures = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) $failures[] = $pid;
            }
            $this->assertSame([], $failures, 'Concurrent worker failed');
        } finally {
            @unlink($gate);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }
    }
}
