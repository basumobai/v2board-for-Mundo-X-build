#!/usr/bin/env bash
set -euo pipefail

# Run only on an ephemeral CI runner. Never test against a production checkout.
[[ ${CI:-} == true && ! -e .env && ! -e config/v2board.php ]] || { echo 'Requires a fresh CI checkout'; exit 1; }
test_dir=$(mktemp -d)
test_suffix="${GITHUB_RUN_ID:-local}-$$"
mysql_name="mundo-ci-mysql-$test_suffix"
cleanup() {
    local result=$?
    for panel in a b; do
        if [[ -f $test_dir/$panel/.env ]]; then
            if (( result != 0 )); then
                (cd "$test_dir/$panel" && docker compose logs --tail=100 web gateway horizon) || true
            fi
            (cd "$test_dir/$panel" && docker compose down -v) || true
        fi
    done
    docker rm -f "$mysql_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d --name "$mysql_name" -e MYSQL_ROOT_PASSWORD=ci-only-password \
    -e MYSQL_ROOT_HOST=% -p 127.0.0.1::3306 mysql:8.0
ready=false
for ((i=0; i<90; i++)); do
    if docker exec -e MYSQL_PWD=ci-only-password "$mysql_name" mysql --protocol=TCP -h127.0.0.1 -uroot -e 'SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
    sleep 2
done
[[ $ready == true ]]
db_port=$(docker port "$mysql_name" 3306/tcp | cut -d: -f2)
docker exec -e MYSQL_PWD=ci-only-password "$mysql_name" mysql -uroot \
    -e 'CREATE DATABASE panel_a; CREATE DATABASE panel_b;'

for panel in a b; do
    mkdir "$test_dir/$panel"
    tar --exclude=.git --exclude=vendor -cf - . | tar -xf - -C "$test_dir/$panel"
    (
        cd "$test_dir/$panel"
        export COMPOSE_PROJECT_NAME="mundo-ci-$panel-$test_suffix"
        if [[ $panel == a ]]; then export WEB_PORT=16600 GATEWAY_PORT=17001; else export WEB_PORT=16601 GATEWAY_PORT=17002; fi
        export WEB_WORKERS=1 HORIZON_MAX_PROCESSES=2
        printf '%s\n' "http://panel-$panel.test" 127.0.0.1 "$db_port" "panel_$panel" root ci-only-password "admin@$panel.test" | bash init.sh
        curl -fsS "http://127.0.0.1:$GATEWAY_PORT/healthz"
        curl -fsS -o /dev/null "http://127.0.0.1:$GATEWAY_PORT/assets/admin/custom.css"
        curl -fsS -o /dev/null "http://127.0.0.1:$GATEWAY_PORT/"
        # Configuration and containers must remain stable after first install.
        before=$(sha256sum .env)
        if bash init.sh; then echo 'Unexpectedly allowed reinstall'; exit 1; fi
        [[ $(sha256sum .env) == "$before" ]]
        docker compose restart web horizon scheduler gateway
        docker compose up -d --wait --wait-timeout 180
        [[ $(sha256sum .env) == "$before" ]]
        docker compose run --rm -T --no-deps installer php docker/healthcheck.php gateway
    )
done

# Exercise traffic reporting against the real MySQL and Redis services while
# the scheduler is paused so the assertions cannot race its minute job.
(
    cd "$test_dir/a"
    docker compose stop scheduler
    docker compose exec -T web php tests/fixtures/TrafficPipelineProbe.php
    docker compose start scheduler
)

# Prove Redis volumes and production queue consumers are independent.
(cd "$test_dir/a" && docker compose exec -T redis redis-cli -s /data/redis.sock SET deployment-probe panel-a)
[[ $(cd "$test_dir/b" && docker compose exec -T redis redis-cli -s /data/redis.sock EXISTS deployment-probe) == 0 ]]
cp tests/fixtures/DeploymentQueueProbe.php "$test_dir/a/app/Jobs/DeploymentQueueProbe.php"
(
    cd "$test_dir/a"
    docker compose exec -T horizon php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Queue::connection("redis")->pushOn("stat", new App\Jobs\DeploymentQueueProbe());
    '
)
for ((i=0; i<30; i++)); do
    [[ -f $test_dir/a/storage/logs/deployment-queue-probe ]] && break
    sleep 2
done
[[ -f $test_dir/a/storage/logs/deployment-queue-probe && ! -f $test_dir/b/storage/logs/deployment-queue-probe ]]
printf 'Two-instance install, HTTP, restart, Redis isolation and production queue checks passed.\n'
