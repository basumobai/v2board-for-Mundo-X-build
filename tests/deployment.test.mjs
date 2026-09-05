import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const common = 'set -euo pipefail; source scripts/deploy-common.sh; ';
const cleanEnv = Object.fromEntries(Object.entries(process.env).filter(([key]) =>
  !/^(COMPOSE_|WEB_|GATEWAY_|HORIZON_|DOCKER_)/.test(key)));
function bash(script, env = {}) {
  return spawnSync('bash', ['-c', common + script], {
    encoding: 'utf8', env: { ...cleanEnv, ...env },
  });
}
const mocks = `docker() { return 0; }; ss() { return 0; }; `;

test('shell syntax and help require no Docker installation', () => {
  assert.equal(spawnSync('bash', ['-n', 'init.sh', 'update.sh', 'scripts/deploy-common.sh']).status, 0);
  assert.equal(spawnSync('bash', ['init.sh', '--help']).status, 0);
});
test('custom values survive installer preflight', () => {
  const result = bash(mocks + 'prepare_deployment; printf "%s %s %s" "$WEB_PORT" "$GATEWAY_PORT" "$WEB_WORKERS"', {
    COMPOSE_PROJECT_NAME: 'panel-b', WEB_PORT: '16601', GATEWAY_PORT: '17002', WEB_WORKERS: '3',
  });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /16601 17002 3$/);
});
test('automatic defaults skip occupied and duplicate ports', () => {
  const result = bash(`ss() { [[ $* == *:6600 || $* == *:7001 ]] && printf busy; return 0; }; available_port 6600; printf ' '; available_port 7001 7002`);
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stdout, '6601 7003');
});
for (const env of [
  { WEB_PORT: '6600', GATEWAY_PORT: '6600' }, { WEB_PORT: '65536' },
  { WEB_PORT: '01' }, { WEB_PORT: '1;touch sentinel' }, { WEB_WORKERS: '0' },
  { COMPOSE_PROJECT_NAME: 'INVALID!' }, { HORIZON_MAX_PROCESSES: '0' },
]) {
  test(`unsafe settings rejected: ${JSON.stringify(env)}`, () => {
    assert.notEqual(bash(mocks + 'prepare_deployment', { COMPOSE_PROJECT_NAME: 'panel-a', ...env }).status, 0);
  });
}
test('occupied explicit port is rejected', () => {
  const result = bash(`docker() { return 0; }; ss() { [[ $* == *:16600 ]] && printf busy; return 0; }; prepare_deployment`, {
    COMPOSE_PROJECT_NAME: 'panel-a', WEB_PORT: '16600', GATEWAY_PORT: '17001',
  });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /已占用/);
});
for (const kind of ['container', 'volume', 'network']) {
  test(`existing ${kind} cannot be adopted by a fresh install`, () => {
    const result = bash(`ss() { return 0; }; docker() { [[ $1 == ${kind} ]] && printf existing; return 0; }; prepare_deployment`, {
      COMPOSE_PROJECT_NAME: 'panel-a',
    });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /已有关联/);
  });
}
test('Docker permission/connection failure stops preflight', () => {
  const result = bash(`docker() { [[ $1 == compose ]] && printf '2.39.0' && return 0; return 1; }; require_docker`);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /无法访问 Docker/);
});
test('remote Docker context is rejected', () => {
  const result = bash(`docker() { [[ $1 == compose ]] && printf '2.39.0'; return 0; }; require_docker`, {
    DOCKER_HOST: 'tcp://example.test:2376',
  });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /不支持远程/);
});
test('templates preserve Nginx variables and contain no fixed listen ports', () => {
  const conf = readFileSync('docker/nginx.conf', 'utf8');
  assert.match(conf, /listen 127\.0\.0\.1:\$\{GATEWAY_PORT\}/);
  assert.match(conf, /\$\{WEB_PORT\}/);
  assert.match(conf, /try_files \$uri @v2board/);
  assert.doesNotMatch(conf, /:6600|:7001/);
});
