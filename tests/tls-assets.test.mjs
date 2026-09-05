import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const adminBundle = readFileSync('public/assets/admin/umi.js', 'utf8');

test('admin exposes panel-distributed TLS certificate mode', () => {
  assert.match(adminBundle, /value: "remote"/);
  assert.match(adminBundle, /e\.cert_mode == "remote"/);
  assert.match(adminBundle, /value: e\.pinned_peer_cert_sha256/);
  assert.match(adminBundle, /readOnly: true/);
});

test('admin payment compatibility remains intact after TLS bundle update', () => {
  assert.match(adminBundle, /MGate/);
  assert.match(adminBundle, /Paytaro/);
});
