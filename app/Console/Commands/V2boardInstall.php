<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use App\Models\User;
use App\Services\RuntimeConfigService;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class V2boardInstall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 安装';

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
        try {
            $this->info("__     ______  ____                      _  ");
            $this->info("\ \   / /___ \| __ )  ___   __ _ _ __ __| | ");
            $this->info(" \ \ / /  __) |  _ \ / _ \ / _` | '__/ _` | ");
            $this->info("  \ V /  / __/| |_) | (_) | (_| | | | (_| | ");
            $this->info("   \_/  |_____|____/ \___/ \__,_|_|  \__,_| ");
            if (\File::exists(base_path() . '/.env')) {
                $securePath = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
                $this->info("访问 http(s)://你的站点/{$securePath} 进入管理面板，你可以在用户中心修改你的密码。");
                abort(500, '如需重新安装请删除目录下.env文件');
            }

            if (!copy(base_path() . '/.env.example', base_path() . '/.env')) {
                abort(500, '复制环境文件失败，请检查目录权限');
            }

            $appUrl = $this->normalizeAppUrl((string)$this->ask(
                '请输入面板完整网址（例如：https://panel.example.com）'
            ));
            $environment = [
                'APP_KEY' => 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC')),
                'APP_URL' => $appUrl,
                'DB_HOST' => $this->ask('请输入数据库地址（默认:127.0.0.1）', '127.0.0.1'),
                'DB_DATABASE' => $this->ask('请输入数据库名'),
                'DB_USERNAME' => $this->ask('请输入数据库用户名'),
                'DB_PASSWORD' => (string)$this->secret('请输入数据库密码（输入不会显示）')
            ];
            $this->saveToEnv($environment);
            \Artisan::call('config:clear');
            config([
                'app.key' => $environment['APP_KEY'],
                'app.url' => $environment['APP_URL'],
                'database.connections.mysql.host' => $environment['DB_HOST'],
                'database.connections.mysql.database' => $environment['DB_DATABASE'],
                'database.connections.mysql.username' => $environment['DB_USERNAME'],
                'database.connections.mysql.password' => $environment['DB_PASSWORD'],
            ]);
            app(RuntimeConfigService::class)->saveV2boardConfig([
                'app_url' => $environment['APP_URL'],
                'force_https' => parse_url($environment['APP_URL'], PHP_URL_SCHEME) === 'https' ? 1 : 0,
            ]);
            DB::purge(config('database.default'));
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                abort(500, '数据库连接失败');
            }

            if (count(DB::select('SHOW TABLES')) > 0) {
                abort(500, '数据库不是空库，为避免覆盖现有数据，安装已停止');
            }

            $file = \File::get(base_path() . '/database/install.sql');
            if (!$file) {
                abort(500, '数据库文件不存在');
            }
            $statements = preg_split('/;\s*(?:\r?\n|$)/', $file);
            if (!is_array($statements)) {
                abort(500, '数据库文件格式有误');
            }
            $this->info('正在导入数据库请稍等...');
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }

                DB::unprepared($statement);
            }
            $this->info('数据库导入完成');
            $email = '';
            while (!$email) {
                $email = $this->ask('请输入管理员邮箱?');
            }
            $password = Helper::guid(false);
            if (!$this->registerAdmin($email, $password)) {
                abort(500, '管理员账号注册失败，请重试');
            }

            $this->info('一切就绪');
            $this->info("管理员邮箱：{$email}");
            $this->info("管理员密码：{$password}");

            $defaultSecurePath = hash('crc32b', config('app.key'));
            $this->info("访问 http(s)://你的站点/{$defaultSecurePath} 进入管理面板，你可以在用户中心修改你的密码。");
            return 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function registerAdmin($email, $password)
    {
        $user = new User();
        $user->email = $email;
        if (strlen($password) < 8) {
            abort(500, '管理员密码长度最小为8位字符');
        }
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;
        return $user->save();
    }

    private function normalizeAppUrl(string $appUrl): string
    {
        $appUrl = rtrim(trim($appUrl), '/');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);

        if (!filter_var($appUrl, FILTER_VALIDATE_URL)
            || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('面板网址无效，必须填写包含 http:// 或 https:// 的完整网址');
        }

        return $appUrl;
    }

    private function saveToEnv(array $data = []): bool
    {
        $envPath = app()->environmentFilePath();
        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException('无法读取 .env 文件');
        }

        foreach ($data as $key => $value) {
            $key = strtoupper((string)$key);
            $line = $key . '=' . $this->formatEnvValue((string)$value);
            $pattern = '/^' . preg_quote($key, '/') . '=[^\r\n]*/m';

            if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                $match = $matches[0];
                $contents = substr_replace($contents, $line, $match[1], strlen($match[0]));
            } else {
                $contents = rtrim($contents, "\r\n") . PHP_EOL . $line . PHP_EOL;
            }
        }

        if (file_put_contents($envPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('无法写入 .env 文件');
        }

        return true;
    }

    private function formatEnvValue(string $value): string
    {
        $escaped = str_replace(
            ["\\", '"', "\r", "\n", '$'],
            ["\\\\", '\\"', '', '\\n', '\\$'],
            $value
        );

        return '"' . $escaped . '"';
    }
}
