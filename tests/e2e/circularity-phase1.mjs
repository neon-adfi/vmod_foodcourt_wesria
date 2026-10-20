// Reuse the guarded Phase 2 identity seed before circularity activation.
// Keep its output private at /private/tmp/wesria-circularity-phase1-acceptance.json.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const fixture = JSON.parse(await fs.readFile('/private/tmp/wesria-circularity-phase1-acceptance.json', 'utf8'));
const output = '/Users/stev/htdoc/vmod_wesria/output/ego-lite/circularity-phase-1';
const base = 'https://wesria.local.test';
const task = await taskSpace(globalThis.vmodE2eSpaceId ?? 'Wesria circularity Phase 1 acceptance');
const page = task.page('p1');
const report = { spaceId: task.spaceId, scenarios: [], screenshots: [], passed: false };
console.log({ spaceId: task.spaceId });
await fs.mkdir(output, { recursive: true });
await page.cdp('Runtime.enable');
await page.cdp('Network.enable');
await page.cdp('Network.setCacheDisabled', { cacheDisabled: true });
await page.events();
const desktop = { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false };
const mobile = { width: 390, height: 844, deviceScaleFactor: 1, mobile: true };
const passed = name => { report.scenarios.push(name); console.log('PASS ' + name); };
async function submit(selector, path) {
    await page.evaluate(() => { document.documentElement.dataset.acceptanceNavigation = 'pending'; });
    await page.click(selector + ' button[type="submit"]');
    await page.waitForFunction(() => !document.documentElement.dataset.acceptanceNavigation, undefined, { timeout: 15000 });
    await page.waitForURL(base + path);
}
async function shot(name) {
    const state = await page.evaluate(async () => {
        await document.fonts.ready;
        return { width: innerWidth, content: document.documentElement.scrollWidth,
            assets: typeof window.oc?.request === 'function'
                && [...document.querySelectorAll('link[rel="stylesheet"]')].every(link => !!link.sheet) };
    });
    assert.ok(state.assets && state.content <= state.width + 1);
    const file = output + '/' + name + '.png';
    await page.screenshot({ path: file, fullPage: false, raw: true });
    report.screenshots.push(file);
}
try {
    await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
    await page.goto(base + '/auth');
    if (!(await page.url()).endsWith('/auth')) {
        await submit('header form[data-request="identity::onLogout"]', '/auth');
    }
    await shot('desktop-login');
    await page.fill('#email', fixture.a.email);
    await page.fill('#password', fixture.password);
    await submit('form[data-request="identity::onLogin"]', '/workspaces');
    const workspace = `form:has(input[name="company_id"][value="${fixture.a.company_id}"])`;
    await page.selectOption(workspace + ' select', '');
    await submit(workspace, '/');
    passed('Existing frontend login and workspace selection');

    await page.goto(base + '/settings/roles');
    const owner = '#role-' + fixture.a.role_id;
    await page.click(owner + ' summary');
    const grants = await page.evaluate(selector => [...document.querySelectorAll(selector + ' input[name="permissions[]"]:checked')].map(input => input.value), owner);
    assert.equal(grants.length, 27);
    assert.ok(grants.includes('circularity.read') && grants.includes('circularity.write'));
    await submit(owner + ' form', '/settings/roles');
    await page.reload();
    await page.click(owner + ' summary');
    await shot('desktop-admin-role');
    passed('Pre-existing full administrator retains all 27 permissions and can save its role');
    await page.click('#role-' + fixture.reader_role_id + ' summary');
    const reader = await page.evaluate(id => [...document.querySelectorAll('#role-' + id + ' input[name="permissions[]"]:checked')].map(input => input.value), fixture.reader_role_id);
    assert.deepEqual(reader, ['catalogue.read']);
    passed('Delegated reader remains unchanged');

    await page.click('#main-nav a[href="/purchasing"]');
    await page.waitForURL(base + '/purchasing');
    await shot('desktop-purchasing');
    await page.click('#main-nav a[href="/inventory"]');
    await page.waitForURL(base + '/inventory');
    passed('Existing ERP purchasing and inventory navigation');
    const denied = await page.fetch('/api/circularity/v1/health', { headers: { Accept: 'application/json' } });
    assert.equal(denied.status, 401);
    passed('Browser session alone does not authorize the API');

    await page.cdp('Emulation.setDeviceMetricsOverride', mobile);
    await page.reload();
    await page.click('.navbar-toggler');
    await page.waitForSelector('#main-nav.show');
    await page.click('#main-nav a[href="/workspaces"]');
    await page.waitForURL(base + '/workspaces');
    await shot('mobile-workspaces');
    await page.selectOption(workspace + ' select', String(fixture.a.branch_id));
    await submit(workspace, '/');
    await page.click('.navbar-toggler');
    await page.waitForSelector('#main-nav.show');
    await page.click('#main-nav a[href="/catalogue/products"]');
    await page.waitForURL(base + '/catalogue/products');
    await shot('mobile-catalogue');
    passed('Mobile workspace switch and ERP navigation without overflow');
    const events = await page.events();
    const errors = events.filter(event => event.method === 'Runtime.exceptionThrown'
        || (event.method === 'Runtime.consoleAPICalled' && event.params.type === 'error'));
    assert.equal(errors.length, 0);
    report.runtimeErrors = 0;
    report.passed = true;
} finally {
    await fs.writeFile(output + '/report.json', JSON.stringify(report, null, 2) + '\n');
}
// Finish this same TaskSpace after inspecting the screenshots.
