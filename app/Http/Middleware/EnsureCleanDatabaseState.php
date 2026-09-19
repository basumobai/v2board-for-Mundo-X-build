<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

class EnsureCleanDatabaseState
{
    private $database;

    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    public function handle($request, Closure $next)
    {
        $connection = $this->database->connection();
        $disconnect = $this->rollbackLeakedTransactions($connection, $request, 'request_start');

        try {
            $response = $next($request);

            if ($connection->transactionLevel() > 0) {
                $depth = $connection->transactionLevel();
                $this->rollbackAll($connection);
                $disconnect = true;
                Log::error('Database transaction was left open by a request', [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'transaction_depth' => $depth,
                ]);

                abort(500, '数据库事务状态异常，请重试');
            }

            return $response;
        } catch (\Throwable $exception) {
            $disconnect = $this->rollbackLeakedTransactions($connection, $request, 'request_exception')
                || $disconnect;
            throw $exception;
        } finally {
            // Keep healthy persistent-worker connections alive. Reconnecting on
            // every request defeats AdapterMan's long-lived worker model.
            if ($disconnect) {
                $this->database->disconnect($connection->getName());
            }
        }
    }

    private function rollbackLeakedTransactions($connection, $request, string $stage): bool
    {
        $depth = $connection->transactionLevel();
        if ($depth === 0) {
            return false;
        }

        $this->rollbackAll($connection);
        Log::warning('Recovered leaked database transaction', [
            'stage' => $stage,
            'method' => $request->method(),
            'path' => $request->path(),
            'transaction_depth' => $depth,
        ]);

        return true;
    }

    private function rollbackAll($connection): void
    {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
}
