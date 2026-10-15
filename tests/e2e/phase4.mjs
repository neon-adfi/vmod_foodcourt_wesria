// Guarded seed first. Resume the same space after failure with globalThis.vmodE2eSpaceId.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const crypto = await import('node:crypto');
const root = '/Users/stev/htdoc/vmod_wesria';
const output = root + '/output/ego-lite/phase-4';
const fixture = JSON.parse(await fs.readFile('/private/tmp/wesria-phase4-acceptance.json', 'utf8'));
const base = 'https://wesria.local.test';
const run = crypto.randomBytes(4).toString('hex');
const task = await taskSpace(globalThis.vmodE2eSpaceId ?? 'Wesria Phase 4 acceptance');
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
    await page.waitForFunction(text => document.querySelector('#document-detail')?.innerText.includes(text), text);
}
async function createPO(quantity = '3') {
    await page.goto(base + '/purchasing?new=1');
    await page.waitForSelector('#purchase-form');
    await page.selectOption('#po-supplier', String(fixture.supplier_id));
    await page.selectOption('[name="lines[0][offer_id]"]', String(fixture.offer_id));
    await page.fill('[name="lines[0][quantity]"]', quantity);
    await page.selectOption('[name="lines[0][tax_rate_id]"]', String(fixture.tax_id));
    await submit('#purchase-form');
    await page.waitForSelector('#document-detail');
    return Number(new URL(await page.url()).searchParams.get('id'));
}
async function createReceipt(poId, quantity, reference) {
    await page.goto(base + '/receiving?new=1&po=' + poId);
    await page.waitForSelector('#receipt-form');
    await page.fill('#receipt-ref', reference);
    await page.selectOption('[name="lines[0][location_id]"]', String(fixture.location_id));
    await page.fill('[name="lines[0][quantity]"]', quantity);
    await submit('#receipt-form');
    await page.waitForSelector('#document-detail');
    return Number(new URL(await page.url()).searchParams.get('id'));
}
try {
    await logout();
    await login(fixture.a);
    await workspace(fixture.a.company_id, fixture.a.branch_id);
    await page.goto(base + '/purchasing');
    await page.waitForSelector('a:has-text("Next")');
    await page.click('a:has-text("Next")');
    assert.ok(new URL(await page.url()).searchParams.get('page') === '2');
    await page.fill('#document-search', 'PO-000001');
    await page.click('form[aria-label="Search purchase orders"] button');
    await page.waitForFunction(() => document.body.innerText.includes('1 documents'));
    passed('Search and pagination');

    await page.goto(base + '/purchasing?new=1');
    await page.selectOption('#po-supplier', String(fixture.supplier_id));
    await page.selectOption('[name="lines[0][offer_id]"]', String(fixture.offer_id));
    await page.fill('[name="lines[0][quantity]"]', '1.00001');
    await page.fill('#po-notes', 'Preserve these buyer notes');
    await page.click('#purchase-form button[type="submit"]');
    await page.waitForSelector('[data-validate-for="lines.0.quantity"]:not(:empty)');
    assert.equal(await page.evaluate(() => document.querySelector('#po-notes').value), 'Preserve these buyer notes');
    assert.equal(await page.evaluate(() => document.activeElement.name), 'lines[0][quantity]');
    await screenshot('nested-validation');
    await page.fill('[name="lines[0][quantity]"]', '3');
    await page.selectOption('[name="lines[0][tax_rate_id]"]', String(fixture.tax_id));
    await page.click('[data-request="purchasing::onPreview"]');
    await page.waitForFunction(() => document.querySelector('#purchase-preview').innerText.includes('31.80'));
    await submit('#purchase-form');
    const poId = Number(new URL(await page.url()).searchParams.get('id'));
    await page.reload();
    await stateContains('31.80');
    passed('PO preview/save/reload and nested validation preservation');
    await page.selectOption('#po-mode', 'inclusive');
    await submit('#purchase-form');
    await stateContains('10.6000');
    await page.selectOption('[name="lines[0][price_action]"]', 'offer');
    await submit('#purchase-form');
    await stateContains('Revision 3');
    await screenshot('purchase-revisions');
    passed('Explicit inclusive conversion and reprice revision');
    const savedForm = await formData('#purchase-form');
    assert.equal((await ajax('purchasing::onSaveOrder', { ...savedForm, operation_key: crypto.randomUUID() }, { csrf: false })).status, 419);
    assert.equal((await ajax('purchasing::onSaveOrder', { ...savedForm, operation_key: crypto.randomUUID() }, { context: 'stale-context' })).status, 409);
    await submit('form[data-request="purchasing::onApprove"]');
    await stateContains('approved');
    assert.equal((await ajax('purchasing::onSaveOrder', { ...savedForm, operation_key: crypto.randomUUID() })).status, 409);
    passed('Approval freezes revision; stale version/context and real CSRF');

    const firstId = await createReceipt(poId, '1', 'DO-' + run + '-A');
    const firstConfirm = await formData('form[data-request="purchasing::onConfirm"]');
    await submit('form[data-request="purchasing::onConfirm"]');
    await stateContains('confirmed');
    assert.equal((await ajax('purchasing::onConfirm', firstConfirm)).status, 200);
    await page.goto(base + '/purchasing?id=' + poId);
    await stateContains('partially_received');
    await page.goto(base + '/inventory?q=RICE');
    assert.ok((await page.evaluate(() => document.body.innerText)).includes('2.0000 kg'));
    passed('Partial receipt posts once and updates stock');
    const fullId = await createReceipt(poId, '2', 'DO-' + run + '-B');
    await submit('form[data-request="purchasing::onConfirm"]');
    await page.goto(base + '/purchasing?id=' + poId);
    await stateContains('completed');
    await screenshot('completed-purchase');
    passed('Full receipt completes PO');

    const excessId = await createReceipt(poId, '1', 'DO-' + run + '-EXCESS');
    const excess = await formData('form[data-request="purchasing::onConfirm"]');
    assert.equal((await ajax('purchasing::onConfirm', excess)).status, 409);
    await page.goto(base + '/receiving?id=' + fullId);
    await page.fill('#receipt-ref', 'DO-' + run + '-B-FIX');
    await page.fill('[name="lines[0][quantity]"]', '1');
    await page.fill('#correction-reason', 'Correct quantity and supplier reference');
    await submit('#receipt-form');
    const correctedId = Number(new URL(await page.url()).searchParams.get('id'));
    assert.notEqual(correctedId, fullId);
    await stateContains('DO-' + run + '-B-FIX');
    await screenshot('receipt-correction');
    const duplicateId = await createReceipt(poId, '1', 'do-' + run + '-b');
    assert.equal((await ajax('purchasing::onConfirm', await formData('form[data-request="purchasing::onConfirm"]'))).status, 409);
    passed('Over-receipt rejected; correction retains both reference claims');

    for (const id of [firstId, correctedId]) {
        await page.goto(base + '/receiving?id=' + id);
        await page.fill('#correction-reason', 'Receipt entirely entered in error');
        await page.click('[data-reverse-only]');
        await submit('#receipt-form');
        await stateContains('voided');
    }
    await page.goto(base + '/purchasing?id=' + poId);
    await stateContains('approved');
    assert.equal(await page.evaluate(() => !!document.querySelector('form[data-request="purchasing::onReopen"]')), false);
    await page.fill('#reason-onCloseShort', 'Close remaining demand after correction');
    await submit('form[data-request="purchasing::onCloseShort"]');
    await stateContains('closed_short');
    await page.goto(base + '/receiving?id=' + excessId);
    await stateContains('voided');
    passed('Full reversal preserves historical freeze; close-short voids drafts');

    const reopenedId = await createPO('1');
    await submit('form[data-request="purchasing::onApprove"]');
    const oldDraft = await createReceipt(reopenedId, '1', 'DO-' + run + '-REOPEN');
    await page.goto(base + '/purchasing?id=' + reopenedId);
    await page.fill('#reason-onReopen', 'Edit before receiving');
    await submit('form[data-request="purchasing::onReopen"]');
    await stateContains('draft');
    await page.goto(base + '/receiving?id=' + oldDraft);
    await stateContains('voided');
    passed('Pre-receipt reopening voids saved receipt drafts');

    await page.goto(base + '/inventory');
    await page.selectOption('#stock-product', String(fixture.loose_id));
    await page.selectOption('#stock-location', String(fixture.location_id));
    await page.fill('#stock-quantity', '5');
    await page.fill('#stock-reason', 'Opening browser count ' + run);
    await page.evaluate(() => { document.documentElement.dataset.acceptanceNavigation = 'pending'; });
    await page.click('[data-request="inventory::onOpen"]');
    await page.waitForFunction(() => !document.documentElement.dataset.acceptanceNavigation && typeof window.bootstrap?.Collapse === 'function');
    assert.ok((await page.evaluate(() => document.body.innerText)).includes('5.0000 kg'));
    await page.selectOption('#stock-product', String(fixture.loose_id));
    await page.selectOption('#stock-location', String(fixture.location_id));
    await page.fill('#stock-quantity', '6');
    await page.selectOption('#stock-direction', 'decrease');
    await page.fill('#stock-reason', 'Count reduction ' + run);
    await page.click('#stock-form button[type="submit"]');
    await page.waitForFunction(() => !document.querySelector('#request-error').classList.contains('d-none'));
    assert.equal(await page.evaluate(() => document.querySelector('#stock-quantity').value), '6');
    await page.fill('#stock-quantity', '2');
    await submit('#stock-form');
    assert.ok((await page.evaluate(() => document.body.innerText)).includes('3.0000 kg'));
    await screenshot('stock-ledger');
    passed('Opening and reasoned adjustments; unsafe reduction preserves input');

    await page.goto(base + '/purchasing?new=1');
    const staleContext = await page.evaluate(() => document.querySelector('#purchase-form').dataset.workspaceContext);
    await workspace(fixture.a.company_id, fixture.restricted_branch_id);
    const staleResponse = await ajax('purchasing::onSaveOrder', savedForm, { context: staleContext, url: '/purchasing' });
    assert.equal(staleResponse.status, 409);
    const foreign = await page.fetch('/purchasing?id=' + poId);
    assert.equal(foreign.status, 404);
    await logout(); await login(fixture.b); await workspace(fixture.b.company_id, fixture.b.branch_id);
    assert.equal((await page.fetch('/purchasing?id=' + poId)).status, 404);
    await workspace(fixture.a.company_id);
    await page.goto(base + '/purchasing');
    assert.equal(await page.evaluate(() => !!document.querySelector('a[href="/purchasing?new=1"]')), false);
    const restricted = await page.fetch('/purchasing?id=' + fixture.restricted_order_id);
    assert.equal(restricted.status, 404);
    await page.goto(base + '/workspaces');
    const readerContext = await page.evaluate(() => document.querySelector('form[data-workspace-context]').dataset.workspaceContext);
    assert.equal((await ajax('purchasing::onApprove', { id: poId, version: '1', operation_key: crypto.randomUUID() }, { context: readerContext, url: '/purchasing' })).status, 403);
    await page.goto(base + '/purchasing?id=' + poId);
    await screenshot('restricted-reader');
    passed('Tenant/branch isolation and read-only direct-action denial');

    await logout(); await login(fixture.a); await workspace(fixture.a.company_id, fixture.a.branch_id);
    await page.cdp('Emulation.setDeviceMetricsOverride', mobile);
    await page.goto(base + '/receiving?new=1&po=' + reopenedId);
    // The reopened PO cannot save receipts; mobile fields and keyboard still remain inspectable without mutation.
    await page.focus('#receipt-ref');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'receipt-date');
    await screenshot('mobile-receiving', mobile);
    await page.goto(base + '/inventory');
    await screenshot('mobile-inventory', mobile);
    passed('Mobile layout, native fields and keyboard order');
    await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
    await page.goto(base + '/purchasing?id=' + poId);
    const events = await page.events();
    const serialized = JSON.stringify(events);
    const runtimeErrors = events.filter(event => event.method === 'Runtime.exceptionThrown' || (event.method === 'Runtime.consoleAPICalled' && event.params.type === 'error'));
    report.runtimeErrors = runtimeErrors;
    assert.equal(runtimeErrors.length, 0, serialized.slice(0, 1000));
    report.passed = true;
    report.records = { poId, firstId, fullId, correctedId, duplicateId, reopenedId };
    console.log({ passed: true, scenarios: report.scenarios.length, screenshots: report.screenshots.length, runtimeErrors: runtimeErrors.length });
} catch (error) {
    report.error = String(error);
    console.log({ passed: false, error: String(error), url: await page.url() });
    console.log(await page.snapshot());
    throw error;
} finally {
    await fs.writeFile(output + '/report.json', JSON.stringify(report, null, 2));
}
// Inspect recorded screenshots before finishing this one TaskSpace.
