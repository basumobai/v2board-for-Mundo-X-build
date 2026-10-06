import { readFileSync } from 'node:fs';

export const xiaoCompatibility = JSON.parse(readFileSync(new URL('../variants/xiao-admin/compatibility.json', import.meta.url), 'utf8'));

// These are the exact upstream route files at the pinned Xiao commit. Nested
// groups in AdminRoute only add a server type prefix; never infer routes from UI.
export function xiaoApiRoutes(securePath) {
  const routes = new Set();
  for (const [file, prefix] of [['AdminRoute.php', securePath], ['UserRoute.php', 'user'], ['PassportRoute.php', 'passport']]) {
    const source = readFileSync(new URL(`./fixtures/xiao-admin/${file}`, import.meta.url), 'utf8');
    let nested = '';
    for (const line of source.split('\n')) {
      const group = line.match(/'prefix'\s*=>\s*'(server\/[^']+)'/);
      if (group) nested = group[1];
      const route = line.match(/\$router->(get|post)\s*\(\s*'([^']+)'/i);
      if (route) routes.add(`${route[1].toUpperCase()} /api/v1/${[prefix, nested, route[2]].filter(Boolean).map(part => part.replace(/^\/+|\/+$/g, '')).join('/')}`);
      if (line.trim() === '});') nested = '';
    }
  }
  return routes;
}

export function validateXiaoV2node(params) {
  const problems = [];
  for (const field of ['group_id', 'name', 'host', 'port', 'server_port', 'rate']) {
    const value = params[field];
    if (value == null || value === '' || (Array.isArray(value) && !value.length)) problems.push(`${field} is required`);
  }
  for (const [field, rules] of Object.entries(xiaoCompatibility.v2nodeValidation)) {
    const allowed = rules.split('in:')[1].split(',');
    if (!allowed.includes(String(params[field]))) problems.push(`${field} must be one of ${allowed.join(', ')}`);
  }
  if (typeof params.network_settings === 'string') problems.push('network_settings must be an array/object, not JSON text');
  return problems;
}
