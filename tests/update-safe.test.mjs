import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, copyFileSync, writeFileSync, readFileSync, readdirSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

function run(command, args, cwd, env = {}) {
  const result = spawnSync(command, args, { cwd, env: { ...process.env, ...env }, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
}

function fixture() {
  const root = mkdtempSync(join(tmpdir(), 'mundo-update-'));
  const remote = join(root, 'remote.git'), source = join(root, 'source'), panel = join(root, 'panel');
  run('git', ['init', '--bare', remote], root);
  run('git', ['init', '-b', 'master', source], root);
  mkdirSync(join(source, 'scripts'));
  copyFileSync('update.sh', join(source, 'update.sh'));
  copyFileSync('scripts/deploy-common.sh', join(source, 'scripts/deploy-common.sh'));
  writeFileSync(join(source, '.gitignore'), '.env\nconfig/v2board.php\nconfig/theme/\nstorage/\npublic/custom/\n/vendor/\n/node_modules/\n.install.lock\n');
  writeFileSync(join(source, 'release'), 'old');
  const gitEnv = { GIT_AUTHOR_NAME: 'Test', GIT_AUTHOR_EMAIL: 'test@example.test', GIT_COMMITTER_NAME: 'Test', GIT_COMMITTER_EMAIL: 'test@example.test' };
  run('git', ['add', '.'], source);
  run('git', ['commit', '-m', 'old'], source, gitEnv);
  run('git', ['remote', 'add', 'origin', remote], source);
  run('git', ['push', '-u', 'origin', 'master'], source);
  run('git', ['clone', remote, panel], root);
  writeFileSync(join(source, 'release'), 'new');
  run('git', ['commit', '-am', 'new'], source, gitEnv);
  run('git', ['push'], source);
  const before = run('git', ['rev-parse', 'HEAD'], panel);
  for (const path of ['config/theme', 'storage', 'public/custom/vendor', 'public/custom/node_modules', 'vendor', 'node_modules']) mkdirSync(join(panel, path), { recursive: true });
  writeFileSync(join(panel, '.env'), 'APP_KEY=preserve-key\nCOMPOSE_PROJECT_NAME=preserve-project\n');
  writeFileSync(join(panel, 'config/v2board.php'), '<?php return ["name" => "preserve"];');
  writeFileSync(join(panel, 'config/theme/default.php'), '<?php return ["theme_header" => "dark"];');
  writeFileSync(join(panel, 'storage/user-file'), 'preserve-storage');
  writeFileSync(join(panel, 'public/custom/user.css'), 'preserve-custom-css');
  writeFileSync(join(panel, 'public/custom/vendor/library.js'), 'preserve-theme-vendor');
  writeFileSync(join(panel, 'public/custom/node_modules/asset.js'), 'preserve-theme-package');
  writeFileSync(join(panel, 'vendor/reinstallable-cache'), 'exclude-root-vendor');
  writeFileSync(join(panel, 'node_modules/reinstallable-cache'), 'exclude-root-packages');
  const bin = join(root, 'bin'); mkdirSync(bin);
  writeFileSync(join(bin, 'docker'), `#!/usr/bin/env bash
set -euo pipefail
printf '%s\\n' "$*" >> "$MOCK_LOG"
case "$*" in
  'compose version --short') echo '2.39.0' ;;
  'context inspect'*) echo 'unix:///var/run/docker.sock' ;;
  *'installer php -r'*)
    for arg in "$@"; do
      if [[ $arg == *:/backup ]]; then
        path=\${arg%:/backup}; printf '[client]\\npassword=test-only\\n' > "$path/mysql-client.cnf";
        printf panel_test > "$path/database-name";
      fi
    done ;;
  'compose exec -T horizon'*) echo 0 ;;
  'run '*mysqldump*)
    [[ \${MOCK_FAIL_DUMP:-0} == 0 ]] || exit 1
    echo 'CREATE DATABASE panel_test; /* test snapshot */' ;;
  *'web tar --exclude=redis.sock'*)
    for arg in "$@"; do
      if [[ $arg == *:/backup ]]; then
        path=\${arg%:/backup}; mkdir -p "$path/redis-fixture";
        printf snapshot > "$path/redis-fixture/dump.rdb";
        tar -czf "$path/redis.tar.gz" -C "$path/redis-fixture" .;
        rm -r "$path/redis-fixture";
      fi
    done ;;
esac
`, { mode: 0o755 });
  const env = { PATH: `${bin}:${process.env.PATH}`, MOCK_LOG: join(root, 'docker.log'), UPDATE_BACKUP_ROOT: join(root, 'backups') };
  return { root, panel, before, env };
}

test('update preserves configuration and files and snapshots before migration', () => {
  const f = fixture();
  try {
    run('bash', ['update.sh'], f.panel, f.env);
    assert.equal(readFileSync(join(f.panel, 'release'), 'utf8'), 'new');
    assert.match(readFileSync(join(f.panel, '.env'), 'utf8'), /preserve-key/);
    assert.match(readFileSync(join(f.panel, 'config/theme/default.php'), 'utf8'), /dark/);
    assert.equal(readFileSync(join(f.panel, 'storage/user-file'), 'utf8'), 'preserve-storage');
    assert.equal(readFileSync(join(f.panel, 'public/custom/user.css'), 'utf8'), 'preserve-custom-css');
    const backup = join(f.env.UPDATE_BACKUP_ROOT, readdirSync(f.env.UPDATE_BACKUP_ROOT)[0]);
    assert.ok(existsSync(join(backup, 'COMPLETE')));
    assert.ok(!existsSync(join(backup, 'mysql-client.cnf')));
    run('sha256sum', ['-c', 'SHA256SUMS'], backup);
    const files = run('tar', ['-tzf', 'project.tar.gz'], backup);
    for (const path of ['.env', 'config/v2board.php', 'config/theme/default.php', 'storage/user-file', 'public/custom/user.css', 'public/custom/vendor/library.js', 'public/custom/node_modules/asset.js']) assert.ok(files.includes(path));
    assert.ok(!files.includes('./vendor/') && !files.includes('./node_modules/'));
    const log = readFileSync(f.env.MOCK_LOG, 'utf8');
    assert.ok(log.indexOf('stop horizon') < log.indexOf('--entrypoint mysqldump'));
    assert.ok(log.indexOf('stop redis') < log.indexOf('web tar'));
    assert.ok(log.indexOf('web tar') < log.indexOf('artisan v2board:update'));
    assert.doesNotMatch(log, /test-only|down -v|reset --hard/);
  } finally { rmSync(f.root, { recursive: true, force: true }); }
});

test('failed database backup keeps old source, removes credentials and restores old services', () => {
  const f = fixture();
  try {
    const result = spawnSync('bash', ['update.sh'], { cwd: f.panel, env: { ...process.env, ...f.env, MOCK_FAIL_DUMP: '1' }, encoding: 'utf8' });
    assert.notEqual(result.status, 0);
    assert.equal(run('git', ['rev-parse', 'HEAD'], f.panel), f.before);
    assert.equal(readFileSync(join(f.panel, 'release'), 'utf8'), 'old');
    const backup = join(f.env.UPDATE_BACKUP_ROOT, readdirSync(f.env.UPDATE_BACKUP_ROOT)[0]);
    assert.ok(!existsSync(join(backup, 'COMPLETE')));
    assert.ok(!existsSync(join(backup, 'mysql-client.cnf')));
    const log = readFileSync(f.env.MOCK_LOG, 'utf8');
    assert.doesNotMatch(log, /artisan v2board:update/);
    assert.match(log, /up -d --wait --wait-timeout 180 redis web horizon scheduler gateway/);
  } finally { rmSync(f.root, { recursive: true, force: true }); }
});
