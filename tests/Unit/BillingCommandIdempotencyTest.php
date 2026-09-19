<?php

namespace Tests\Unit;

use App\Console\Commands\CheckCommission;
use App\Console\Commands\CheckRenewal;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingCommandIdempotencyTest extends TestCase
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
            'v2board.commission_auto_check_enable' => 0,
            'v2board.commission_distribution_enable' => 0,
            'v2board.withdraw_close_enable' => 0,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function testCommissionCannotBePaidTwice(): void
    {
        $inviter = User::create(['balance' => 0, 'commission_balance' => 0]);
        $buyer = User::create(['invite_user_id' => $inviter->id]);
        $order = Order::create([
            'invite_user_id' => $inviter->id,
            'user_id' => $buyer->id,
            'trade_no' => 'commission-order',
            'total_amount' => 1000,
            'commission_balance' => 100,
            'commission_status' => 1,
            'status' => 3,
        ]);

        $command = new CheckCommission();
        $command->autoPayCommission();
        $command->autoPayCommission();

        $this->assertSame(100, (int)$inviter->fresh()->commission_balance);
        $this->assertSame(2, (int)$order->fresh()->commission_status);
        $this->assertSame(100, (int)$order->fresh()->actual_commission_balance);
        $this->assertSame(1, DB::table('v2_commission_log')->count());
    }

    public function testRenewalCannotChargeTheSameExpiryTwice(): void
    {
        $plan = Plan::create([
            'renew' => 1,
            'month_price' => 100,
        ]);
        $originalExpiry = time() + 86400;
        $user = User::create([
            'plan_id' => $plan->id,
            'auto_renewal' => 1,
            'expired_at' => $originalExpiry,
            'balance' => 500,
        ]);
        Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'trade_no' => 'original-order',
            'total_amount' => 100,
            'status' => 3,
        ]);

        $command = new CheckRenewal();
        $command->handle();
        $command->handle();

        $renewedUser = $user->fresh();
        $this->assertSame(400, (int)$renewedUser->balance);
        $this->assertGreaterThan($originalExpiry + 20 * 86400, (int)$renewedUser->expired_at);
        $this->assertSame(2, Order::where('user_id', $user->id)->count());
        $this->assertSame(1, Order::where('user_id', $user->id)->where('type', 2)->count());
    }

    private function createSchema(): void
    {
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('invite_user_id')->nullable();
            $table->integer('balance')->default(0);
            $table->integer('commission_balance')->default(0);
            $table->unsignedInteger('plan_id')->nullable();
            $table->unsignedTinyInteger('auto_renewal')->default(0);
            $table->bigInteger('expired_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedTinyInteger('renew')->default(1);
            $table->integer('month_price')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('invite_user_id')->nullable();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('plan_id')->default(1);
            $table->unsignedTinyInteger('type')->default(1);
            $table->string('period')->default('month_price');
            $table->string('trade_no', 36)->unique();
            $table->integer('total_amount')->default(0);
            $table->integer('balance_amount')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedTinyInteger('commission_status')->default(0);
            $table->integer('commission_balance')->default(0);
            $table->integer('actual_commission_balance')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_commission_log', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('invite_user_id');
            $table->unsignedInteger('user_id');
            $table->string('trade_no', 36);
            $table->integer('order_amount');
            $table->integer('get_amount');
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['trade_no', 'invite_user_id']);
        });
    }
}
