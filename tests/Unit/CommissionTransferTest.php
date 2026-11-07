<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\User\UserController;
use App\Http\Requests\User\UserTransfer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesSecurityDatabase;
use Tests\TestCase;

class CommissionTransferTest extends TestCase
{
    use CreatesSecurityDatabase;

    public function testInsufficientTransferPreservesBalancesAndDoesNotCreateAnOrder(): void
    {
        $this->createSecurityDatabase();
        $user = User::create(['balance' => 1000, 'commission_balance' => 500]);
        $request = UserTransfer::create('/', 'POST', ['transfer_amount' => 300, 'user' => ['id' => $user->id]]);
        $controller = new UserController();
        $this->assertSame(200, $controller->transfer($request)->getStatusCode());
        try {
            $controller->transfer($request);
            $this->fail('Overspent commission');
        } catch (HttpException $error) {
            $this->assertSame(__('Insufficient commission balance'), $error->getMessage());
        }
        $this->assertSame(1300, (int) $user->fresh()->balance);
        $this->assertSame(200, (int) $user->fresh()->commission_balance);
        $this->assertSame(1, Order::count());
        $this->assertSame(300, (int) Order::first()->surplus_amount);
        $this->assertSame(0, DB::transactionLevel());
    }
}
