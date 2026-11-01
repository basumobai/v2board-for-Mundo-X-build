<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesSecurityDatabase
{
    protected function createSecurityDatabase(bool $mysql = false): void
    {
        $this->clearGuardableColumnCache();
        $connection = 'sqlite';
        $settings = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        if ($mysql) {
            $database = getenv('SECURITY_MYSQL_DATABASE');
            if (!is_string($database) || strpos($database, 'v2board_test_') !== 0) {
                throw new \RuntimeException('Security tests require a dedicated v2board_test_* database');
            }
            $connection = 'mysql';
            $settings = [
                'driver' => 'mysql', 'host' => getenv('SECURITY_MYSQL_HOST') ?: '127.0.0.1',
                'port' => getenv('SECURITY_MYSQL_PORT') ?: '3306', 'database' => $database,
                'username' => getenv('SECURITY_MYSQL_USER') ?: 'root',
                'password' => getenv('SECURITY_MYSQL_PASSWORD') ?: '',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            ];
        }
        config(['database.default' => $connection, 'database.connections.' . $connection => $settings]);
        DB::purge($connection);
        DB::reconnect($connection);
        foreach (['v2_order', 'v2_user', 'v2_payment'] as $table) Schema::dropIfExists($table);
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->default('user@example.test');
            foreach (['password', 'password_salt', 'password_algo', 'token', 'remember_token'] as $field) $table->string($field)->nullable();
            foreach (['is_admin', 'is_staff', 'banned', 'balance', 'plan_id', 'group_id', 'speed_limit', 'device_limit', 'u', 'd', 'transfer_enable'] as $field) $table->bigInteger($field)->default(0);
            $table->integer('expired_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_payment', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('payment');
            $table->text('config');
            $table->integer('enable')->default(1);
            $table->string('notify_domain')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('payment_id')->nullable();
            $table->string('trade_no', 36)->unique();
            $table->string('callback_no')->nullable();
            $table->integer('type')->default(9);
            $table->integer('total_amount')->default(500);
            $table->integer('handling_amount')->nullable();
            $table->integer('balance_amount')->nullable();
            $table->integer('status')->default(0);
            $table->integer('paid_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    protected function tearDown(): void
    {
        // Other suites deliberately use smaller table schemas. Eloquent caches
        // their guardable columns by model class across application instances.
        $this->clearGuardableColumnCache();
        DB::disconnect();
        parent::tearDown();
    }

    private function clearGuardableColumnCache(): void
    {
        $property = new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
