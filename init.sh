#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# shellcheck source=scripts/deploy-common.sh
source scripts/deploy-common.sh

case ${1:-} in
    --help|-h)
        printf '%s\n' '首次安装：bash init.sh' \
            '可预设：COMPOSE_PROJECT_NAME=panel-b WEB_PORT=6601 GATEWAY_PORT=7002 bash init.sh' \
            '已有 .env 时不会重装或覆盖数据。无需宿主机 PHP/Composer；需要本机 Docker、ss 和空 MySQL 数据库。'
        exit 0 ;;
    '') ;;
    *) fail '未知参数，请运行 bash init.sh --help。' ;;
esac

[[ ! -e .env && ! -e config/v2board.php ]] || fail '发现已有 .env 或面板配置，已停止首次安装。请使用更新流程；不要删除配置重装。'
command -v flock >/dev/null || fail '需要 flock 命令（util-linux）。'
exec 9>.install.lock
flock -n 9 || fail '当前目录已有安装器正在运行。'
require_docker
prepare_deployment

trap 'printf "安装未完成，未自动删除配置或数据。请查看上方错误；若数据库已导入，请勿删除 .env 重新安装。\n" >&2' ERR
docker compose config --quiet
printf '\n[1/4] 构建 PHP 运行环境（首次可能需要几分钟）\n'
docker compose build --pull web
printf '\n[2/4] 按锁文件安装依赖\n'
docker compose run --rm -T --no-deps installer composer install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction
printf '\n[3/4] 配置站点并初始化空数据库\n'
terminal_args=()
if [[ ! -t 0 || ! -t 1 ]]; then terminal_args=(-T); fi
docker compose run --rm "${terminal_args[@]}" --no-deps installer php artisan v2board:install
[[ -s .env && -s config/v2board.php ]] || fail '安装器未生成完整配置，已停止启动。'
printf '\n[4/4] 启动服务并检查健康状态\n'
finish_deployment
trap - ERR
printf '\n安装完成。宝塔/Nginx 的反向代理目标：http://127.0.0.1:%s\n' "$GATEWAY_PORT"
printf '端口只对本机开放；请通过自己的域名配置 HTTPS，并更换管理员初始密码。\n'
