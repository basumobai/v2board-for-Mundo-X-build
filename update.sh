#!/usr/bin/env bash
set -euo pipefail

project_dir=$(dirname "$0")
case ${1:-} in
    --help|-h)
        printf '%s\n' '已有安装更新：bash update.sh' \
            '可指定项目：bash update.sh --project-dir /path/to/panel' \
            '自动备份源码/配置/主题/storage、MySQL 与 Redis；备份失败不会切换代码。' \
            'UPDATE_BACKUP_ROOT 可指定项目目录之外的备份位置。更新期间入口暂时不可用。'
        exit 0 ;;
    --project-dir) [[ $# == 2 ]] || { echo '--project-dir 需要项目路径' >&2; exit 1; }; project_dir=$2 ;;
    '') [[ $# == 0 ]] || exit 1 ;;
    *) echo '未知参数，请运行 bash update.sh --help。' >&2; exit 1 ;;
esac
cd "$project_dir"
project_dir=$(pwd -P)
# These existing functions also allow the new script to upgrade an older checkout.
# shellcheck source=scripts/deploy-common.sh
source scripts/deploy-common.sh
command -v flock >/dev/null || fail '需要 flock 命令（util-linux）。'
exec 9>.install.lock
flock -n 9 || fail '当前目录已有安装或更新任务。'
require_docker
[[ -s .env && -s config/v2board.php ]] || fail '没有完整的现有配置，全新安装请运行 bash init.sh。'
[[ -d .git || -f .git ]] || fail '当前目录不是 Git 仓库，无法安全更新。'
if [[ -n $(git status --porcelain) ]]; then
    echo '工作区存在未提交修改。请先提交或另行保存；不会覆盖你的定制。' >&2
    git status --short
    exit 1
fi
branch=$(git branch --show-current)
[[ -n $branch ]] || fail '当前是 detached HEAD，请先切换到要更新的分支。'
old_commit=$(git rev-parse HEAD)
git fetch origin "$branch"
git merge-base --is-ancestor HEAD FETCH_HEAD || fail '远端不是当前版本的快进更新。'
new_commit=$(git rev-parse FETCH_HEAD)
[[ $old_commit != "$new_commit" ]] || { echo '已经是最新版本。'; exit 0; }
docker compose config --quiet
# Pull backup tooling before taking the site offline. Never pass passwords in argv.
docker pull mysql:8.4 >/dev/null
backup_root=$(realpath -m "${UPDATE_BACKUP_ROOT:-$(dirname "$project_dir")/mundo-backups}")
case "$backup_root/" in "$project_dir/"*) fail '备份目录必须位于项目目录之外。' ;; esac
umask 077
mkdir -p "$backup_root"
backup_dir=$(mktemp -d "$backup_root/$(basename "$project_dir")-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")
services_stopped=false
code_switched=false
on_exit() {
    local status=$?
    trap - EXIT
    rm -f "$backup_dir/mysql-client.cnf" "$backup_dir/database-name"
    if (( status != 0 )); then
        printf '更新未完成，备份目录：%s\n' "$backup_dir" >&2
        if [[ $services_stopped == true && $code_switched == false ]]; then
            echo '源码尚未切换，正在恢复旧服务。' >&2
            docker compose up -d --wait --wait-timeout 180 redis web horizon scheduler gateway || \
                echo '旧服务恢复失败，请检查 docker compose ps/logs。' >&2
        elif [[ $code_switched == true ]]; then
            printf '源码已切换；请检查迁移错误，必要时按 docs/safe-update.md 恢复完整备份。旧提交：%s\n' "$old_commit" >&2
        fi
    fi
    exit "$status"
}
trap on_exit EXIT
printf '自动备份目录：%s\n' "$backup_dir"
# Read the effective database configuration from the old application, without
# printing credentials or storing them in command-line arguments.
docker compose run --rm -T --no-deps -v "$backup_dir:/backup" installer php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = config("database.connections." . config("database.default"));
    if (($db["driver"] ?? "") !== "mysql" || !empty($db["unix_socket"])) {
        fwrite(STDERR, "自动数据库备份要求 MySQL TCP 连接。请配置 TCP 或使用独立的已验证备份流程。\n");
        exit(1);
    }
    $name = $db["database"] ?? "";
    if (!$name || strpbrk($name, "\r\n\0") !== false) { exit(1); }
    $quote = static function ($value) {
        return "\"" . str_replace(["\\", "\"", "\n", "\r"], ["\\\\", "\\\"", "\\n", "\\r"], (string) $value) . "\"";
    };
    $host = $db["host"] ?? "127.0.0.1";
    if ($host === "localhost") { $host = "127.0.0.1"; }
    $ini = "[client]\nprotocol=tcp\n";
    foreach (["host" => $host, "port" => $db["port"] ?? 3306,
        "user" => $db["username"] ?? "", "password" => $db["password"] ?? ""] as $key => $value) {
        $ini .= $key . "=" . $quote($value) . "\n";
    }
    foreach ([PDO::MYSQL_ATTR_SSL_CA => "ssl-ca", PDO::MYSQL_ATTR_SSL_CERT => "ssl-cert", PDO::MYSQL_ATTR_SSL_KEY => "ssl-key"] as $attribute => $key) {
        if (!empty($db["options"][$attribute])) { $ini .= $key . "=" . $quote($db["options"][$attribute]) . "\n"; }
    }
    if (file_put_contents("/backup/mysql-client.cnf", $ini) === false ||
        file_put_contents("/backup/database-name", $name) === false) { exit(1); }
' </dev/null
database=$(cat "$backup_dir/database-name")
docker compose up -d --wait --wait-timeout 120 redis
# Stop producers before draining the two legacy queues. Reserved jobs are
# included in RedisQueue::size; Horizon is stopped only after they complete.
services_stopped=true
docker compose stop gateway web scheduler
for attempt in $(seq 1 120); do
    backlog=$(docker compose exec -T horizon php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo Illuminate\Support\Facades\Queue::connection("redis")->size("traffic_fetch")
            + Illuminate\Support\Facades\Queue::connection("redis")->size("stat");
    ')
    [[ $backlog =~ ^[0-9]+$ ]] || fail '无法核对旧流量队列。'
    if (( backlog == 0 )); then break; fi
    if (( attempt == 120 )); then fail "旧流量/统计队列仍有 $backlog 项。"; fi
    sleep 5
done
docker compose stop horizon
# A quiescent source/database/Redis snapshot is taken before any migration.
tar --exclude=./.git --exclude=./vendor --exclude=./node_modules --exclude=./.install.lock \
    -czf "$backup_dir/project.tar.gz" -C "$project_dir" .
docker run --rm --network host -v "$backup_dir:/backup:ro" -v "$project_dir:/www:ro" \
    --entrypoint mysqldump mysql:8.4 --defaults-extra-file=/backup/mysql-client.cnf \
    --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
    --column-statistics=0 --routines --events --triggers --hex-blob \
    --databases -- "$database" > "$backup_dir/database.sql.partial"
[[ -s "$backup_dir/database.sql.partial" ]] || fail '数据库备份为空。'
mv "$backup_dir/database.sql.partial" "$backup_dir/database.sql"
rm -f "$backup_dir/mysql-client.cnf" "$backup_dir/database-name"
docker compose exec -T redis redis-cli -s /data/redis.sock SAVE >/dev/null
docker compose stop redis
docker compose run --rm -T --no-deps -v "$backup_dir:/backup" web \
    tar --exclude=redis.sock -czf /backup/redis.tar.gz -C /data . </dev/null
tar -tzf "$backup_dir/project.tar.gz" >/dev/null
tar -tzf "$backup_dir/redis.tar.gz" >/dev/null
printf 'old_commit=%s\nnew_commit=%s\nbranch=%s\n' "$old_commit" "$new_commit" "$branch" > "$backup_dir/manifest.txt"
(cd "$backup_dir" && sha256sum project.tar.gz database.sql redis.tar.gz manifest.txt > SHA256SUMS)
touch "$backup_dir/COMPLETE"
# Backups deliberately use umask 077. Do not let that private-file policy leak
# into the Git checkout: Nginx must be able to traverse and read public assets.
umask 022
# Files absent from the commit (.env/config/theme/storage/custom themes) are not
# removed by a fast-forward merge. No reset --hard, clean, or volume removal.
git merge --ff-only "$new_commit"
code_switched=true
# Repair installations already affected by the leaked 077 umask. Everything in
# public is web-facing by definition; keep owner write access and grant Nginx
# traversal/read access without following symlinks.
find public -type d -exec chmod u+rwx,go+rx {} +
find public -type f -exec chmod u+rw,go+r {} +
docker compose config --quiet
docker compose build --pull web </dev/null
docker compose up -d --wait --wait-timeout 120 redis
docker compose run --rm -T --no-deps installer composer install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction </dev/null
docker compose run --rm -T --no-deps web php artisan v2board:update </dev/null
docker compose run --rm -T --no-deps web php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    app(App\Console\Commands\TrafficUpdate::class)->settleBeforeReset();
' </dev/null
finish_deployment
trap - EXIT
printf '更新完成，完整备份保留在：%s\n' "$backup_dir"
