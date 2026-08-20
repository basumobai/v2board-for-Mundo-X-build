#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")"

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
docker compose build --pull
docker compose up -d redis
docker compose run --rm web composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction
docker compose run --rm web php artisan v2board:update
docker compose run --rm web php artisan config:clear
docker compose up -d
docker compose ps
