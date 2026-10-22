// Run check-circularity-http.sh --extracted first. Resume this one TaskSpace after a failed check.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const { proof_dir: proof } = JSON.parse(await fs.readFile('/private/tmp/wesria-circularity-phase5-runtime.json', 'utf8'));
const fixturePath = proof + '/fixture.json';
const fixture = JSON.parse(await fs.readFile(fixturePath, 'utf8'));
const base = fixture.base_url;
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
assert.equal(fixture.runtime.host, 'mysql-test');
const output = '/Users/stev/htdoc/vmod_wesria/output/ego-lite/circularity-phase-5';
const task = await taskSpace(fixture.space_id ?? 'Wesria circularity Phase 5 extraction');
const page = task.page('p1');
fixture.space_id = task.spaceId;
console.log({ spaceId: task.spaceId, origin: base });
await fs.mkdir(output, { recursive: true });
let report = { spaceId: task.spaceId, origin: base, runtime: fixture.runtime, scenarios: [], screenshots: [], history: [], runtimeErrors: [], passed: false };
try { const old = JSON.parse(await fs.readFile(output + '/report.json', 'utf8')); if (old.spaceId === task.spaceId) report = old; } catch {}
report.passed = false;
const done = name => report.scenarios.includes(name);
const desktop = { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false };
const mobile = { width: 390, height: 844, deviceScaleFactor: 1, mobile: true };
await page.cdp('Runtime.enable');
await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
async function persist() {
    await fs.writeFile(fixturePath, JSON.stringify(fixture), { mode: 0o600 });
    await fs.writeFile(output + '/report.json', JSON.stringify(report, null, 2));
}
async function passed(name) {
    report.runtimeErrors.push(...(await page.events()).filter(e => e.method === 'Runtime.exceptionThrown').map(e => e.params.exceptionDetails.text));
    report.scenarios.push(name); await persist(); console.log('PASS ' + name);
}
async function poll(read, label, timeout = 15000) {
    const until = Date.now() + timeout;
    while (Date.now() < until) { const value = await read(); if (value) return value; await new Promise(resolve => setTimeout(resolve, 40)); }
    throw new Error('Timed out: ' + label);
}
async function control(action, value) {
    const id = crypto.randomUUID();
    const path = proof + '/controls/' + id;
    await fs.writeFile(path + '.tmp', JSON.stringify({ action, value }));
    await fs.rename(path + '.tmp', path + '.request.json');
    return poll(async () => { try { return JSON.parse(await fs.readFile(path + '.response.json', 'utf8')); } catch { return null; } }, action);
}
async function targetControl(action) {
    const path = proof + '/target-controls/' + crypto.randomUUID() + '.request';
    await fs.writeFile(path + '.tmp', action);
    await fs.rename(path + '.tmp', path);
    return poll(async () => { try { return JSON.parse(await fs.readFile(path + '.response', 'utf8')); } catch { return null; } }, 'target ' + action, 30000);
}
async function go(path) {
    await page.goto(base + path);
    await page.waitForFunction(() => typeof window.oc?.request === 'function' && typeof window.bootstrap?.Collapse === 'function');
}
async function submit(selector) {
    await page.evaluate(() => { document.documentElement.dataset.acceptanceNavigation = 'pending'; });
    await page.press(selector + ' button[type=submit]', 'Enter');
    await page.waitForFunction(() => !document.documentElement.dataset.acceptanceNavigation, undefined, { timeout: 15000 });
    await page.waitForFunction(() => typeof window.oc?.request === 'function');
}
async function login(account = fixture.b, company = fixture.a.company_id) {
    await go('/account');
    if ((await page.url()).includes('/account')) await submit('header form[data-request="identity::onLogout"]');
    await page.fill('#email', account.email); await page.fill('#password', fixture.password);
    await submit('form[data-request="identity::onLogin"]');
    const form = `form:has(input[name=company_id][value="${company}"])`;
    await page.selectOption(form + ' select', ''); await submit(form);
}
async function shot(name) {
    const size = await page.evaluate(async () => { await document.fonts.ready; return [innerWidth, document.documentElement.scrollWidth]; });
    assert.ok(size[1] <= size[0] + 1, 'No horizontal page overflow');
    const path = output + '/' + name + '.png';
    await page.screenshot({ path, fullPage: false, raw: true });
    if (!report.screenshots.includes(path)) report.screenshots.push(path);
    await persist();
}
async function values() { return page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('#circularity-entry-form')))); }
async function quantity(type) { return page.evaluate(type => document.querySelector(`[data-summary-type="${type}"] [data-summary-quantity]`)?.textContent.trim() ?? null, type); }
async function entry({ date, quantity = '1', individual = false } = {}) {
    await go('/circularity/entries?new=1');
    const uuid = new URL(await page.url()).searchParams.get('uuid');
    assert.ok(uuid);
    await page.fill('#capture-quantity', quantity);
    await page.selectOption('#capture-court', String(fixture.a.branch_id));
    await page.selectOption('#capture-type', 'mixed_waste');
    await page.fill('#capture-date', date);
    await page.selectOption('#capture-destination', 'unknown');
    await page.selectOption('#capture-area', String(fixture.capture_area));
    await page.waitForFunction(() => /No measurements|This group uses/.test(document.querySelector('#capture-group').textContent));
    if (individual) await page.selectOption('#capture-mode', 'individual');
    return uuid;
}
async function clickResult(selector) {
    await page.evaluate(() => {
        document.documentElement.dataset.acceptanceStatus = '';
        const listener = event => {
            if (event.detail.context.handler === 'circularityCapture::onGroup') return;
            document.documentElement.dataset.acceptanceStatus = String(event.detail.responseCode);
            document.removeEventListener('ajax:request-complete', listener);
        };
        document.addEventListener('ajax:request-complete', listener);
    });
    await page.press(selector, 'Enter');
    await page.waitForFunction(() => !!document.documentElement.dataset.acceptanceStatus, undefined, { timeout: 15000 });
    return page.evaluate(() => Number(document.documentElement.dataset.acceptanceStatus));
}
async function remove() {
    await page.click('[data-request="circularityCapture::onRemoveEntry"]');
    await page.acceptDialog(); await page.waitForURL(base + '/circularity/entries');
}
async function historyEntry() { const h = await page.cdp('Page.getNavigationHistory'); return h.entries[h.currentIndex].id; }
async function backTo(id) { await page.cdp('Page.navigateToHistoryEntry', { entryId: id }); }
async function markHistory(key) {
    await page.evaluate(key => {
        window.__phase5Document = key;
        addEventListener('pagehide', e => sessionStorage.setItem(key + '-hide', JSON.stringify({ persisted: e.persisted,
            hidden: document.querySelector('.page').style.display === 'none', inert: document.querySelector('.page').inert })));
        addEventListener('pageshow', e => { if (e.persisted) sessionStorage.setItem(key + '-show', 'true'); });
    }, key);
}
async function historyReturn(name, path, change, verify, forward = false) {
    await go('/account'); const anchor = await historyEntry();
    await go(path); const target = await historyEntry(); const key = 'phase5-' + crypto.randomUUID();
    if (await page.evaluate(() => !!document.querySelector('#capture-quantity'))) await page.fill('#capture-quantity', '999.999');
    await markHistory(key);
    if (forward) { await backTo(anchor); await page.waitForSelector('#first_name'); }
    else await go('/account');
    const hidden = await page.evaluate(key => JSON.parse(sessionStorage.getItem(key + '-hide')), key);
    assert.deepEqual(hidden, { persisted: true, hidden: true, inert: true }, 'Actual cached departure was concealed');
    await change();
    await backTo(target);
    await page.waitForFunction(key => sessionStorage.getItem(key + '-show') === 'true'
        && !!document.body && !window.__phase5Document, key, { timeout: 15000 });
    assert.equal(await page.evaluate(key => sessionStorage.getItem(key + '-show'), key), 'true', 'Actual BFCache restoration observed');
    await verify();
    report.history.push({ name, direction: forward ? 'Forward' : 'Back', concealed: true, persisted: true, replaced: true });
    await shot('history-' + name);
    await page.evaluate(key => { sessionStorage.removeItem(key + '-hide'); sessionStorage.removeItem(key + '-show'); }, key);
}
await persist();
try {
    await control('permissions', 'full'); await control('branches', 'all');
    await login();
    console.log(await page.snapshot());
    if (!done('Actual history replaces circularity documents and discards drafts')) {
        const path = '/circularity/entries?uuid=' + fixture.entries.food;
        await historyReturn('unchanged-access', path, async () => {}, async () => {
            await page.waitForSelector('#capture-quantity'); assert.equal((await values()).quantity, '10.125');
        });
        await historyReturn('write-revoked', path, () => control('permissions', 'read'), async () => {
            await page.waitForSelector('#circularity-detail');
            assert.equal(await page.evaluate(() => !!document.querySelector('#circularity-entry-form')), false);
            assert.match(await page.evaluate(() => document.querySelector('#circularity-detail').textContent), /10\.125/);
        }, true);
        await control('permissions', 'full');
        await historyReturn('read-revoked', path, () => control('permissions', 'none'), async () => {
            assert.equal((await page.fetch(path)).status, 403);
            assert.equal(await page.evaluate(() => !!document.querySelector('#circularity-entry-form, #circularity-detail')), false);
        });
        await control('permissions', 'full');
        const otherPath = '/circularity/entries?uuid=' + fixture.entries.other;
        await historyReturn('court-reduced', otherPath, () => control('branches', 'first'), async () => {
            assert.equal((await page.fetch(otherPath)).status, 404);
            assert.equal(await page.evaluate(() => !!document.querySelector('#circularity-entry-form, #circularity-detail')), false);
        }, true);
        await control('branches', 'all');
        await historyReturn('workspace-changed', '/circularity', async () => {
            const other = await task.newPage();
            await other.goto(base + '/workspaces');
            const form = `form:has(input[name=company_id][value="${fixture.b.company_id}"])`;
            await other.selectOption(form + ' select', ''); await other.click(form + ' button[type=submit]');
            await other.waitForURL(base + '/'); await other.close();
        }, async () => {
            await page.waitForFunction(() => document.querySelectorAll('[data-summary-empty]').length === 5);
            assert.equal(await quantity('food_waste'), null);
        });
        await login();
        await passed('Actual history replaces circularity documents and discards drafts');
    }
    if (!done('Capture reload edit remove and both measurement modes')) {
        if (!fixture.capture_area) {
            await go('/circularity/areas?new=1');
            await page.selectOption('#area-court', String(fixture.a.branch_id));
            await page.fill('#area-name', 'Capture qualification ' + fixture.nonce);
            await page.selectOption('#area-kind', 'shared_area'); await submit('#circularity-area-form');
            fixture.capture_area = Number(new URL(await page.url()).searchParams.get('id')); await persist();
        }
        fixture.daily = await entry({ date: '2026-01-01', quantity: '0' }); await persist();
        await submit('#circularity-entry-form'); await page.reload();
        assert.equal((await values()).quantity, '0.000'); assert.equal((await values()).version, '1');
        await page.fill('#capture-quantity', '4.125'); await submit('#circularity-entry-form'); await page.reload();
        assert.equal((await values()).quantity, '4.125'); assert.equal((await values()).version, '2');
        const query = new URLSearchParams({ area_id: String(fixture.capture_area), date_from: '2026-01-01', date_to: '2026-01-01' });
        await go('/circularity?' + query); assert.equal(await quantity('mixed_waste'), '4.125 kg');
        await entry({ date: '2026-01-01', quantity: '7' }); const before = await values();
        assert.equal(await clickResult('[data-circularity-save][type=submit]'), 409);
        assert.deepEqual(await values(), before); await shot('daily-conflict');
        await go('/circularity/entries?uuid=' + fixture.daily); await remove();
        await go('/circularity?' + query); assert.equal(await quantity('mixed_waste'), null);
        fixture.individual = await entry({ date: '2026-01-01', quantity: '1.125', individual: true }); await persist();
        await submit('#circularity-entry-form'); await page.reload(); assert.equal((await values()).mode, 'individual');
        await entry({ date: '2026-01-01', quantity: '2.000' });
        assert.equal((await values()).mode, 'individual');
        assert.equal(await page.evaluate(() => document.querySelector('#capture-mode').disabled), true);
        await submit('#circularity-entry-form');
        await go('/circularity?' + query); assert.equal(await quantity('mixed_waste'), '3.125 kg');
        const state = await targetControl('snapshot');
        assert.equal(state.entries.find(e => e.uuid === fixture.daily).version, 3);
        assert.ok(state.entries.find(e => e.uuid === fixture.daily).deleted_at);
        await passed('Capture reload edit remove and both measurement modes');
    }
    if (!done('Exact overview list reconciliation destination filters and scope')) {
        const scope = new URLSearchParams({ branch_ref: String(fixture.a.branch_id), area_id: String(fixture.areas.main) });
        await go('/circularity?' + scope);
        for (const [type, expected] of Object.entries({ food_waste: '12.125 kg', mixed_waste: '3.250 kg', packaging_waste: '0.000 kg', reusable_issued: '8 items', reusable_returned: '12 items' })) assert.equal(await quantity(type), expected);
        assert.match(await page.evaluate(() => document.querySelector('[data-destination-type=food_waste]').textContent), /Unknown.*2\.000/s);
        await shot('desktop-overview'); await page.hover('[data-destination-type=food_waste]'); await shot('desktop-destinations');
        await page.click('#view-measurements'); await page.waitForSelector('tbody');
        assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 6);
        const rows = await page.evaluate(() => [...document.querySelectorAll('tbody tr')].map(row => row.textContent));
        for (const value of ['10.125 kg', '2.000 kg', '3.250 kg', '0.000 kg', '8 count', '12 count']) assert.ok(rows.some(row => row.includes(value)), value);
        await shot('desktop-list');
        await go('/circularity?' + scope); await page.selectOption('#filter-destination', 'composting'); await submit('#circularity-filters');
        assert.equal(await quantity('food_waste'), '10.125 kg'); assert.equal(await quantity('reusable_issued'), null);
        assert.equal(await quantity('reusable_returned'), null);
        await page.click('[data-summary-type=food_waste] a'); await page.waitForSelector('tbody');
        assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 1);
        assert.match(await page.evaluate(() => document.querySelector('tbody').textContent), /10\.125 kg/);
        await go('/circularity?date_from=1900-01-01&date_to=1900-01-01');
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-summary-empty]').length), 5);
        await control('branches', 'first');
        assert.equal((await page.fetch('/circularity?branch_ref=' + fixture.other_branch_id)).status, 404);
        assert.equal((await page.fetch('/circularity?area_id=' + fixture.areas.other)).status, 404);
        await control('branches', 'all');
        await passed('Exact overview list reconciliation destination filters and scope');
    }
    if (!done('Real service unavailable keyboard and mobile scrolling')) {
        await targetControl('stop');
        await go('/circularity');
        assert.match(await page.evaluate(() => document.body.textContent), /temporarily unavailable/);
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-summary-quantity]').length), 0);
        await shot('desktop-unavailable');
        await targetControl('start');
        await go('/circularity'); await page.focus('#filter-from');
        for (let step = 0; step < 4 && await page.evaluate(() => document.activeElement.id) === 'filter-from'; step++) await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'filter-to');
        await page.cdp('Emulation.setDeviceMetricsOverride', mobile); await page.reload(); await shot('mobile-overview');
        await page.hover('[data-summary-type=food_waste]'); await shot('mobile-totals');
        await page.hover('[data-destination-type=food_waste]'); await shot('mobile-destinations');
        await page.click('#view-measurements'); await page.waitForSelector('tbody'); await shot('mobile-list');
        // Bring the table body into the mobile viewport before testing horizontal wheel input.
        await page.mouse.move(190, 400, { label: 'focus measurement list' });
        await page.mouse.wheel(0, 650, { label: 'show measurement table' });
        await page.hover('tbody tr:first-child td:first-child');
        const scrolling = await page.evaluate(() => { const table = document.querySelector('.table-responsive'); return { width: table.clientWidth, full: table.scrollWidth }; });
        assert.ok(scrolling.full > scrolling.width, 'Table has its own horizontal scroll');
        await page.mouse.wheel(600, 0, { label: 'scroll measurement table' });
        assert.ok(await page.evaluate(() => document.querySelector('.table-responsive').scrollLeft > 0));
        await shot('mobile-table-scrolled');
        await go('/circularity/entries?uuid=' + fixture.individual); await shot('mobile-capture');
        await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
        await passed('Real service unavailable keyboard and mobile scrolling');
    }
    assert.deepEqual(report.runtimeErrors, []);
    for (const file of ['transfer-evidence.json', 'target-runtime.json']) await fs.copyFile(proof + '/' + file, output + '/' + file);
    const transfer = JSON.parse(await fs.readFile(proof + '/transfer-evidence.json', 'utf8'));
    assert.notEqual(transfer.source.server_uuid, transfer.target.server_uuid);
    assert.equal(transfer.raw_rows_equal, true); assert.equal(transfer.migration_noop, true);
    report.transfer = transfer;
    report.passed = true; delete report.error; await persist();
    console.log({ passed: true, scenarios: report.scenarios.length, screenshots: report.screenshots.length });
    // Keep runtime and this TaskSpace until visual inspection. Then write /proof/complete and finish once.
} catch (error) {
    report.error = error.message; await persist(); console.log(await page.snapshot()); throw error;
}
