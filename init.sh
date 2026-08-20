#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")"

if test -f .env; then
    echo '.env 已存在。项目可能已经安装，为避免覆盖现有站点，本脚本已停止。'
    exit 1
fi

docker compose config --quiet
docker compose build --pull
docker compose up -d redis
docker compose exec redis redis-cli -s /data/redis.sock ping
docker compose run --rm web composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction
docker compose run --rm web php artisan v2board:install

if ! test -f .env; then
    echo '安装器没有生成 .env，请检查上方错误后重试。'
    exit 1
fi

docker compose run --rm web php artisan config:clear
docker compose up -d
docker compose ps
