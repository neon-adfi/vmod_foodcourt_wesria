// Guarded seed first. Resume the same space after failure with globalThis.vmodE2eSpaceId.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const crypto = await import('node:crypto');
const root = '/Users/stev/htdoc/vmod_wesria';
const output = root + '/output/ego-lite/phase-5';
const fixture = JSON.parse(await fs.readFile('/private/tmp/wesria-phase5-acceptance.json', 'utf8'));
const base = 'https://wesria.local.test';
const run = crypto.randomBytes(4).toString('hex');
const task = await taskSpace(globalThis.vmodE2eSpaceId ?? 'Wesria Phase 5 acceptance');
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
    await page.waitForFunction(() => typeof window.bootstrap?.Collapse === 'function' && typeof window.oc?.request === 'function');
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

async function formData(selector) {
    return page.evaluate(selector => Object.fromEntries(new FormData(document.querySelector(selector))), selector);
}
async function stateContains(text) {
    await page.waitForFunction(text => document.querySelector('#document-detail')?.innerText.includes(text.replaceAll('_', ' ')), text);
}
async function createSO(quantity = '6', packageId = fixture.package_id) {
    await page.goto(base + '/sales?new=1');
    await page.waitForSelector('#sales-form');
    await page.selectOption('#so-customer', String(fixture.customer_id));
    await page.selectOption('[name="lines[0][package_id]"]', String(packageId));
    await page.selectOption('[name="lines[0][location_id]"]', String(fixture.location_id));
    await page.fill('[name="lines[0][quantity]"]', quantity);
    await page.selectOption('[name="lines[0][tax_rate_id]"]', String(fixture.tax_id));
    await submit('#sales-form');
    await page.waitForSelector('#document-detail');
    return Number(new URL(await page.url()).searchParams.get('id'));
}
async function createDelivery(soId, quantity) {
    await page.goto(base + '/delivery?new=1&so=' + soId);
    await page.waitForSelector('#delivery-form');
    await page.fill('[name="lines[0][quantity]"]', quantity);
    await page.fill('#delivery-notes', 'Acceptance delivery ' + run);
    await submit('#delivery-form');
    await page.waitForSelector('#document-detail');
    return Number(new URL(await page.url()).searchParams.get('id'));
}
async function retire(kind, id) {
    await page.goto(base + '/catalogue/' + kind + '?edit=' + id + '&status=all');
    await submit('form[data-request="catalogue::onSetActive"]');
}
async function inventoryValues(expected) {
    await page.goto(base + '/inventory?q=RICE');
    const values = await page.evaluate(() => [...document.querySelectorAll('.table tbody tr:first-child td')].slice(2,5).map(td => td.textContent.trim()));
    assert.deepEqual(values, expected);
}
try {
    await logout(); await login(fixture.a); await workspace(fixture.a.company_id, fixture.a.branch_id);
    await page.goto(base + '/sales');
    await page.click('a:has-text("Next")');
    assert.equal(new URL(await page.url()).searchParams.get('page'), '2');
    await page.fill('#document-search', 'SO-000001');
    await page.click('form[aria-label="Search sales orders"] button');
    await page.waitForFunction(() => document.body.innerText.includes('1 documents'));
    passed('Sales search and pagination');

    await page.goto(base + '/sales?new=1');
    const options = await page.evaluate(() => [...document.querySelector('[name="lines[0][package_id]"]').options].map(o => o.value));
    assert.equal(options.includes(String(fixture.blank_id)), false);
    assert.equal(options.includes(String(fixture.zero_id)), true);
    await page.selectOption('#so-customer', String(fixture.customer_id));
    await page.selectOption('[name="lines[0][package_id]"]', String(fixture.package_id));
    await page.selectOption('[name="lines[0][location_id]"]', String(fixture.location_id));
    await page.fill('[name="lines[0][quantity]"]', '1.00001');
    await page.fill('#so-notes', 'Preserve these sales notes');
    await page.click('#sales-form button[type="submit"]');
    await page.waitForSelector('[data-validate-for="lines.0.quantity"]:not(:empty)');
    assert.equal(await page.evaluate(() => document.querySelector('#so-notes').value), 'Preserve these sales notes');
    assert.equal(await page.evaluate(() => document.activeElement.name), 'lines[0][quantity]');
    await screenshot('sales-validation');
    await page.fill('[name="lines[0][quantity]"]', '6');
    await page.selectOption('[name="lines[0][tax_rate_id]"]', String(fixture.tax_id));
    await page.click('[data-request="sales::onPreview"]');
    await page.waitForFunction(() => document.querySelector('#sales-preview').innerText.includes('63.60'));
    await submit('#sales-form');
    const soId = Number(new URL(await page.url()).searchParams.get('id'));
    await page.reload(); await stateContains('63.60');
    passed('Priced-only selection, nested validation/focus, preview/save/reload');

    await page.selectOption('#so-mode', 'inclusive'); await submit('#sales-form'); await stateContains('10.6000');
    await page.goto(base + '/catalogue/packages?edit=' + fixture.package_id);
    await page.fill('[name="sales_price"]', '12'); await submit('#catalogue-form');
    await page.goto(base + '/sales?id=' + soId); await page.fill('#so-notes', 'Retain saved commercial snapshot'); await submit('#sales-form');
    await stateContains('10.6000');
    await page.selectOption('[name="lines[0][price_action]"]', 'catalogue'); await submit('#sales-form'); await stateContains('12.7200');
    await screenshot('sales-revisions');
    const savedForm = await formData('#sales-form');
    assert.equal((await ajax('sales::onSaveOrder', {...savedForm, operation_key: crypto.randomUUID()}, {csrf:false})).status, 419);
    assert.equal((await ajax('sales::onSaveOrder', {...savedForm, operation_key: crypto.randomUUID()}, {context:'stale-context'})).status, 409);
    await page.fill('#so-notes', 'Advance version'); await submit('#sales-form');
    assert.equal((await ajax('sales::onSaveOrder', {...savedForm, operation_key: crypto.randomUUID()})).status, 409);
    passed('Stable conversion, frozen snapshots/reprice; real CSRF, stale context/version');

    const insufficientId = await createSO('11');
    await page.click('form[data-request="sales::onApprove"] button');
    await page.waitForFunction(() => document.querySelector('#request-error').textContent.includes('Insufficient available'));
    await stateContains('draft');
    await screenshot('approval-insufficient');
    const zeroId = await createSO('1', fixture.zero_id); await stateContains('Total 0.00');
    await page.goto(base + '/sales?id=' + soId);
    const approval = await formData('form[data-request="sales::onApprove"]');
    await submit('form[data-request="sales::onApprove"]'); await stateContains('approved');
    assert.equal((await ajax('sales::onApprove',approval)).status,200);
    await inventoryValues(['10.0000 kg','6.0000','4.0000']);
    await page.selectOption('#stock-product',String(fixture.product_id)); await page.selectOption('#stock-location',String(fixture.location_id));
    await page.selectOption('#stock-direction','decrease'); await page.fill('#stock-quantity','5'); await page.fill('#stock-reason','Respect reservations');
    await page.click('#stock-form button[type="submit"]'); await page.waitForFunction(() => document.querySelector('#request-error').textContent.includes('Insufficient available'));
    assert.equal(await page.evaluate(() => document.querySelector('#stock-quantity').value),'5');
    await page.fill('#stock-quantity','4'); await submit('#stock-form');
    await inventoryValues(['6.0000 kg','6.0000','0.0000']);
    await page.selectOption('#stock-product',String(fixture.product_id)); await page.selectOption('#stock-location',String(fixture.location_id));
    await page.fill('#stock-quantity','4'); await page.fill('#stock-reason','Restore acceptance count'); await submit('#stock-form');
    await inventoryValues(['10.0000 kg','6.0000','4.0000']); await screenshot('inventory-reservations');
    passed('Zero-price save, failed/successful approvals and protected adjustments');

    for(const [kind,id] of [['parties',fixture.customer_id],['products',fixture.product_id],['packages',fixture.package_id],['locations',fixture.location_id]]) await retire(kind,id);
    const firstId = await createDelivery(soId,'2');
    const dispatch = await formData('form[data-request="sales::onDispatch"]');
    await submit('form[data-request="sales::onDispatch"]'); await stateContains('dispatched');
    assert.equal((await ajax('sales::onDispatch',dispatch)).status,200);
    await page.goto(base + '/sales?id=' + soId); await stateContains('partially_delivered');
    await inventoryValues(['8.0000 kg','4.0000','4.0000']);
    const fullId = await createDelivery(soId,'4'); await submit('form[data-request="sales::onDispatch"]');
    await page.goto(base + '/sales?id=' + soId); await stateContains('completed'); await screenshot('sales-completed');
    await inventoryValues(['4.0000 kg','0.0000','4.0000']);
    passed('Retired masters remain dispatchable; partial/full dispatch consumes own reservations once');

    const excessId = await createDelivery(soId,'1');
    assert.equal((await ajax('sales::onDispatch',await formData('form[data-request="sales::onDispatch"]'))).status,409);
    await page.goto(base + '/delivery?id=' + fullId);
    await page.fill('[name="lines[0][quantity]"]','3'); await page.fill('#correction-reason','Only three delivered');
    const correction = await formData('#delivery-form');
    await submit('#delivery-form');
    const correctedId = Number(new URL(await page.url()).searchParams.get('id'));
    assert.notEqual(correctedId,fullId); await stateContains('dispatched'); await screenshot('delivery-correction');
    assert.equal((await ajax('sales::onCorrect',correction)).status,200);
    assert.equal((await ajax('sales::onCorrect',{...correction,reason:'Changed retry'})).status,409);
    await inventoryValues(['5.0000 kg','1.0000','4.0000']);
    for(const id of [firstId,correctedId]) {
        await page.goto(base + '/delivery?id='+id); await page.fill('#correction-reason','Entire delivery entered in error');
        await page.click('[data-reverse-only]'); await submit('#delivery-form'); await stateContains('voided');
    }
    await inventoryValues(['10.0000 kg','6.0000','4.0000']);
    await page.goto(base + '/sales?id='+soId); await stateContains('approved');
    assert.equal(await page.evaluate(() => !!document.querySelector('form[data-request="sales::onReopen"]')),false);
    await page.fill('#reason-onCloseShort','Release restored remainder'); await submit('form[data-request="sales::onCloseShort"]'); await stateContains('closed_short');
    await page.goto(base + '/delivery?id='+excessId); await stateContains('voided');
    assert.equal(await page.evaluate(() => !!document.querySelector('#delivery-form')),false);
    await inventoryValues(['10.0000 kg','0.0000','10.0000']);
    passed('Over-dispatch/retry rejection; correction/full reversal and permanent historical freeze');

    for(const [kind,id] of [['parties',fixture.customer_id],['products',fixture.product_id],['packages',fixture.package_id],['locations',fixture.location_id]]) await retire(kind,id);
    const reopenedId = await createSO('1'); await submit('form[data-request="sales::onApprove"]');
    const oldDraft = await createDelivery(reopenedId,'1');
    const draft = await formData('#delivery-form'); delete draft.id; delete draft.version;
    for(let n=0;n<15;n++) assert.equal((await ajax('sales::onSaveDelivery',{...draft,operation_key:crypto.randomUUID()})).status,200);
    await page.goto(base+'/delivery'); await page.click('a:has-text("Next")');
    assert.equal(new URL(await page.url()).searchParams.get('page'),'2');
    await page.fill('#document-search','DO-000001'); await page.click('form[aria-label="Search delivery orders"] button');
    await page.waitForFunction(() => document.body.innerText.includes('1 documents'));
    await page.goto(base+'/sales?id='+reopenedId); await page.fill('#reason-onReopen','Edit before any dispatch'); await submit('form[data-request="sales::onReopen"]'); await stateContains('draft');
    await page.goto(base+'/delivery?id='+oldDraft); await stateContains('voided');
    await page.goto(base+'/sales?id='+reopenedId); await submit('form[data-request="sales::onApprove"]'); await stateContains('approved');
    await page.fill('#reason-onVoid','No longer required'); await submit('form[data-request="sales::onVoid"]'); await stateContains('voided');
    await inventoryValues(['10.0000 kg','0.0000','10.0000']);
    passed('Delivery search/pagination; reopen releases and voids drafts, reapproval and void');

    await page.goto(base+'/sales?new=1');
    const staleContext=await page.evaluate(() => document.querySelector('#sales-form').dataset.workspaceContext);
    await workspace(fixture.a.company_id,fixture.restricted_branch_id);
    assert.equal((await ajax('sales::onSaveOrder',savedForm,{context:staleContext,url:'/sales'})).status,409);
    assert.equal((await page.fetch('/sales?id='+soId)).status,404);
    await logout(); await login(fixture.b); await workspace(fixture.b.company_id,fixture.b.branch_id);
    assert.equal((await page.fetch('/sales?id='+soId)).status,404);
    await workspace(fixture.a.company_id); await page.goto(base+'/sales');
    assert.equal(await page.evaluate(() => !!document.querySelector('a[href="/sales?new=1"]')),false);
    assert.equal((await page.fetch('/sales?id='+fixture.restricted_order_id)).status,404);
    assert.equal((await page.fetch('/inventory')).status,403);
    await page.goto(base+'/workspaces');
    const readerContext=await page.evaluate(() => document.querySelector('form[data-workspace-context]').dataset.workspaceContext);
    assert.equal((await ajax('sales::onApprove',{id:insufficientId,version:'1',operation_key:crypto.randomUUID()},{context:readerContext,url:'/sales'})).status,403);
    assert.equal((await ajax('sales::onDispatch',dispatch,{context:readerContext,url:'/delivery'})).status,403);
    await page.goto(base+'/sales?id='+soId); await screenshot('sales-reader');
    assert.equal(await page.evaluate(() => document.body.innerText.includes('On hand')),false);
    passed('Tenant/branch isolation, restricted actions, denied replay and inventory privacy');

    await logout(); await login(fixture.a); await workspace(fixture.a.company_id,fixture.a.branch_id);
    await page.cdp('Emulation.setDeviceMetricsOverride',mobile);
    await page.goto(base+'/sales?new=1'); await page.focus('#so-customer'); await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id),'so-branch'); await screenshot('mobile-sales',mobile);
    await page.goto(base+'/delivery?id='+correctedId); await screenshot('mobile-delivery',mobile);
    await page.goto(base+'/inventory'); await screenshot('mobile-inventory',mobile);
    passed('Mobile layouts, native fields and keyboard order');
    await page.cdp('Emulation.setDeviceMetricsOverride',desktop);
    await page.goto(base+'/sales?id='+soId);
    const events=await page.events();
    report.runtimeErrors=events.filter(e=>e.method==='Runtime.exceptionThrown'||(e.method==='Runtime.consoleAPICalled'&&e.params.type==='error'));
    assert.equal(report.runtimeErrors.length,0,JSON.stringify(report.runtimeErrors).slice(0,1000));
    report.passed=true; report.records={soId,firstId,fullId,correctedId,excessId,reopenedId,zeroId,insufficientId};
    console.log({passed:true,scenarios:report.scenarios.length,screenshots:report.screenshots.length,runtimeErrors:report.runtimeErrors.length});
} catch(error) {
    report.error=String(error); console.log({passed:false,error:String(error),url:await page.url()});
    console.log(await page.snapshot()); throw error;
} finally {
    await fs.writeFile(output+'/report.json',JSON.stringify(report,null,2));
}
// Inspect the screenshots before finishing this one TaskSpace.
