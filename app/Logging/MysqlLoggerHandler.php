<?php
namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;
use App\Support\SensitiveData;
use Monolog\LogRecord;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try{
            $log = [
                'title' => $record->message,
                'level' => $record->level->getName(),
                'host' => request()->getSchemeAndHttpHost(),
                // Authentication may be in the query string; store the path.
                'uri' => '/' . request()->path(),
                'method' => request()->getMethod(),
                'ip' => request()->getClientIp(),
                'data' => json_encode(SensitiveData::redact(request()->all())),
                'context' => json_encode(SensitiveData::redact($record->context)),
                'created_at' => $record->datetime->getTimestamp(),
                'updated_at' => $record->datetime->getTimestamp(),
            ];

            LogModel::insert(
                $log
            );
        }catch (\Throwable $e){
            Log::channel('daily')->error('Database log write failed', ['exception' => get_class($e)]);
        }
    }
}
