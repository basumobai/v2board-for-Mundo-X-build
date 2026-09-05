import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('Paytaro QR browser widget is valid JavaScript', () => {
  const source = read('public/paytaro-qr/widget.js');
  assert.doesNotThrow(() => new Script(source));
  assert.match(source, /global\.PaytaroQR = PaytaroQR/);
});

test('Docker exposes only the controlled Paytaro PHP bridge', () => {
  const nginx = read('docker/nginx.conf');
  const routes = read('routes/web.php');

  assert.match(nginx, /location = \/paytaro-qr\/pay\.php/);
  assert.match(nginx, /location ~ \\.php\$ \{\s*return 404;/);
  assert.match(routes, /paytaro-qr\/pay\.php.*PaytaroQrController@handle/);
});

test('admin payment UI preserves MGate and shows Paytaro help', () => {
  const admin = read('public/assets/admin/umi.js');

  assert.match(admin, /"MGate" === r/);
  assert.match(admin, /r\.includes\("Paytaro"\)/);
  assert.match(admin, /https:\/\/v3\.paytaro\.com\/#\/docs/);
});
