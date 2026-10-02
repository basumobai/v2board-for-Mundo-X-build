// Render the shipped React bundles; only API responses and Blade parameters
// use isolated fixtures. This does not replace a production database test.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve, extname, join } from 'node:path';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const root = resolve(process.env.UI_PROJECT_ROOT || '.');
const output = resolve(process.env.UI_OUTPUT_DIR || 'test-results/ui');
mkdirSync(output, { recursive: true });
const users = Array.from({ length: 8 }, (_, i) => ({
  commission_type: 0, commission_rate: null, discount: null, speed_limit: null, device_limit: null, is_staff: 0, group_id: 1, id: i + 1, email: `student-${i + 1}@example.test`, is_admin: 1, ban: 0,
  u: 1024 ** 3, d: 3 * 1024 ** 3, transfer_enable: 100 * 1024 ** 3,
  total_used: 4 * 1024 ** 3, commission_balance: 0, balance: 2000,
  plan_id: 1, plan_name: '测试订阅', expired_at: 1900000000,
  created_at: 1700000000, updated_at: 1700000000, uuid: 'fixture-uuid',
}));
let nodes = [{ id: 1, name: '香港测试节点', type: 'vmess', host: 'node.example.test',
  port: 443, server_port: 443, show: 1, rate: 1, online: 3, available_status: 1,
  group_id: [1], tls: 1, network: 'ws', alter_id: 0, parent_id: null }];
