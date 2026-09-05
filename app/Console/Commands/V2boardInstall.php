<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use App\Models\User;
use App\Services\RuntimeConfigService;
use App\Support\DeploymentSettings;
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
            if (\File::exists(base_path() . '/.env') || is_file(base_path('config/v2board.php'))) {
                $securePath = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
                $this->info("访问 http(s)://你的站点/{$securePath} 进入管理面板，你可以在用户中心修改你的密码。");
                abort(500, '检测到已有配置，禁止重新安装。请使用更新或迁移流程，不要删除 .env');
            }

            $deployment = DeploymentSettings::fromEnvironment();
            $appUrl = $this->normalizeAppUrl((string)$this->ask(
                '请输入面板完整网址（例如：https://panel.example.com）'
            ));
            $environment = [
                'APP_KEY' => 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC')),
                'APP_URL' => $appUrl,
                'DB_HOST' => $this->ask('请输入数据库地址（默认:127.0.0.1）', '127.0.0.1'),
                'DB_PORT' => DeploymentSettings::positiveInteger('DB_PORT', $this->ask('请输入数据库端口', '3306'), 65535),
                'DB_DATABASE' => $this->ask('请输入数据库名'),
                'DB_USERNAME' => $this->ask('请输入数据库用户名'),
                'DB_PASSWORD' => (string)$this->secret('请输入数据库密码（输入不会显示）'),
                'SESSION_SECURE_COOKIE' => parse_url($appUrl, PHP_URL_SCHEME) === 'https' ? 'true' : 'false',
                'HORIZON_MAX_PROCESSES' => DeploymentSettings::positiveInteger('HORIZON_MAX_PROCESSES', getenv('HORIZON_MAX_PROCESSES') ?: 4, 128),
            ] + $deployment;
            foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $field) {
                $environment[$field] = trim((string)$environment[$field]);
                if ($environment[$field] === '') {
                    throw new RuntimeException("{$field} 不能为空");
                }
            }
            $email = trim((string)$this->ask('请输入管理员邮箱?'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('管理员邮箱格式无效');
            }
            $project = getenv('COMPOSE_PROJECT_NAME');
            if ($project !== false && $project !== '') {
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $project)) {
                    throw new RuntimeException('COMPOSE_PROJECT_NAME 格式无效');
                }
                $environment += [
                    'COMPOSE_PROJECT_NAME' => $project,
                    'REDIS_PREFIX' => $project . '_database_',
                    'CACHE_PREFIX' => $project . '_cache_',
                    'HORIZON_PREFIX' => $project . '_horizon:',
                    'SESSION_COOKIE' => $project . '_session',
                ];
            }
            // No files have been written yet. Invalid input, occupied ports or
            // a wrong/nonempty database can be corrected by rerunning init.sh.
            DeploymentSettings::assertPortsAvailable([$deployment['WEB_PORT'], $deployment['GATEWAY_PORT']]);
            \Artisan::call('config:clear');
            config([
                'app.key' => $environment['APP_KEY'],
                'app.url' => $environment['APP_URL'],
                'database.connections.mysql.host' => $environment['DB_HOST'],
                'database.connections.mysql.port' => $environment['DB_PORT'],
                'database.connections.mysql.database' => $environment['DB_DATABASE'],
                'database.connections.mysql.username' => $environment['DB_USERNAME'],
                'database.connections.mysql.password' => $environment['DB_PASSWORD'],
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
            // Exclusive creation prevents concurrent installers overwriting a
            // configuration after both passed the initial existence check.
            $envFile = @fopen(base_path('.env'), 'x');
            if ($envFile === false) {
                throw new RuntimeException('无法新建 .env（文件已存在或目录不可写），安装已停止');
            }
            fclose($envFile);
            chmod(base_path('.env'), 0600);
            // The container may run as root while Docker Compose on the host
            // runs as the checkout owner. Keep .env private AND readable by
            // that owner without recursively chowning the user's repository.
            $owner = fileowner(base_path());
            if ($owner !== false && fileowner(base_path('.env')) !== $owner
                && !@chown(base_path('.env'), $owner)) {
                throw new RuntimeException('无法将 .env 交给项目目录所有者，未导入数据库');
            }
            $template = file_get_contents(base_path('.env.example'));
            if ($template === false || file_put_contents(base_path('.env'), $template, LOCK_EX) === false) {
                throw new RuntimeException('无法写入 .env 模板，未导入数据库');
            }
            $this->saveToEnv($environment);
            app(RuntimeConfigService::class)->saveV2boardConfig([
                'app_url' => $environment['APP_URL'],
                'force_https' => parse_url($environment['APP_URL'], PHP_URL_SCHEME) === 'https' ? 1 : 0,
            ]);
            $this->info('正在导入数据库请稍等...');
            foreach ($statements as $statement) {
                $statement = trim($statement);
                // Never drop a table, even if another installer races the
                // empty-database check. CREATE will safely fail in that case.
                if ($statement === '' || preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\b/i', $statement)) {
                    continue;
                }

                DB::unprepared($statement);
            }
            $this->info('数据库导入完成');
            $password = Helper::guid(false);
            if (!$this->registerAdmin($email, $password)) {
                abort(500, '管理员账号注册失败，请重试');
            }

            $this->info('一切就绪');
            $this->info("管理员邮箱：{$email}");
            $this->info("管理员密码：{$password}");

            $defaultSecurePath = hash('crc32b', config('app.key'));
            $this->info("后台地址：{$appUrl}/{$defaultSecurePath}");
            $this->info('请立即保存管理员信息，登录后更换初始密码。');
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
            || !in_array($scheme, ['http', 'https'], true)
            || !empty(parse_url($appUrl, PHP_URL_USER))
            || !empty(parse_url($appUrl, PHP_URL_PASS))
            || !empty(parse_url($appUrl, PHP_URL_QUERY))
            || !empty(parse_url($appUrl, PHP_URL_FRAGMENT))
            || !in_array(parse_url($appUrl, PHP_URL_PATH), [null, '', '/'], true)) {
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
