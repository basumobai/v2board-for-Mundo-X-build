#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")"
# shellcheck source=scripts/deploy-common.sh
source scripts/deploy-common.sh
require_docker

if ! test -f .env; then
    fail '没有找到 .env，全新安装请运行 bash init.sh。'
fi

if ! test -d .git; then
    echo '当前目录不是 Git 仓库，无法安全更新。'
    exit 1
fi

if test -n "$(git status --porcelain)"; then
    echo '工作区存在未提交修改。请先检查、提交或备份，更新已停止。'
    git status --short
    exit 1
fi

echo '继续前请确认数据库、.env、config/v2board.php 和主题配置已经备份。'

docker compose config --quiet
docker compose up -d --wait --wait-timeout 120 redis
# Fetch without changing files used by long-running PHP workers.
git fetch origin "$(git branch --show-current)"
git merge-base --is-ancestor HEAD FETCH_HEAD || fail '远端不是当前版本的快进更新。'

trap 'echo "更新未完成。请检查迁移错误与队列状态；不要在旧进程上启动新代码。" >&2' ERR
# Stop all producers and the old scheduler; let the old queue consumers finish
# their three-job traffic reports before switching the bind-mounted source.
docker compose stop gateway web scheduler
for attempt in $(seq 1 120); do
    backlog=$(docker compose exec -T horizon php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo Illuminate\Support\Facades\Queue::connection("redis")->size("traffic_fetch")
            + Illuminate\Support\Facades\Queue::connection("redis")->size("stat");
    ')
    [[ $backlog =~ ^[0-9]+$ ]] || fail '无法核对旧流量队列，更新已停止。'
    if (( backlog == 0 )); then break; fi
    if (( attempt == 120 )); then fail "旧流量/统计队列仍有 $backlog 项，更新已停止。"; fi
    sleep 5
done
docker compose stop horizon
git merge --ff-only FETCH_HEAD
docker compose config --quiet
docker compose build --pull web
docker compose up -d --wait --wait-timeout 120 redis
trap 'echo "更新未完成。配置与数据已保留，检查错误后恢复服务；请勿重新安装。" >&2' ERR
docker compose run --rm --no-deps installer composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction
docker compose run --rm --no-deps web php artisan v2board:update
# No old producer/consumer is running. Settle the legacy Redis batches before
# users can buy a plan or manually reset traffic on the new web service.
docker compose run --rm --no-deps web php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    app(App\Console\Commands\TrafficUpdate::class)->settleBeforeReset();
'
finish_deployment
trap - ERR