const requests = [];
let delayNextNodeFetch = false;
let releaseNodeFetch;
let delayedNodeFetchStarted;
function html(kind, url) {
  const user = kind === 'user';
  const color = url.searchParams.get('color') || 'default';
  const version = createHash('sha256').update(readFileSync(join(root, user ? 'public/theme/default/assets/custom.css' : 'public/assets/admin/custom.css'))).digest('hex').slice(0, 20);
  const values = { title: 'Mundo 测试面板', theme: 'default', version: 'fixture', admin_ui_version: version,
    frontend_ui_version: version, theme_sidebar: url.searchParams.get('sidebar') || 'light',
    theme_header: url.searchParams.get('header') || 'light', theme_color: color,
    background_url: '', logo: '', secure_path: 'admin', description: '隔离渲染测试',
  };
  let template = readFileSync(join(root, user ? 'public/theme/default/dashboard.blade.php' : 'resources/views/admin.blade.php'), 'utf8');
  template = template.replace(/@php \(\$colors = \[[\s\S]*?\]\)/g, '')
    .replace(/@if[^\n]*\n/g, '').replace(/@endif/g, '')
    .replace(/\{!![^}]*!!\}/g, '')
    .replace(/\{\{\$theme_config\['([^']+)'\]\}\}/g, (_, key) => values[key] || '')
    .replace(/\{\{\$colors\[[^}]+\}\}/g, '#0665d0')
    .replace(/\{\{\$frontend_ui_version \?\? \$version\}\}/g, version)
    .replace(/\{\{\$(\w+)\}\}/g, (_, key) => values[key] || '');
  return template;
}
const server = createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname === '/monitor/api/stats') {
    res.setHeader('Content-Type', 'application/json');
    res.end(JSON.stringify({status:'running'}));
    return;
  }
  if (url.pathname.startsWith('/api/')) {
    requests.push(url.pathname);
    let data = [];
    let total;
    if (url.pathname.endsWith('/checkLogin')) data = { is_admin: 1 };
    else if (url.pathname.endsWith('/auth/login')) data = { is_admin: 1, auth_data: 'fixture-token', token: 'fixture-token' };
    else if (url.pathname.endsWith('/user/info')) data = users[0];
    else if (url.pathname.endsWith('/getUserInfoById')) data = users[0];
    else if (url.pathname.endsWith('/user/fetch')) { data = users; total = users.length; }
    else if (url.pathname.endsWith('/getNodes')) {
      data = nodes.map(node => ({...node}));
      if (delayNextNodeFetch) {
        delayNextNodeFetch = false;
        res.setHeader('X-Fixture-Stale', '1');
        await new Promise(resolve => {
          releaseNodeFetch = resolve;
          delayedNodeFetchStarted();
        });
      }
    }
    else if (url.pathname.endsWith('/group/fetch')) data = [{ id: 1, name: '测试组' }];
    else if (url.pathname.endsWith('/plan/fetch')) data = [{ id: 1, name: '测试订阅', transfer_enable: 100, month_price: 2000 }];
    else if (url.pathname.endsWith('/getOverride')) data = { month_income: 12345, day_income: 1200, order_count: 5, register_count: 20 };
    else if (url.pathname.endsWith('/config/fetch')) data = {site:{currency:'CNY'}};
    else if (url.pathname.endsWith('/comm/config')) data = { is_telegram: 0, invite_commission: 10, currency: 'CNY', currency_symbol: '¥', deposit_bounus: [] };
    else if (url.pathname.endsWith('/getSubscribe')) data = { ...users[0], subscribe_url: 'https://example.test/sub', plan: { name: '测试订阅' } };
    else if (url.pathname.endsWith('/getStat')) data = [0, 0, 0];
    else if (url.pathname.endsWith('/server/fetch')) data = nodes;
    else if (url.pathname.endsWith('/server/v2node/save')) {
      const body = (await Array.fromAsync(req)).map(chunk => chunk.toString()).join('');
      const name = req.headers['content-type']?.includes('json') ? JSON.parse(body).name : new URLSearchParams(body).get('name');
      if (!name) { res.statusCode = 422; res.end(JSON.stringify({code: 422, message: 'Name required'})); return; }
      nodes = [...nodes, { ...nodes[0], id: nodes.length + 1, name, type: 'v2node' }];
      data = true;
    }
    else if (/\/(save|update|drop|cancel|paid)$/.test(url.pathname)) data = true;
    res.setHeader('Content-Type', 'application/json');
    res.end(JSON.stringify({ data, total, code: 200, status: 'success' }));
    return;
  }
  if (url.pathname === '/admin' || url.pathname === '/') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(html(url.pathname === '/admin' ? 'admin' : 'user', url)); return;
  }
  const path = resolve(root, 'public', '.' + url.pathname);
  if (!path.startsWith(join(root, 'public') + '/') || !existsSync(path)) { res.statusCode = 404; res.end(); return; }
  const types = { '.css': 'text/css', '.js': 'application/javascript', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
  res.setHeader('Content-Type', types[extname(path)] || 'application/octet-stream');
  res.end(readFileSync(path));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true,
  ...(process.env.CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH } : {}),
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--no-zygote'],
});
try {
  for (const width of (process.env.UI_KIND === 'user' ? [] : [1440, 390])) {
    const context = await browser.newContext({ locale:'zh-CN', viewport: { width, height: 900 }, isMobile: width === 390, hasTouch: width === 390, ...(width === 390 ? { userAgent: 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36' } : {}) });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${origin}/admin#/login`);
    await page.locator('.v2board-auth-box input[type=password]').waitFor();
    await page.screenshot({ path: join(output, `admin-login-${width}.png`) });
    await page.locator('.v2board-auth-box input[type=email]').fill('admin@example.test');
    await page.locator('.v2board-auth-box input[type=password]').fill('fixture-password');
    await page.locator('.v2board-auth-box button[type=submit]').click();
    await page.waitForFunction(() => location.hash === '#/dashboard');
    for (const route of ['dashboard', 'user', 'server/manage']) {
      await page.goto(`${origin}/admin#/${route}`);
      await page.locator('#main-container').waitFor();
      await page.waitForTimeout(700);
      await page.screenshot({ path: join(output, `admin-${route.replace('/', '-')}-${width}.png`) });
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Page overflows at ${width}: ${route}`);
      if (route === 'dashboard' && width === 1440) {
        await page.setViewportSize({width:1152, height:650});
        const shortcuts = await page.locator('.js-classic-nav a.block').evaluateAll(cards => cards.map(card => ({top:card.getBoundingClientRect().top, height:card.getBoundingClientRect().height})));
        assert.equal(new Set(shortcuts.map(card => Math.round(card.top))).size, 1, 'Tablet shortcuts expand into multiple oversized rows');
        assert.ok(shortcuts.every(card => card.height < 110), 'Tablet shortcuts dominate the dashboard');
        await page.screenshot({path:join(output,'admin-dashboard-tablet-1152.png')});
        await page.setViewportSize({width, height:900});
      }
      if (route === 'user') {
        assert.equal(await page.locator('.ant-table-fixed-right tbody td').first().evaluate(el=>getComputedStyle(el).backgroundColor), 'rgb(255, 255, 255)', 'Fixed cells must be opaque');
        await page.locator('.ant-table-body').first().evaluate(el=>{el.scrollLeft=300;});
        await page.locator('.ant-table-fixed-right .ant-dropdown-trigger').first().click();
        await page.locator('.ant-dropdown-menu:visible').waitFor();
        await page.screenshot({ path: join(output, `admin-user-menu-${width}.png`) });

        await page.locator('.ant-dropdown-menu:visible').getByText('编辑', { exact: true }).first().click();
        await page.locator('.ant-drawer-content').waitFor();
        await page.waitForTimeout(400);
        const box = await page.locator('.ant-drawer-content-wrapper').boundingBox();
        assert.ok(box.x >= -1 && box.x + box.width <= width + 1, 'Drawer leaves viewport');
        await page.screenshot({ path: join(output, `admin-user-drawer-${width}.png`) });
        if (width === 390) {
          await page.setViewportSize({width, height:600});
          await page.locator('.ant-drawer-body textarea').last().scrollIntoViewIfNeeded();
          const field = await page.locator('.ant-drawer-body textarea').last().boundingBox();
          const footer = await page.locator('.v2board-drawer-action').boundingBox();
          assert.ok(footer.y + footer.height <= 601 && field.y + field.height <= footer.y + 1, 'Drawer footer obscures last field at short viewport');
          await page.screenshot({path:join(output, 'admin-user-drawer-short-390.png')});
          await page.setViewportSize({width,height:900});
        }
        await page.locator('.v2board-drawer-action .ant-btn').first().click();
        await page.locator('.ant-table-fixed-right .ant-dropdown-trigger').first().click();
        await page.locator('.ant-dropdown-menu:visible').getByText('删除用户', {exact:true}).click();
        await page.locator('.ant-modal-content').waitFor();
        await page.waitForTimeout(250);
        const modal = await page.locator('.ant-modal-content').boundingBox();
        assert.ok(modal.x >= -1 && modal.x + modal.width <= width + 1, 'Modal leaves viewport');
        await page.screenshot({ path: join(output, `admin-user-modal-${width}.png`) });
        await page.locator('.ant-modal-content .ant-btn').first().click();
      }
      if (route === 'server/manage') {
        try {
        const addNode = page.getByRole('button', {name:'新增节点'});
        const nodeDrawer = page.locator('.ant-drawer-open');
        await addNode.click();
        await page.locator('.ant-dropdown-menu:visible').getByText('V2node', {exact:true}).click();
        await nodeDrawer.locator('.ant-drawer-content').waitFor();
        await nodeDrawer.getByPlaceholder('请输入节点名称').fill('取消的草稿');
        await nodeDrawer.locator('.ant-drawer-close').click();
        await page.locator('.ant-drawer-open').waitFor({state:'hidden'});
        delayNextNodeFetch = true;
        const delayedRequest = new Promise(resolve => { delayedNodeFetchStarted = resolve; });
        await page.getByRole('button', {name:'刷新节点'}).click();
        await delayedRequest;
        console.log(`Node ${width}: delayed refresh started`);
        await addNode.click();
        await page.locator('.ant-dropdown-menu:visible').getByText('V2node', {exact:true}).click();
        await nodeDrawer.locator('.ant-drawer-content').waitFor();
        assert.equal(await nodeDrawer.getByPlaceholder('请输入节点名称').inputValue(), '', 'A new node inherited a cancelled draft');
        await nodeDrawer.getByPlaceholder('请输入节点名称').fill(`新增测试节点-${width}`);
        await nodeDrawer.getByPlaceholder('地址或IP', {exact:true}).fill('new-node.example.test');
        await nodeDrawer.getByPlaceholder('用户连接端口').fill('443');
        await nodeDrawer.getByPlaceholder('服务端开放端口').fill('443');
        await nodeDrawer.locator('.form-group').filter({has:page.locator('label').getByText('节点协议',{exact:true})}).locator('.ant-select-selection').click();
        await page.locator('.ant-select-dropdown:visible').getByText('VMess',{exact:true}).click();
        await nodeDrawer.locator('.form-group').filter({has:page.locator('label').filter({hasText:'权限组'})}).first().locator('.ant-select-selection').click();
        await page.locator('.ant-select-dropdown:visible').getByText('测试组',{exact:true}).click();
        await nodeDrawer.getByPlaceholder('请输入节点名称').click();
        await page.screenshot({path:join(output,`admin-node-create-${width}.png`)});
        const [saved] = await Promise.all([
          page.waitForResponse(response => response.url().endsWith('/server/v2node/save')),
          nodeDrawer.getByRole('button', {name:/提\s*交/}).click(),
        ]);
        assert.equal(saved.status(),200,`Node save failed: ${await saved.text()}`);
        console.log(`Node ${width}: saved`);
        await page.getByText(`新增测试节点-${width}`).first().waitFor();
        const staleResponse = page.waitForResponse(response => response.headers()['x-fixture-stale'] === '1');
        releaseNodeFetch();
        await staleResponse;
        await page.waitForLoadState('networkidle');
        assert.ok(await page.getByText(`新增测试节点-${width}`).first().isVisible(), 'An older list response replaced the saved node');
        await page.route('**/api/v1/admin/server/manage/getNodes?*',route=>route.abort('failed'),{times:1});
        await page.getByRole('button', {name:'刷新节点'}).click();
        await page.getByText('节点列表刷新失败',{exact:true}).waitFor();
        await page.getByRole('button', {name:'刷新节点'}).waitFor({state:'visible'});
        await page.waitForFunction(() => !document.querySelector('button[aria-label="刷新节点"]')?.classList.contains('ant-btn-loading'));
        const recoveryResponse = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/getNodes'));
        await page.getByRole('button', {name:'刷新节点'}).click();
        await recoveryResponse;
        await addNode.click();
        await page.locator('.ant-dropdown-menu:visible').waitFor();
        await addNode.click();
        const nodeTrigger = page.locator(width === 390 ? '.v2board_node_mobile .ant-dropdown-trigger' : '.ant-table-fixed-right .ant-dropdown-trigger').first();
        await nodeTrigger.click();
        await page.locator('.ant-dropdown-menu:visible').getByText('编辑', {exact:true}).click();
        await page.locator('.ant-drawer-content').waitFor();
        await page.waitForTimeout(400);
        await page.screenshot({ path: join(output, `admin-node-drawer-${width}.png`) });
        await page.locator('.v2board-drawer-action .ant-btn').first().click();
        console.log(`Node ${width}: create, stale response and transport recovery passed`);
        } catch (error) {
          console.log(`Node ${width} failed; requests: ${JSON.stringify(requests)}; errors: ${JSON.stringify(errors)}`);
          console.log('Node failure state:',await page.locator('body').innerText());
          await page.screenshot({path:join(output,`admin-node-failure-${width}.png`)});
          releaseNodeFetch?.();
          throw error;
        }
      }
    }
    if (width === 390) {
      await page.getByRole('button', {name:'打开导航',exact:true}).click();
      await page.waitForTimeout(500);
      console.log('nav',await page.locator('#page-container').getAttribute('class'),await page.locator('#sidebar').boundingBox());
      await page.screenshot({path:join(output,'admin-mobile-nav-390.png')});
      assert.ok((await page.locator('#sidebar').boundingBox()).x >= -1, 'Mobile navigation stays offscreen');
      await page.locator('.v2board-nav-mask').click({position:{x:350,y:400}});
      const zoom = await context.newCDPSession(page);
      assert.ok(!/user-scalable\s*=\s*no|maximum-scale\s*=\s*1(?:[,;\s]|$)/i.test(await page.locator('meta[name=viewport]').getAttribute('content')), 'Viewport blocks zoom');
      await zoom.send('Emulation.setPageScaleFactor', {pageScaleFactor:1.5});
      assert.ok(await page.evaluate(()=>visualViewport.scale>1), 'Mobile zoom emulation did not increase scale');
      await zoom.send('Emulation.setPageScaleFactor', {pageScaleFactor:1});
      await zoom.detach();
    }
    for (const color of ['default', 'green', 'black', 'darkblue']) {
      for (const header of ['light', 'dark']) {
        for (const sidebar of ['light', 'dark']) {
          await page.goto(`${origin}/admin?color=${color}&header=${header}&sidebar=${sidebar}#/dashboard`);
          await page.locator('#page-header').waitFor();
          await page.waitForLoadState('networkidle');
          await page.waitForFunction(() => Array.from(document.styleSheets).some(sheet => sheet.href && sheet.href.includes('custom.css') && sheet.cssRules.length > 10));
          assert.equal(await page.locator('html').getAttribute('data-mundo-theme'), color);
          const cdp = await context.newCDPSession(page);
          await cdp.send('Emulation.setEmulatedMedia', { features: [
            { name: 'prefers-reduced-transparency', value: 'reduce' },
            { name: 'prefers-reduced-motion', value: 'reduce' },
          ] });
          await page.reload();
          await page.waitForLoadState('networkidle');
          const styles = await page.evaluate(() => {
            const header = getComputedStyle(document.querySelector('#page-header > .content-header'));
            return { background: header.backgroundColor, backdrop: header.backdropFilter,
              reduced: matchMedia('(prefers-reduced-transparency: reduce)').matches };
          });
          assert.equal(styles.reduced, true);
          assert.equal(styles.background, header === 'dark' ? 'rgb(38, 51, 71)' : 'rgb(255, 255, 255)', `${color}/${header}/${sidebar}/${width}`);
          assert.equal(styles.backdrop, 'none');
          if (color === 'green') await page.screenshot({ path: join(output, `admin-${header}-${sidebar}-reduced-${width}.png`) });
          await cdp.send('Emulation.setEmulatedMedia', { features: [] });
          await cdp.detach();
        }
      }
    }
    await page.goto(`${origin}/admin#/dashboard`);
    await page.locator('#page-header').waitFor();
    await page.locator('#page-header button').filter({ has: page.locator('.fa-sun, .fa-moon') }).click();
    await page.waitForFunction(() => Boolean(document.querySelector('style.darkreader')));
    await page.screenshot({ path: join(output, `admin-night-mode-${width}.png`) });
    await page.reload();
    await page.waitForFunction(() => Boolean(document.querySelector('style.darkreader')));
    console.log(`Admin ${width}px rendered; page errors: ${JSON.stringify(errors)}; APIs: ${[...new Set(requests)].join(', ')}`);
    assert.deepEqual(errors, []);
    await context.close();
  }  if (existsSync(join(root, 'public/theme/default/assets/custom.css'))) {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({locale:'zh-CN',viewport:{width,height:900},isMobile:width===390,hasTouch:width===390,
        ...(width===390 ? {userAgent:'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36'} : {})});
      const page = await context.newPage(); page.setDefaultTimeout(10000);
      const errors=[]; page.on('pageerror',e=>errors.push(e.message));
      await page.goto(`${origin}/#/login`);
      await page.locator('.v2board-auth-box input[type=password]').waitFor();
      await page.waitForTimeout(300);
      await page.screenshot({path:join(output,`user-login-${width}.png`)});
      assert.equal(await page.locator('.v2board-auth-box .block').first().evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
      await page.locator('.v2board-auth-box input').first().fill('student@example.test');
      await page.locator('.v2board-auth-box input[type=password]').fill('fixture-password');
      await page.locator('.v2board-auth-box button[type=submit]').click();
      await page.waitForFunction(()=>location.hash==='#/dashboard');
      for (const route of ['dashboard','plan','order','profile']) {
        await page.goto(`${origin}/#/${route}`);
        await page.locator('#main-container').waitFor();
        await page.waitForTimeout(700);
        await page.screenshot({path:join(output,`user-${route}-${width}.png`)});
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`User ${route} overflows at ${width}`);
        if (route === 'dashboard' && width === 1440) {
          await page.setViewportSize({width:1152, height:650});
          await page.goto(`${origin}/?header=dark#/dashboard`);
          await page.locator('#page-header').waitFor();
          await page.waitForLoadState('networkidle');
          await page.screenshot({path:join(output,'user-dashboard-dark-tablet-1152.png')});
          await page.setViewportSize({width,height:900});
          await page.goto(`${origin}/#/dashboard`);
        }
        if(route==='profile') {
          console.log('Profile actions:', await page.getByRole('button').allTextContents());
          await page.getByRole('button',{name:/重\s*置|Reset/}).click();
          await page.locator('.ant-modal-content').waitFor();
          await page.waitForTimeout(300);
          await page.screenshot({path:join(output,`user-reset-modal-${width}.png`)});
          await page.locator('.ant-modal-content .ant-btn').first().click();
        }
      }
      if(width===390) {
        await page.locator('#page-header button').filter({has:page.locator('.fa-bars')}).click();
        await page.waitForTimeout(500);
        assert.ok((await page.locator('#sidebar').boundingBox()).x>=-1,'User mobile nav remains offscreen');
        await page.screenshot({path:join(output,'user-nav-390.png')});
        await page.locator('.v2board-nav-mask').click({position:{x:350,y:400}});
        assert.ok(!/user-scalable\s*=\s*no|maximum-scale\s*=\s*1(?:[,;\s]|$)/i.test(await page.locator('meta[name=viewport]').getAttribute('content')));
        const zoom=await context.newCDPSession(page);
        await zoom.send('Emulation.setPageScaleFactor',{pageScaleFactor:1.5});
        assert.ok(await page.evaluate(()=>visualViewport.scale>1),'User mobile zoom emulation is blocked');
        await zoom.send('Emulation.setPageScaleFactor',{pageScaleFactor:1}); await zoom.detach();
      }
      for(const color of ['default','green','black','darkblue']) for(const header of ['light','dark']) for(const sidebar of ['light','dark']) {
        const cdp=await context.newCDPSession(page);
        await cdp.send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-transparency',value:'reduce'},{name:'prefers-reduced-motion',value:'reduce'}]});
        await page.goto(`${origin}/?color=${color}&header=${header}&sidebar=${sidebar}#/dashboard`);
        await page.locator('#page-header').waitFor(); await page.waitForLoadState('networkidle');
        const styles=await page.evaluate(()=>({header:getComputedStyle(document.querySelector('#page-header')).backgroundColor,
          sidebar:getComputedStyle(document.querySelector('#sidebar')).backgroundColor,
          backdrop:getComputedStyle(document.querySelector('#page-header')).backdropFilter,
          color:getComputedStyle(document.body).getPropertyValue('--user-blue').trim(),
          themeLast:document.querySelector('#mundo-user-overrides').compareDocumentPosition(document.querySelector('link[href*="/assets/theme/"]'))&Node.DOCUMENT_POSITION_PRECEDING,
          motion:getComputedStyle(document.querySelector('.v2board-shortcuts-item')).transitionDuration,
          content:getComputedStyle(document.querySelector('#main-container .block')).backgroundColor,
          themes:Array.from(document.querySelectorAll('link[href*="/assets/theme/"]')).every(el=>new URL(el.href).searchParams.get('v')===window.settings.ui_version)}));
        assert.equal(styles.header,header==='dark'?'rgb(38, 51, 71)':'rgb(255, 255, 255)');
        assert.equal(styles.sidebar,sidebar==='dark'?'rgb(38, 51, 71)':'rgb(255, 255, 255)');
        assert.equal(styles.backdrop,'none'); assert.equal(styles.content,'rgb(255, 255, 255)');
        assert.ok(styles.motion.split(',').every(time=>parseFloat(time)<.001),'Reduced motion keeps long transition'); assert.ok(styles.themeLast && styles.themes,'User theme order/version is incorrect');
        assert.equal(styles.color,{default:'#2865d9',green:'#247a77',black:'#3f4b5f',darkblue:'#3b5998'}[color]);
        if(color==='green') await page.screenshot({path:join(output,`user-${header}-${sidebar}-reduced-${width}.png`)});
        await cdp.send('Emulation.setEmulatedMedia',{features:[]}); await cdp.detach();
      }
      await page.goto(`${origin}/#/dashboard`); await page.locator('#page-header').waitFor();
      await page.locator('#page-header button').filter({has:page.locator('.fa-sun, .fa-moon')}).click();
      await page.waitForFunction(()=>Boolean(document.querySelector('style.darkreader')));
      await page.waitForTimeout(300);
      await page.screenshot({path:join(output,`user-night-mode-${width}.png`)});
      await page.reload(); await page.waitForFunction(()=>Boolean(document.querySelector('style.darkreader')));
      console.log(`User ${width}px rendered; errors: ${JSON.stringify(errors)}`); assert.deepEqual(errors,[]);
      await context.close();
    }
  }

} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
