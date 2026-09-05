<?php

namespace Tests\Unit;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderServiceCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('balance')->default(0);
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('trade_no', 36)->unique();
            $table->string('callback_no')->nullable();
            $table->integer('balance_amount')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('paid_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Queue::fake();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function testPendingOrderCanBeCancelledAndBalanceIsRefunded(): void
    {
        [$user, $order] = $this->createOrder(0, 250);

        $this->assertTrue((new OrderService($order))->cancel());

        $this->assertSame(2, (int) $order->fresh()->status);
        $this->assertSame(1250, (int) $user->fresh()->balance);
    }

    public function testRepeatedCancellationCannotRefundBalanceTwice(): void
    {
        [$user, $order] = $this->createOrder(0, 250);
        $firstRequest = Order::findOrFail($order->id);
        $secondRequest = Order::findOrFail($order->id);

        $this->assertTrue((new OrderService($firstRequest))->cancel());
        $this->assertFalse((new OrderService($secondRequest))->cancel());
        $this->assertFalse((new OrderService($firstRequest))->cancel());

        $this->assertSame(2, (int) $order->fresh()->status);
        $this->assertSame(1250, (int) $user->fresh()->balance);
    }

    public function testPaidOrderCannotBeCancelledOrRefunded(): void
    {
        [$user, $order] = $this->createOrder(1, 250);

        $this->assertFalse((new OrderService($order))->cancel());

        $this->assertSame(1, (int) $order->fresh()->status);
        $this->assertSame(1000, (int) $user->fresh()->balance);
    }

    public function testPaymentWinningTheRacePreventsStaleCancellation(): void
    {
        [$user, $order] = $this->createOrder(0, 250);
        $paymentRequest = Order::findOrFail($order->id);
        $cancelRequest = Order::findOrFail($order->id);

        $this->assertTrue((new OrderService($paymentRequest))->paid('gateway-1'));
        $this->assertFalse((new OrderService($cancelRequest))->cancel());

        $storedOrder = $order->fresh();
        $this->assertSame(1, (int) $storedOrder->status);
        $this->assertSame('gateway-1', $storedOrder->callback_no);
        $this->assertSame(1000, (int) $user->fresh()->balance);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    public function testCancellationWinningTheRacePreventsStalePayment(): void
    {
        [$user, $order] = $this->createOrder(0, 250);
        $cancelRequest = Order::findOrFail($order->id);
        $paymentRequest = Order::findOrFail($order->id);

        $this->assertTrue((new OrderService($cancelRequest))->cancel());
        $this->assertFalse((new OrderService($paymentRequest))->paid('gateway-late'));

        $storedOrder = $order->fresh();
        $this->assertSame(2, (int) $storedOrder->status);
        $this->assertNull($storedOrder->callback_no);
        $this->assertSame(1250, (int) $user->fresh()->balance);
        Queue::assertNothingPushed();
    }

    public function testDuplicatePaymentCallbackIsIdempotent(): void
    {
        [, $order] = $this->createOrder(0, 0);
        $firstCallback = Order::findOrFail($order->id);
        $duplicateCallback = Order::findOrFail($order->id);

        $this->assertTrue((new OrderService($firstCallback))->paid('gateway-1'));
        $this->assertTrue((new OrderService($duplicateCallback))->paid('gateway-1'));

        $storedOrder = $order->fresh();
        $this->assertSame(1, (int) $storedOrder->status);
        $this->assertSame('gateway-1', $storedOrder->callback_no);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    public function testZeroAffectedRowsNeverRefundBalance(): void
    {
        [$user, $order] = $this->createOrder(2, 250);

        $this->assertFalse((new OrderService($order))->cancel());

        $this->assertSame(2, (int) $order->fresh()->status);
        $this->assertSame(1000, (int) $user->fresh()->balance);
    }

    private function createOrder(int $status, int $balanceAmount): array
    {
        $user = User::create([
            'balance' => 1000,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'trade_no' => uniqid('order-', true),
            'balance_amount' => $balanceAmount,
            'status' => $status,
        ]);

        return [$user, $order];
    }
}
