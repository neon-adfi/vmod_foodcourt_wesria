// Guarded identity seed first. Resume this same TaskSpace and scenario ledger after a failure.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const fixturePath = '/private/tmp/wesria-circularity-phase2-acceptance.json';
const fixture = JSON.parse(await fs.readFile(fixturePath, 'utf8'));
const output = '/Users/stev/htdoc/vmod_wesria/output/ego-lite/circularity-phase-2';
const base = 'https://wesria.local.test';
const task = await taskSpace(fixture.space_id ?? 'Wesria circularity Phase 2 acceptance');
const page = task.page('p1');
fixture.space_id = task.spaceId;
console.log({ spaceId: task.spaceId });
await fs.mkdir(output, { recursive: true });
let report = { spaceId: task.spaceId, scenarios: [], screenshots: [], passed: false };
try { const previous = JSON.parse(await fs.readFile(output + '/report.json', 'utf8')); if (previous.spaceId === task.spaceId) report = previous; } catch {}
report.passed = false;
const done = name => report.scenarios.includes(name);
async function persist() {
    await fs.writeFile(fixturePath, JSON.stringify(fixture), { mode: 0o600 });
    await fs.writeFile(output + '/report.json', JSON.stringify(report, null, 2));
}
async function passed(name) { report.scenarios.push(name); await persist(); console.log('PASS ' + name); }
const desktop = { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false };
const mobile = { width: 390, height: 844, deviceScaleFactor: 1, mobile: true };
await page.cdp('Runtime.enable');
await page.cdp('Network.enable');
await page.cdp('Network.setCacheDisabled', { cacheDisabled: true });
await page.events();
await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
async function go(path) {
    await page.goto(base + path);
    await page.waitForFunction(() => typeof window.oc?.request === 'function' && typeof window.bootstrap?.Collapse === 'function');
}
async function submit(selector) {
    await page.evaluate(() => { document.documentElement.dataset.acceptanceNavigation = 'pending'; });
    await page.click(selector + ' button[type=submit]');
    await page.waitForFunction(() => !document.documentElement.dataset.acceptanceNavigation, undefined, { timeout: 15000 });
    await page.waitForFunction(() => typeof window.oc?.request === 'function' && typeof window.bootstrap?.Collapse === 'function');
}
async function login(account, company = fixture.a.company_id) {
    await go('/account');
    if ((await page.url()).includes('/account')) await submit('header form[data-request="identity::onLogout"]');
    await page.fill('#email', account.email); await page.fill('#password', fixture.password);
    await submit('form[data-request="identity::onLogin"]');
    const selector = `form:has(input[name=company_id][value="${company}"])`;
    await page.selectOption(selector + ' select', '');
    await submit(selector);
}
async function shot(name) {
    const size = await page.evaluate(async () => { await document.fonts.ready; return [innerWidth, document.documentElement.scrollWidth]; });
    assert.ok(size[1] <= size[0] + 1, 'No horizontal page overflow');
    const file = output + '/' + name + '.png';
    await page.screenshot({ path: file, fullPage: false, raw: true });
    if (!report.screenshots.includes(file)) report.screenshots.push(file);
    await persist();
}
async function values() { return page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('#circularity-entry-form')))); }
async function request(handler, data, context) {
    return page.evaluate(async ({ handler, data, context }) => {
        const response = await fetch(location.href, { method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest',
                'X-AJAX-HANDLER': handler, 'X-Workspace-Context': context ?? document.body.dataset.historyContext },
            body: new URLSearchParams({ ...data, _token: document.querySelector('input[name=_token]').value }) });
        return { status: response.status, data: await response.json() };
    }, { handler, data, context });
}
async function entry({ type = 'mixed_waste', date = '2026-01-01', quantity = '1', destination = 'unknown', individual = false } = {}) {
    await go('/circularity/entries?new=1');
    assert.equal(await page.evaluate(() => document.querySelector('#capture-court').value), '');
    const uuid = new URL(await page.url()).searchParams.get('uuid');
    assert.ok(uuid);
    await page.fill('#capture-quantity', quantity);
    await page.selectOption('#capture-court', String(fixture.a.branch_id));
    await page.selectOption('#capture-type', type);
    await page.fill('#capture-date', date);
    await page.selectOption('#capture-destination', destination);
    await page.selectOption('#capture-area', String(fixture.area_id));
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
    await page.focus(selector);
    await page.press(selector, 'Enter');
    await page.waitForFunction(() => !!document.documentElement.dataset.acceptanceStatus);
    await page.waitForFunction(() => !document.querySelector('#circularity-entry-form')?.hasAttribute('data-ajax-progress'));
    return page.evaluate(() => Number(document.documentElement.dataset.acceptanceStatus));
}
async function remove() {
    await page.click('[data-request="circularityCapture::onRemoveEntry"]');
    await page.acceptDialog();
    await page.waitForURL(base + '/circularity/entries');
}
async function paused() {
    const deadline = Date.now() + 10000;
    while (Date.now() < deadline) {
        const event = (await page.events()).find(event => event.method === 'Fetch.requestPaused');
        if (event) return event.params;
        await new Promise(resolve => setTimeout(resolve, 25));
    }
    throw new Error('Expected intercepted request.');
}
try {
    await go('/auth');
    console.log(await page.snapshot());
    await login(fixture.a);
    if (!done('Area creation, reload and reactivation')) {
        await go('/circularity/areas?new=1');
        await page.selectOption('#area-court', String(fixture.a.branch_id));
        await page.fill('#area-name', 'Shared kitchen ' + fixture.nonce);
        await page.selectOption('#area-kind', 'shared_area');
        await submit('#circularity-area-form');
        fixture.area_id = Number(new URL(await page.url()).searchParams.get('id')); await persist();
        await page.reload();
        assert.equal(await page.evaluate(() => document.querySelector('#area-name').value), 'Shared kitchen ' + fixture.nonce);
        await page.click('input[type=checkbox][name=is_active]'); await submit('#circularity-area-form');
        assert.equal(await page.evaluate(() => document.querySelector('input[type=checkbox][name=is_active]').checked), false);
        await page.click('input[type=checkbox][name=is_active]'); await submit('#circularity-area-form');
        await shot('desktop-area');
        await passed('Area creation, reload and reactivation');
    }
    if (!done('Daily mixed waste save reload edit')) {
        fixture.daily_uuid = await entry({ quantity: '0' }); await persist();
        await submit('#circularity-entry-form'); await page.reload();
        assert.equal((await values()).quantity, '0.000');
        await page.fill('#capture-quantity', '4.125'); await page.fill('#capture-reference', 'Collection A');
        await page.fill('#capture-notes', 'Food and packaging cannot be separated.');
        await submit('#circularity-entry-form'); await page.reload();
        assert.equal((await values()).quantity, '4.125'); assert.equal((await values()).version, '2');
        await shot('desktop-measurement');
        await passed('Daily mixed waste save reload edit');
    }
    if (!done('Clean daily selection opens existing and dirty input stays intact')) {
        await go('/circularity/entries?new=1');
        await page.selectOption('#capture-court', String(fixture.a.branch_id));
        await page.selectOption('#capture-type', 'mixed_waste');
        await page.fill('#capture-date', '2026-01-01');
        await page.selectOption('#capture-area', String(fixture.area_id));
        await page.waitForURL(base + '/circularity/entries?uuid=' + fixture.daily_uuid);
        const draft = await entry({ quantity: '7' });
        await page.waitForSelector('#capture-group a');
        assert.equal(new URL(await page.url()).searchParams.get('uuid'), draft);
        const before = await values();
        assert.equal(await clickResult('[data-circularity-save][type=submit]'), 409);
        assert.deepEqual(await values(), before);
        assert.equal(await page.evaluate(() => document.querySelector('[data-circularity-save]').disabled), true);
        await clickResult('[data-request="circularityCapture::onCheckEntry"]');
        assert.match(await page.evaluate(() => document.querySelector('#capture-recovery').textContent), /may still finish/);
        assert.deepEqual(await values(), before);
        await shot('desktop-conflict-preserved');
        await passed('Clean daily selection opens existing and dirty input stays intact');
    }
    if (!done('Existing edit never switches UUID on daily collision')) {
        if (!fixture.other_uuid) {
            fixture.other_uuid = await entry({ quantity: '8', destination: 'landfill' }); await persist();
            await submit('#circularity-entry-form');
        } else {
            await go('/circularity/entries?uuid=' + fixture.other_uuid);
        }
        await page.selectOption('#capture-destination', 'unknown');
        await page.waitForSelector('#capture-group a');
        assert.equal((await values()).uuid, fixture.other_uuid);
        const before = await values();
        assert.equal(await clickResult('[data-circularity-save][type=submit]'), 409);
        await clickResult('[data-request="circularityCapture::onCheckEntry"]');
        assert.deepEqual(await values(), before);
        await go('/circularity/entries?uuid=' + fixture.other_uuid); await remove();
        await passed('Existing edit never switches UUID on daily collision');
    }
    if (!done('Individual entries and remove-before-switch')) {
        fixture.individual_uuid = await entry({ date: '2026-01-02', quantity: '2', individual: true }); await persist();
        await submit('#circularity-entry-form'); await page.reload();
        assert.equal((await values()).mode, 'individual');
        await page.fill('#capture-quantity', '3'); await submit('#circularity-entry-form');
        await entry({ date: '2026-01-02', quantity: '5' });
        assert.equal((await values()).mode, 'individual');
        assert.equal(await page.evaluate(() => document.querySelector('#capture-mode').disabled), true);
        const forged = await values(); forged.mode = 'daily_total';
        assert.equal((await request('circularityCapture::onSaveEntry', forged)).status, 409);
        await go('/circularity/entries?uuid=' + fixture.individual_uuid); await remove();
        await entry({ date: '2026-01-02', quantity: '5' });
        assert.equal((await values()).mode, 'daily_total');
        assert.equal(await page.evaluate(() => document.querySelector('#capture-mode').disabled), false);
        await submit('#circularity-entry-form'); await remove();
        await passed('Individual entries and remove-before-switch');
    }
    if (!done('Unknown completed save recovery preserves UUID and values')) {
        fixture.uncertain_uuid = await entry({ date: '2026-01-03', quantity: '6.5' }); await persist();
        const before = await values();
        await page.cdp('Fetch.enable', { patterns: [{ urlPattern: base + '/circularity/entries*', requestStage: 'Response' }] });
        await page.click('[data-circularity-save][type=submit]');
        const response = await paused();
        await page.cdp('Fetch.failRequest', { requestId: response.requestId, errorReason: 'ConnectionReset' });
        await page.cdp('Fetch.disable');
        await page.waitForFunction(() => document.querySelector('#circularity-entry-form').dataset.needsCheck === '1');
        assert.deepEqual(await values(), before);
        await clickResult('[data-request="circularityCapture::onCheckEntry"]');
        assert.match(await page.evaluate(() => document.querySelector('#capture-recovery').textContent), /saved entry exists/);
        assert.deepEqual(await values(), before);
        await shot('desktop-uncertain-save');
        await go('/circularity/entries?uuid=' + fixture.uncertain_uuid); await page.reload();
        assert.equal((await values()).quantity, '6.500'); assert.equal((await values()).version, '1');
        await passed('Unknown completed save recovery preserves UUID and values');
    }
    if (!done('Unavailable save and failed lookup remain uncertain')) {
        await go('/circularity/entries?uuid=' + fixture.uncertain_uuid);
        await page.waitForFunction(() => document.querySelector('#capture-group').textContent.includes('This group uses'));
        await page.fill('#capture-quantity', '99');
        const before = await values();
        await page.cdp('Fetch.enable', { patterns: [{ urlPattern: base + '/circularity/entries*', requestStage: 'Request' }] });
        await page.click('[data-circularity-save][type=submit]');
        let intercept = await paused();
        const body = { __ajax: { ok: false, severity: 'error', message: 'Circularity unavailable', invalid: [], ops: [], redirect: null } };
        await page.cdp('Fetch.fulfillRequest', { requestId: intercept.requestId, responseCode: 503, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'X-AJAX-RESPONSE', value: 'true' }], body: Buffer.from(JSON.stringify(body)).toString('base64') });
        await page.waitForFunction(() => document.querySelector('#circularity-entry-form').dataset.needsCheck === '1');
        await page.click('[data-request="circularityCapture::onCheckEntry"]');
        intercept = await paused();
        await page.cdp('Fetch.failRequest', { requestId: intercept.requestId, errorReason: 'ConnectionReset' });
        await page.cdp('Fetch.disable');
        await page.waitForFunction(() => document.querySelector('#request-error').textContent.includes('lookup failed'));
        assert.deepEqual(await values(), before);
        assert.equal(await page.evaluate(() => document.querySelector('[data-circularity-save]').disabled), true);
        await clickResult('[data-request="circularityCapture::onCheckEntry"]');
        assert.deepEqual(await values(), before);
        await passed('Unavailable save and failed lookup remain uncertain');
    }
    if (!done('Stale workspace read-only access and shared recovery')) {
        await go('/circularity/entries?uuid=' + fixture.daily_uuid);
        const before = await values();
        assert.equal((await request('circularityCapture::onSaveEntry', before, 'stale-workspace')).status, 409);
        assert.equal((await request('circularityCapture::onCheckEntry', { uuid: before.uuid }, 'stale-workspace')).status, 409);
        assert.deepEqual(await values(), before);
        await go('/invoice-references?new=1');
        await page.evaluate(() => document.querySelector('#invoice-form').dispatchEvent(new CustomEvent('ajax:request-error', { bubbles: true, cancelable: true, detail: { responseCode: 0, context: { handler: 'invoiceReferences::onChoices' } } })));
        assert.match(await page.evaluate(() => document.querySelector('#request-error').textContent), /Documents could not be loaded/);
        assert.doesNotMatch(await page.evaluate(() => document.querySelector('#request-error').textContent), /Save outcome unknown/);
        await login(fixture.b);
        await go('/circularity/entries');
        assert.equal(await page.evaluate(() => document.querySelectorAll('a[href*="new=1"], [data-circularity-save]').length), 0);
        await go('/circularity/entries?uuid=' + fixture.daily_uuid);
        assert.equal(await page.evaluate(() => !!document.querySelector('#circularity-detail')), true);
        assert.equal((await request('circularityCapture::onSaveEntry', before)).status, 403);
        await shot('desktop-read-only');
        await login(fixture.a);
        await passed('Stale workspace read-only access and shared recovery');
    }
    if (!done('Desktop and mobile lists and capture')) {
        await go('/circularity/entries?measurement_type=mixed_waste');
        assert.match(await page.evaluate(() => document.querySelector('tbody').textContent), /4\.125 kg/);
        await shot('desktop-list');
        await page.cdp('Emulation.setDeviceMetricsOverride', mobile); await page.reload();
        await shot('mobile-list');
        await go('/circularity/entries?uuid=' + fixture.daily_uuid);
        await shot('mobile-measurement');
        await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
        await passed('Desktop and mobile lists and capture');
    }
    if (!done('Live native validation and invalid filter handling')) {
        assert.equal((await page.fetch('/circularity/entries?measurement_type=unexpected')).status, 400);
        await go('/circularity/entries?uuid=' + fixture.daily_uuid);
        await page.waitForFunction(() => document.querySelector('#capture-group').textContent.includes('This group uses'));
        assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector('#circularity-entry-form [data-validate-error]')).display), 'none');
        await page.fill('#capture-quantity', '1e3');
        assert.equal(await clickResult('[data-circularity-save][type=submit]'), 422);
        assert.equal((await values()).quantity, '1e3');
        assert.equal((await values()).version, '2');
        assert.match(await page.evaluate(() => document.querySelector('[data-validate-for=quantity]').textContent), /nonnegative kilograms/);
        await shot('desktop-field-validation');
        await page.reload();
        assert.equal((await values()).quantity, '4.125');
        await shot('desktop-measurement');
        await page.cdp('Emulation.setDeviceMetricsOverride', mobile);
        await page.reload(); await shot('mobile-measurement');
        await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
        await passed('Live native validation and invalid filter handling');
    }
    if (!done('Reusable counts and packaging remain separate')) {
        await entry({ type: 'reusable_issued', date: '2026-01-04', quantity: '0', destination: 'not_applicable' });
        await submit('#circularity-entry-form'); await page.reload();
        assert.equal((await values()).quantity, '0');
        assert.equal((await values()).destination, 'not_applicable');
        await entry({ type: 'reusable_returned', date: '2026-01-04', quantity: '8', destination: 'not_applicable' });
        await submit('#circularity-entry-form'); await page.reload();
        assert.equal((await values()).quantity, '8');
        await entry({ type: 'packaging_waste', date: '2026-01-04', quantity: '0.125', destination: 'recycling' });
        await submit('#circularity-entry-form'); await page.reload();
        assert.equal((await values()).quantity, '0.125');
        await passed('Reusable counts and packaging remain separate');
    }
    if (!done('A different empty group defaults to daily total')) {
        if (!fixture.food_uuid) {
            fixture.food_uuid = await entry({ type: 'food_waste', date: '2026-01-05', quantity: '1', individual: true });
            await persist(); await submit('#circularity-entry-form');
        }
        await entry({ type: 'food_waste', date: '2026-01-05', quantity: '2' });
        assert.equal((await values()).mode, 'individual');
        await page.fill('#capture-date', '2026-01-06');
        await page.focus('#capture-quantity');
        await page.waitForFunction(() => document.querySelector('#capture-group').textContent.includes('No measurements'));
        assert.equal((await values()).mode, 'daily_total');
        await passed('A different empty group defaults to daily total');
    }
    if (!done('Gateway errors require a saved-entry check before another mutation')) {
        await go('/circularity/entries?uuid=' + fixture.uncertain_uuid);
        await page.waitForFunction(() => document.querySelector('#capture-group').textContent.includes('This group uses'));
        await page.fill('#capture-quantity', '98');
        const before = await values();
        for (const responseCode of [504, 502, 500]) {
            await page.evaluate(responseCode => document.querySelector('#circularity-entry-form').dispatchEvent(
                new CustomEvent('ajax:request-error', { bubbles: true, cancelable: true, detail: {
                    responseCode, context: { handler: 'circularityCapture::onSaveEntry', requestSent: true }
                } })
            ), responseCode);
            assert.equal(await page.evaluate(() => document.querySelector('#circularity-entry-form').dataset.needsCheck), '1');
            assert.equal(await page.evaluate(() => [...document.querySelectorAll('[data-circularity-save]')].every(button => button.disabled)), true);
            assert.match(await page.evaluate(() => document.querySelector('#request-error').textContent), /Save outcome unknown/);
            await page.evaluate(() => document.querySelector('#circularity-entry-form').dispatchEvent(
                new CustomEvent('ajax:request-error', { bubbles: true, cancelable: true, detail: {
                    responseCode: 504, context: { handler: 'circularityCapture::onCheckEntry', requestSent: true }
                } })
            ));
            assert.equal(await page.evaluate(() => document.querySelector('#circularity-entry-form').dataset.needsCheck), '1');
            assert.deepEqual(await values(), before);
            if (responseCode === 504) await shot('desktop-gateway-recovery');
            await clickResult('[data-request="circularityCapture::onCheckEntry"]');
            assert.deepEqual(await values(), before);
        }
        await passed('Gateway errors require a saved-entry check before another mutation');
    }
    const errors = (await page.events()).filter(event => event.method === 'Runtime.exceptionThrown');
    assert.equal(errors.length, 0, JSON.stringify(errors));
    report.runtimeErrors = 0; report.passed = true;
} catch (error) {
    console.error(error.stack ?? String(error));
    throw error;
} finally {
    await page.cdp('Fetch.disable');
    await persist();
}
// Inspect screenshots, then finish this same TaskSpace exactly once.
