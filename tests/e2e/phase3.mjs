// Guarded seed first. Resume the same space after failure with globalThis.vmodE2eSpaceId.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const crypto = await import('node:crypto');
const root = '/Users/stev/htdoc/vmod_wesria';
const output = root + '/output/ego-lite/phase-3';
const fixture = JSON.parse(await fs.readFile('/private/tmp/wesria-phase3-acceptance.json', 'utf8'));
const base = 'https://wesria.local.test';
const run = crypto.randomBytes(4).toString('hex');
const task = await taskSpace(globalThis.vmodE2eSpaceId ?? 'Wesria Phase 3 acceptance');
const page = task.page('p1');
console.log({ spaceId: task.spaceId });
const report = { date: new Date().toISOString(), spaceId: task.spaceId, runtime: fixture.runtime, scenarios: [], screenshots: [], passed: false };
await fs.mkdir(output, { recursive: true });
await page.cdp('Runtime.enable');
await page.cdp('Log.enable');
await page.cdp('Network.enable');
await page.cdp('Network.setCacheDisabled', { cacheDisabled: true });
await page.events();
const desktop = { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false };
const mobile = { width: 390, height: 844, deviceScaleFactor: 1, mobile: true };
await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
const passed = name => { report.scenarios.push({ name, passed: true }); console.log('PASS ' + name); };

async function screenshot(name, viewport = desktop) {
    const state = await page.evaluate(async () => {
        await document.fonts.ready;
        return { width: innerWidth, height: innerHeight, contentWidth: document.documentElement.scrollWidth,
            assets: [...document.querySelectorAll('link[rel="stylesheet"]')].every(link => !!link.sheet)
                && typeof window.bootstrap?.Collapse === 'function' && typeof window.oc?.request === 'function' };
    });
    assert.equal(state.width, viewport.width);
    assert.equal(state.height, viewport.height);
    assert.ok(state.contentWidth <= state.width + 1, 'No horizontal page overflow');
    assert.ok(state.assets, 'Tabler and native October assets loaded');
    const file = output + '/' + name + '.png';
    await page.screenshot({ path: file, fullPage: false, raw: true });
    const bytes = await fs.readFile(file);
    assert.equal(bytes.readUInt32BE(16), viewport.width);
    assert.equal(bytes.readUInt32BE(20), viewport.height);
    report.screenshots.push({ name, file, sha256: crypto.createHash('sha256').update(bytes).digest('hex') });
}

async function submit(form, path) {
    await page.evaluate(() => { document.documentElement.dataset.acceptanceNavigation = 'pending'; });
    await page.click(form + ' button[type="submit"]');
    await page.waitForFunction(() => !!document.documentElement && !document.documentElement.dataset.acceptanceNavigation, undefined, { timeout: 15000 });
    if (path) await page.waitForURL(base + path);
}

async function logout() {
    await page.goto(base + '/account');
    if ((await page.url()).includes('/account')) {
        if (await page.evaluate(() => innerWidth < 768)) {
            await page.click('.navbar-toggler');
            await page.waitForSelector('#main-nav.show');
        }
        await submit('header form[data-request="identity::onLogout"]', '/auth');
    }
}

async function login(account) {
    await page.goto(base + '/auth');
    await page.fill('#email', account.email);
    await page.fill('#password', fixture.password);
    await submit('form[data-request="identity::onLogin"]', '/workspaces');
}

async function workspace(companyId, branchId = '') {
    await page.goto(base + '/workspaces');
    const form = `form:has(input[name="company_id"][value="${companyId}"])`;
    await page.selectOption(form + ' select', String(branchId));
    await submit(form, '/');
}

