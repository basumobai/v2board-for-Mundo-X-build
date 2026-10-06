import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Script } from 'node:vm';
import { test, after } from 'node:test';
import { buildXiaoAdmin } from '../scripts/build-xiao-admin.mjs';
import { validateXiaoV2node, xiaoApiRoutes, xiaoCompatibility } from './xiao-contract.mjs';

const scratch = mkdtempSync(join(tmpdir(), 'xiao-admin-test-'));
const output = buildXiaoAdmin(join(scratch, 'package'));
after(() => rmSync(scratch, { recursive: true, force: true }));
const sha = value => createHash('sha256').update(value).digest('hex');
const read = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('Xiao package contains only the admin payload and its installer metadata', () => {
  assert.deepEqual(readdirSync(output).sort(), ['LICENSE', 'README.md', 'SHA256SUMS', 'compatibility.json', 'install.sh', 'public', 'resources']);
  assert.deepEqual(readdirSync(join(output, 'public')), ['assets']);
  assert.deepEqual(readdirSync(join(output, 'public/assets')), ['admin']);
  assert.deepEqual(readdirSync(join(output, 'resources/views')), ['admin.blade.php']);
  execFileSync('sha256sum', ['--check', 'SHA256SUMS'], { cwd: output, stdio: 'pipe' });
});

test('adaptation removes every incompatible model and retains the shipped UI fixes', () => {
  const bundle = readFileSync(join(output, 'public/assets/admin/umi.js'), 'utf8');
  assert.doesNotThrow(() => new Script(bundle));
  assert.doesNotMatch(bundle, /server\/mx|serverMx|callMx|mxOnly|mundordp|\bmc1\b|Mundo X|MGate/);
  for (const term of ['latestNodesRequest', 'createSequence', '新增节点', '刷新节点', '节点列表刷新失败', '网络配置格式有误']) assert.ok(bundle.includes(term), term);
  assert.equal(readFileSync(join(output, 'public/assets/admin/custom.css'), 'utf8'), read('public/assets/admin/custom.css'));
  assert.equal(readFileSync(join(output, 'public/assets/admin/custom.js'), 'utf8'), read('public/assets/admin/custom.js'));
});

test('Blade defines its own cache keys before use and needs no fork backend service', () => {
  const view = readFileSync(join(output, 'resources/views/admin.blade.php'), 'utf8');
  assert.ok(view.indexOf('$admin_ui_version =') < view.indexOf('{{$admin_ui_version}}'));
  assert.ok(view.indexOf('$admin_bundle_version =') < view.indexOf('{{$admin_bundle_version}}'));
  assert.match(view, /hash_file\('sha256', \$bundlePath\)/);
  assert.doesNotMatch(view, /App\\Support|FrontendAssets::|RuntimeConfigService/);
  assert.doesNotMatch(view, /user-scalable=no|maximum-scale=1/);
});

test('API contract is pinned to actual Xiao routes and has no Mundo endpoints', () => {
  const source = readFileSync(new URL('./fixtures/xiao-admin/AdminRoute.php', import.meta.url));
  assert.equal(sha(source), xiaoCompatibility.adminRouteSha256);
  const routes = xiaoApiRoutes('custom-admin');
  assert.ok(routes.has('POST /api/v1/custom-admin/server/v2node/save'));
  assert.ok(routes.has('GET /api/v1/custom-admin/stat/getServerTodayRank'));
  assert.ok(routes.has('GET /api/v1/custom-admin/system/getQueueStats'));
  assert.ok(routes.has('POST /api/v1/passport/auth/login'));
  assert.ok(!routes.has('POST /api/v1/custom-admin/server/mx/save'));
  assert.ok(!routes.has('GET /api/v1/admin/user/fetch'));
  const valid = { group_id: [1], name: '测试', host: 'node.example.test', port: 443, server_port: 443, rate: 1, protocol: 'vmess', network: 'tcp', tls: 0, disable_sni: 0, zero_rtt_handshake: 0 };
  assert.deepEqual(validateXiaoV2node(valid), []);
  for (const network of ['mc1', 'mundordp']) assert.ok(validateXiaoV2node({ ...valid, network }).some(error => error.startsWith('network')));
  assert.ok(validateXiaoV2node({ ...valid, protocol: 'mx' }).some(error => error.startsWith('protocol')));
});

function fixture(name) {
  const site = join(scratch, name);
  mkdirSync(join(site, 'public/assets/admin'), { recursive: true });
  mkdirSync(join(site, 'resources/views'), { recursive: true });
  mkdirSync(join(site, 'app/Http/Routes/V1'), { recursive: true });
  writeFileSync(join(site, 'artisan'), 'original artisan');
  cpSync(new URL('./fixtures/xiao-admin/AdminRoute.php', import.meta.url), join(site, 'app/Http/Routes/V1/AdminRoute.php'));
  writeFileSync(join(site, 'public/assets/admin/original.js'), 'original assets', { mode: 0o600 });
  writeFileSync(join(site, 'resources/views/admin.blade.php'), 'original view');
  writeFileSync(join(site, '.env'), 'original config');
  return site;
}

test('install and rollback preserve backend/config and restore original admin files', () => {
  const site = fixture('site');
  const backendBefore = readFileSync(join(site, 'app/Http/Routes/V1/AdminRoute.php'));
  const installed = execFileSync('bash', [join(output, 'install.sh'), 'install', site], { env: { ...process.env, PHP_BIN: 'xiao-no-php-test' }, encoding: 'utf8' });
  const backup = installed.match(/Backup: (.+)/)[1];
  assert.equal(statSync(backup).mode & 0o777, 0o700);
  assert.equal(statSync(join(site, 'public/assets/admin/umi.js')).mode & 0o777, 0o644);
  assert.equal(statSync(join(site, 'public/assets/admin')).mode & 0o777, 0o755);
  assert.ok(!existsSync(join(site, 'public/assets/admin/original.js')));
  assert.equal(readFileSync(join(site, '.env'), 'utf8'), 'original config');
  assert.deepEqual(readFileSync(join(site, 'app/Http/Routes/V1/AdminRoute.php')), backendBefore);
  execFileSync('bash', [join(output, 'install.sh'), 'restore', site, backup], { stdio: 'pipe' });
  assert.deepEqual(readdirSync(join(site, 'public/assets/admin')), ['original.js']);
  assert.equal(readFileSync(join(site, 'resources/views/admin.blade.php'), 'utf8'), 'original view');
  assert.equal(statSync(join(site, 'public/assets/admin/original.js')).mode & 0o777, 0o600);
});

test('installer refuses other API contracts and tampered packages before touching a site', () => {
  const incompatible = fixture('incompatible');
  writeFileSync(join(incompatible, 'app/Http/Routes/V1/AdminRoute.php'), 'different routes');
  assert.throws(() => execFileSync('bash', [join(output, 'install.sh'), 'install', incompatible], { stdio: 'pipe' }), /different API contract/);
  assert.equal(readFileSync(join(incompatible, 'resources/views/admin.blade.php'), 'utf8'), 'original view');
  const tampered = join(scratch, 'tampered');
  cpSync(output, tampered, { recursive: true });
  writeFileSync(join(tampered, 'public/assets/admin/custom.js'), 'tampered');
  const site = fixture('tamper-site');
  assert.throws(() => execFileSync('bash', [join(tampered, 'install.sh'), 'install', site], { stdio: 'pipe' }));
  assert.equal(readFileSync(join(site, 'resources/views/admin.blade.php'), 'utf8'), 'original view');
});
