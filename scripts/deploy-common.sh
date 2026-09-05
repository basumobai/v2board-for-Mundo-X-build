#!/usr/bin/env bash

fail() { printf '%s\n' "$*" >&2; exit 1; }

require_docker() {
    command -v docker >/dev/null || fail '请先安装 Docker Engine 和 Compose 插件：https://docs.docker.com/engine/install/'
    local version major minor endpoint
    version=$(docker compose version --short) || fail '找不到 Docker Compose 插件。'
    [[ $version =~ ^v?([0-9]+)\.([0-9]+) ]] || fail '无法识别 Compose 版本。'
    major=${BASH_REMATCH[1]}; minor=${BASH_REMATCH[2]}
    (( major > 2 || (major == 2 && minor >= 20) )) || fail '需要 Docker Compose 2.20 或更新版本。'
    docker info >/dev/null 2>&1 || fail '无法访问 Docker Engine，请检查服务和当前用户权限。'
    endpoint=${DOCKER_HOST:-$(docker context inspect --format '{{.Endpoints.docker.Host}}')}
    [[ $endpoint == unix://* ]] || fail '安装器需要本机 Docker Engine；不支持远程 Docker context。'
}

prompt_setting() {
    local key=$1 label=$2 default=$3 answer
    if [[ -z ${!key:-} ]]; then
        if [[ -t 0 ]]; then
            read -r -p "$label [$default]：" answer
            printf -v "$key" '%s' "${answer:-$default}"
        else
            printf -v "$key" '%s' "$default"
        fi
    fi
    export "$key"
}

validate_integer() {
    local value=$1 max=$2 label=$3
    [[ $value =~ ^[1-9][0-9]*$ && ${#value} -le 8 ]] || fail "$label 必须是 1–$max 的整数。"
    (( value <= max )) || fail "$label 必须是 1–$max 的整数。"
}

port_available() {
    local listeners
    listeners=$(ss -H -ltn "sport = :$1") || fail '无法检查本机端口。'
    [[ -z $listeners ]]
}

available_port() {
    local port=$1 excluded=${2:-0}
    while (( port <= 65535 )); do
        if [[ $port != "$excluded" ]] && port_available "$port"; then
            printf '%s' "$port"
            return
        fi
        ((port += 1))
    done
    fail '没有找到空闲端口，请手动指定 WEB_PORT 和 GATEWAY_PORT。'
}

prepare_deployment() {
    command -v ss >/dev/null || fail '需要 ss 命令检查端口，请先安装 iproute2。'
    local default_name used
    default_name=$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')
    default_name="${default_name:-panel}-$(printf '%s' "$PWD" | cksum | cut -d ' ' -f 1)"
    prompt_setting COMPOSE_PROJECT_NAME '实例名（每个面板必须不同）' "$default_name"
    [[ $COMPOSE_PROJECT_NAME =~ ^[a-z0-9][a-z0-9_-]*$ ]] || fail '实例名只能包含小写字母、数字、下划线和短横线，且以字母或数字开头。'
    ((${#COMPOSE_PROJECT_NAME} <= 63)) || fail '实例名不能超过 63 个字符。'
    for kind in container volume network; do
        if [[ $kind == container ]]; then
            used=$(docker container ls -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")
        else
            used=$(docker "$kind" ls -q --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")
        fi
        [[ -z $used ]] || fail "实例名 $COMPOSE_PROJECT_NAME 已有关联 $kind，不能作为新实例使用。请选择不同实例名；已有安装不要重新运行安装器。"
    done
    prompt_setting WEB_PORT 'PHP Web 端口（仅本机）' "$(available_port 6600)"
    validate_integer "$WEB_PORT" 65535 WEB_PORT
    prompt_setting GATEWAY_PORT 'Nginx 入口端口（反向代理填这个）' "$(available_port 7001 "$WEB_PORT")"
    validate_integer "$GATEWAY_PORT" 65535 GATEWAY_PORT
    [[ $WEB_PORT != "$GATEWAY_PORT" ]] || fail 'WEB_PORT 和 GATEWAY_PORT 不能相同。'
    port_available "$WEB_PORT" || fail "WEB_PORT=$WEB_PORT 已占用，请换一个端口。"
    port_available "$GATEWAY_PORT" || fail "GATEWAY_PORT=$GATEWAY_PORT 已占用，请换一个端口。"
    prompt_setting WEB_WORKERS 'Web Worker 数量（多实例建议从 2 开始）' 2
    validate_integer "$WEB_WORKERS" 128 WEB_WORKERS
    prompt_setting HORIZON_MAX_PROCESSES '队列 Worker 上限' 4
    validate_integer "$HORIZON_MAX_PROCESSES" 128 HORIZON_MAX_PROCESSES
    export WEB_MAX_REQUESTS=${WEB_MAX_REQUESTS:-6600}
    validate_integer "$WEB_MAX_REQUESTS" 10000000 WEB_MAX_REQUESTS
    printf '实例 %s：Web=%s，入口=%s，Worker=%s\n' "$COMPOSE_PROJECT_NAME" "$WEB_PORT" "$GATEWAY_PORT" "$WEB_WORKERS"
}

finish_deployment() {
    docker compose up -d --wait --wait-timeout 120 redis
    docker compose run --rm -T --no-deps installer php artisan config:clear
    docker compose run --rm -T --no-deps installer php artisan view:cache
    docker compose up -d --wait --wait-timeout 180
    docker compose run --rm -T --no-deps installer php docker/healthcheck.php gateway
    docker compose ps
}
