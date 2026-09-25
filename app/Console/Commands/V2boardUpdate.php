<?php

namespace App\Console\Commands;

use App\Support\PerformanceSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class V2boardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 更新';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        \Artisan::call('config:clear');
        DB::connection()->getPdo();
        $file = \File::get(base_path() . '/database/update.sql');
        if (!$file) {
            abort(500, '数据库文件不存在');
        }
        $sql = str_replace("\n", "", $file);
        $sql = preg_split("/;/", $sql);
        if (!is_array($sql)) {
            abort(500, '数据库文件格式有误');
        }
        $this->info('正在导入数据库请稍等...');
        foreach ($sql as $item) {
            if (!$item) continue;
            try {
                DB::select(DB::raw($item));
            } catch (\Exception $e) {
                // The historical update file contains non-repeatable DDL.
                // Never mistake its best-effort pass for a verified migration.
                Log::warning('Historical update SQL was skipped', ['sql' => $item, 'exception' => $e]);
            }
        }
        try {
            PerformanceSchema::ensure();
        } catch (\Throwable $e) {
            $this->error('必要数据库迁移失败：' . $e->getMessage());
            Log::error('Performance schema migration failed', ['exception' => $e]);
            return 1;
        }
        \Artisan::call('horizon:terminate');
        $this->info('数据库迁移与校验完成。');
        return 0;
    }
}
