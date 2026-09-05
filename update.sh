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

git pull --ff-only
docker compose config --quiet
docker compose build --pull web
docker compose up -d --wait --wait-timeout 120 redis
# Do not replace bind-mounted vendor while long-running workers are using it.
docker compose stop web horizon scheduler
trap 'echo "更新未完成。配置与数据已保留，检查错误后恢复服务；请勿重新安装。" >&2' ERR
docker compose run --rm --no-deps installer composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction
docker compose run --rm --no-deps web php artisan v2board:update
finish_deployment
trap - ERR