async function ajax(handler, data, { context, csrf = true, url } = {}) {
    return page.evaluate(async ({ handler, data, context, csrf, url }) => {
        const form = document.querySelector('form[data-workspace-context]');
        const headers = { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-AJAX-HANDLER': handler };
        if (context !== null) headers['X-Workspace-Context'] = context ?? form?.dataset.workspaceContext ?? '';
        const body = new URLSearchParams(data);
        if (csrf) body.set('_token', document.querySelector('input[name="_token"]').value);
        else body.delete('_token');
        const response = await fetch(url || location.href, { method: 'POST', headers, body });
        return { status: response.status, body: await response.text() };
    }, { handler, data, context, csrf, url });
}

async function editor(kind, id) {
    await page.goto(base + '/catalogue/' + kind + (id ? '?edit=' + id + '&status=all' : '?new=1') + '#catalogue-editor');
    await page.waitForSelector('#catalogue-form');
}

async function save() {
    await submit('#catalogue-form');
    await page.waitForFunction(() => !!document.querySelector('#catalogue-form input[name="id"]')?.value);
    return page.evaluate(() => document.querySelector('#catalogue-form input[name="id"]').value);
}

async function toggle(kind, id) {
    await editor(kind, id);
    await submit('form[data-request="catalogue::onSetActive"]');
}

const probe = output + '/checkout-' + run + '.txt';
try {
    await fs.writeFile(probe, run, { flag: 'wx' });
    await logout();
    const served = await page.evaluate(async path => (await fetch(path, { cache: 'no-store' })).text(), '/output/ego-lite/phase-3/checkout-' + run + '.txt');
    assert.equal(served, run);
    passed('Exact served checkout provenance');
    await login(fixture.a);
    await workspace(fixture.a.company_id);
    await page.goto(base + '/catalogue/products');
    assert.equal(await page.evaluate(() => document.querySelectorAll('tr[data-record-id]').length), 25);
    await page.click('loc=role:link[name="Next"]');
    assert.ok(await page.evaluate(() => document.querySelector('.card-footer').innerText.includes('Page 2')));
    await page.fill('#catalogue-search', 'Pagination item 07');
    await submit('form[aria-label="Search catalogue"]');
    assert.equal(await page.evaluate(() => document.querySelectorAll('tr[data-record-id]').length), 1);
    await screenshot('desktop-search');
    passed('Search and native pagination');

    await editor('products');
    await page.focus('#sku'); await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'name');
    await page.fill('#sku', 'BROWSER-' + run);
    await page.fill('#name', 'Browser rice ' + run);
    await page.fill('#base_uom', 'kg');
    await page.fill('#description', 'Acceptance rice');
    const product = await save();
    await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#base_uom').value), 'kg');
    passed('Native product create/reload and keyboard field order');

    await editor('parties');
    await page.fill('#code', 'SUP-' + run); await page.fill('#name', 'Browser supplier ' + run);
    await page.click('#catalogue-form button[type="submit"]');
    await page.waitForFunction(() => document.activeElement.name === 'is_customer' && document.activeElement.type === 'checkbox');
    await page.click('input[type="checkbox"][name="is_supplier"]');
    await page.click('input[type="checkbox"][name="is_customer"]');
    await page.fill('#address', 'Synthetic acceptance address');
    const party = await save();
    await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#address').value), 'Synthetic acceptance address');
    await screenshot('desktop-party');
    passed('Customer/supplier validation focuses visible checkbox; flags/contact details save/reload');

    await editor('packages'); await page.selectOption('#product_id', product);
    await page.fill('#code', 'BAG'); await page.fill('#name', 'Rice bag ' + run); await page.fill('#conversion', '2.5');
    const productInput = { id: product, version: '1', sku: 'BROWSER-' + run, name: 'Browser rice ' + run, base_uom: 'g' };
    assert.equal((await ajax('catalogue::onSaveProduct', productInput)).status, 200);
    await page.click('#catalogue-form button[type="submit"]');
    await page.waitForFunction(() => document.querySelector('#request-error').innerText.includes('base unit changed'));
    assert.equal(await page.evaluate(() => document.querySelector('#conversion').value), '2.5');
    assert.equal((await ajax('catalogue::onSaveProduct', { ...productInput, version: '2', base_uom: 'kg' })).status, 200);
    const pack = await save();
    await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#sales_price').value), '');
    assert.ok(await page.evaluate(() => document.querySelector('#conversion').readOnly));
    await editor('products', product);
    assert.ok(await page.evaluate(() => document.querySelector('#base_uom').readOnly));
    await page.goto(base + '/settings/company');
    assert.equal(await page.evaluate(() => document.querySelector('#company-currency').readOnly), false);
    await page.fill('#company-currency', 'SGD');
    await submit('form[data-request="workspaces::onCompany"]', '/settings/company');
    passed('Stale base-unit form rejects; blank package persists, conversion/UOM lock and currency remains editable');

    await editor('packages', pack);
    const packageData = await page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('#catalogue-form'))));
    assert.equal((await ajax('catalogue::onSavePackage', { ...packageData, expected_currency: 'MYR', sales_price: '1' })).status, 409);
    await page.fill('#name', 'Preserved bag ' + run); await page.fill('#sales_price', '1.00001');
    await page.click('#catalogue-form button[type="submit"]');
    await page.waitForFunction(() => document.querySelector('#sales_price').classList.contains('is-invalid'));
    assert.equal(await page.evaluate(() => document.activeElement.id), 'sales_price');
    assert.equal(await page.evaluate(() => document.querySelector('#name').value), 'Preserved bag ' + run);
    await screenshot('desktop-validation');
    await page.fill('#sales_price', '0'); await save(); await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#sales_price').value), '0.0000');
    await page.fill('#sales_price', ''); await save();
    await page.goto(base + '/settings/company');
    assert.ok(await page.evaluate(() => document.querySelector('#company-currency').readOnly));
    const currencyData = await page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form[data-request="workspaces::onCompany"]'))));
    assert.equal((await ajax('workspaces::onCompany', { ...currencyData, currency: 'MYR' })).status, 409);
    await screenshot('desktop-currency-lock');
    passed('Stale currency, precision validation/input focus, zero price and permanent currency lock after clearing');

    await editor('offers');
    await page.selectOption('#supplier_id', party); await page.selectOption('#package_id', pack); await page.fill('#unit_price', '10');
    const offer = await save(); await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#unit_price').value), '10.0000');
    await toggle('offers', offer);
    await editor('offers'); await page.selectOption('#supplier_id', party); await page.selectOption('#package_id', pack); await page.fill('#unit_price', '12');
    await page.click('#catalogue-form button[type="submit"]');
    await page.waitForFunction(() => document.querySelector('[data-validate-for="package_id"]').innerText.includes('already exists'));
    await toggle('offers', offer);
    await editor('offers', offer); await screenshot('desktop-offer');
    passed('Explicit supplier price, duplicate inactive offer rejection and restoration');

    await editor('tax_rates'); await page.fill('#name', 'Example six ' + run); await page.fill('#rate', '6');
    await save(); await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#rate').value), '6.0000');
    await workspace(fixture.a.company_id, fixture.a.branch_id);
    await editor('locations');
    assert.equal(await page.evaluate(() => document.querySelectorAll('#branch_id option[value]:not([value=""])').length), 1);
    await page.selectOption('#branch_id', String(fixture.a.branch_id)); await page.fill('#code', 'BROWSER-' + run); await page.fill('#name', 'Browser stock room');
    await save(); await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#name').value), 'Browser stock room');
    await screenshot('desktop-location');
    passed('Tax rate and branch-authorized location save/reload');

    await toggle('products', product);
    await editor('packages', pack); await page.fill('#name', 'Edited unavailable bag'); await save();
    await editor('offers', offer); await page.fill('#unit_price', '11'); await save();
    await page.goto(base + '/catalogue/packages');
    assert.equal(await page.evaluate(() => document.querySelectorAll('tr[data-record-id]').length), 0);
    await page.goto(base + '/catalogue/packages?status=inactive');
    assert.ok(await page.evaluate(() => document.querySelector('tbody').innerText.includes('Product inactive')));
    await toggle('products', product);
    await page.goto(base + '/catalogue/packages');
    assert.equal(await page.evaluate(() => document.querySelectorAll('tr[data-record-id]').length), 1);
    assert.ok(await page.evaluate(() => document.querySelector('tbody').innerText.includes('Sales price not set')));
    await toggle('offers', offer); await toggle('parties', party); await toggle('parties', party);
    await page.goto(base + '/catalogue/offers');
    assert.equal(await page.evaluate(() => document.querySelectorAll('tr[data-record-id]').length), 0);
    await toggle('offers', offer);
    passed('Unavailable records stay editable; retirement/restoration preserves independent child flags');

    await editor('products', product);
    const edit = await page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('#catalogue-form'))));
    assert.equal((await ajax('catalogue::onSaveProduct', { ...edit, name: 'Winner ' + run })).status, 200);
    assert.equal((await ajax('catalogue::onSaveProduct', { ...edit, name: 'Loser' })).status, 409);
    assert.equal((await ajax('catalogue::onSaveProduct', edit, { csrf: false })).status, 419);
    assert.equal((await ajax('catalogue::onSaveProduct', edit, { context: null })).status, 409);
    const oldContext = await page.evaluate(() => document.querySelector('#catalogue-form').dataset.workspaceContext);
    await workspace(fixture.a.company_id, fixture.restricted_branch_id);
    await editor('products', product);
    assert.equal((await ajax('catalogue::onSaveProduct', edit, { context: oldContext })).status, 409);
    assert.equal(await page.evaluate(() => document.querySelector('#name').value), 'Winner ' + run);
    passed('Atomic stale edit, real CSRF 419 and missing/switched workspace context 409');

    await logout(); await login(fixture.b); await workspace(fixture.a.company_id);
    await page.goto(base + '/workspaces');
    const readerContext = await page.evaluate(() => document.querySelector('form[data-workspace-context]').dataset.workspaceContext);
    await page.goto(base + '/catalogue/products');
    assert.equal(await page.evaluate(() => !!document.querySelector('a[href*="new=1"]')), false);
    const forbidden = await ajax('catalogue::onSaveProduct', {}, { context: readerContext });
    assert.equal(forbidden.status, 403);
    await page.goto(base + '/catalogue/locations');
    assert.equal(await page.evaluate(() => document.querySelector('tbody').innerText.includes('Restricted store')), false);
    const restricted = await page.evaluate(async id => (await fetch('/catalogue/locations?edit=' + id)).status, fixture.restricted_location_id);
    assert.equal(restricted, 404);
    await workspace(fixture.b.company_id);
    await page.goto(base + '/catalogue/products');
    assert.equal(await page.evaluate(() => document.querySelectorAll('tr[data-record-id]').length), 0);
    const foreign = await page.evaluate(async id => (await fetch('/catalogue/products?edit=' + id)).status, product);
    assert.equal(foreign, 404);
    passed('Read-only UI/write denial, authorized-branch company view and foreign-tenant 404');

    await logout(); await login(fixture.a); await workspace(fixture.a.company_id);
    await page.cdp('Emulation.setDeviceMetricsOverride', mobile);
    await page.goto(base + '/catalogue/packages'); await screenshot('mobile-packages', mobile);
    await page.click('.navbar-toggler'); await page.waitForSelector('#main-nav.show');
    await page.click('#main-nav a[href="/settings/company"]'); await page.waitForURL(base + '/settings/company');
    await screenshot('mobile-company', mobile);
    await editor('packages', pack); await page.focus('#sales_price');
    await screenshot('mobile-package-editor', mobile);
    await page.fill('#sales_price', '2.5'); await save(); await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#sales_price').value), '2.5000');
    passed('Mobile navigation, responsive table/form and native save/reload');

    const events = await page.events();
    const exceptions = events.filter(event => event.method === 'Runtime.exceptionThrown' || (event.method === 'Runtime.consoleAPICalled' && event.params.type === 'error'));
    assert.equal(exceptions.length, 0, 'No JavaScript runtime exceptions or console errors');
    report.runtimeErrors = 0;
    report.expectedRejections = [403, 404, 406, 409, 419];
    passed('No unexpected browser runtime errors');
    report.passed = true;
    // Finish the one TaskSpace only after the caller visually inspects screenshots.
} catch (error) {
    report.failure = error.name + ': ' + error.message.replace(/https:\/\/[^\s]+/g, '[URL omitted]');
    throw new Error(report.failure);
} finally {
    await fs.unlink(probe).catch(() => {});
    await fs.writeFile(output + '/report.json', JSON.stringify(report, null, 2) + '\n');
}
