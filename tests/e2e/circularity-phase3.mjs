// Run seed-circularity-phase3.php once; resume this fixture's TaskSpace after an interrupted check.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const fixturePath = '/private/tmp/wesria-circularity-phase3-acceptance.json';
const fixture = JSON.parse(await fs.readFile(fixturePath, 'utf8'));
const output = '/Users/stev/htdoc/vmod_wesria/output/ego-lite/circularity-phase-3';
const base = 'https://wesria.local.test';
const task = await taskSpace(fixture.space_id ?? 'Wesria circularity Phase 3 acceptance');
const page = task.page('p1');
fixture.space_id = task.spaceId;
console.log({ spaceId: task.spaceId });
await fs.mkdir(output, { recursive: true });
let report = { spaceId: task.spaceId, scenarios: [], screenshots: [], passed: false };
try { const old = JSON.parse(await fs.readFile(output + '/report.json', 'utf8')); if (old.spaceId === task.spaceId) report = old; } catch {}
report.passed = false;
const done = name => report.scenarios.includes(name);
async function persist() {
    await fs.writeFile(fixturePath, JSON.stringify(fixture), { mode: 0o600 });
    await fs.writeFile(output + '/report.json', JSON.stringify(report, null, 2));
}
async function passed(name) { report.scenarios.push(name); await persist(); console.log('PASS ' + name); }
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
async function login(account, company = fixture.a.company_id) {
    await go('/account');
    if ((await page.url()).includes('/account')) await submit('header form[data-request="identity::onLogout"]');
    await page.fill('#email', account.email); await page.fill('#password', fixture.password);
    await submit('form[data-request="identity::onLogin"]');
    const selector = `form:has(input[name=company_id][value="${company}"])`;
    await page.selectOption(selector + ' select', '');
    await submit(selector);
}
async function quantity(type) {
    return page.evaluate(type => document.querySelector(`[data-summary-type="${type}"] [data-summary-quantity]`)?.textContent.trim() ?? null, type);
}
async function shot(name) {
    const size = await page.evaluate(async () => { await document.fonts.ready; return [innerWidth, document.documentElement.scrollWidth]; });
    assert.ok(size[1] <= size[0] + 1, 'No horizontal page overflow');
    const path = output + '/' + name + '.png';
    await page.screenshot({ path, fullPage: false, raw: true });
    if (!report.screenshots.includes(path)) report.screenshots.push(path);
    await persist();
}
const scope = new URLSearchParams({ branch_ref: String(fixture.a.branch_id), area_id: String(fixture.areas.main) });
const desktop = { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false };
const mobile = { width: 390, height: 844, deviceScaleFactor: 1, mobile: true };
await page.cdp('Runtime.enable');
await page.events();
await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
await persist();
try {
    await go('/auth');
    console.log(await page.snapshot());
    await login(fixture.a);
    if (!done('Month-to-date defaults and permitted court filters')) {
        await go('/circularity');
        assert.equal(await quantity('food_waste'), '112.125 kg');
        assert.equal(await page.evaluate(() => document.querySelector('#filter-from').value), fixture.month_from);
        assert.equal(await page.evaluate(() => document.querySelector('#filter-to').value), fixture.today);
        await page.selectOption('#filter-court', String(fixture.a.branch_id));
        assert.equal(await page.evaluate(id => document.querySelector(`#filter-area option[value="${id}"]`).disabled, fixture.areas.other), true);
        await page.selectOption('#filter-area', String(fixture.areas.main));
        await submit('#circularity-filters');
        assert.equal(await quantity('food_waste'), '12.125 kg');
        assert.equal(await quantity('mixed_waste'), '3.250 kg');
        assert.equal(await quantity('packaging_waste'), '0.000 kg');
        assert.equal(await quantity('reusable_issued'), '8 items');
        assert.equal(await quantity('reusable_returned'), '12 items');
        assert.match(await page.evaluate(() => document.querySelector('[data-destination-type=food_waste]').textContent), /Unknown.*2\.000/s);
        await shot('desktop-overview');
        await page.hover('[data-destination-type=food_waste]');
        await shot('desktop-destinations');
        await passed('Month-to-date defaults and permitted court filters');
    }
    if (!done('Uniform destination filters and exact drilldowns')) {
        await go('/circularity?' + scope);
        await page.selectOption('#filter-destination', 'composting');
        await submit('#circularity-filters');
        assert.equal(await quantity('food_waste'), '10.125 kg');
        assert.equal(await quantity('reusable_returned'), null);
        assert.match(await page.evaluate(() => document.querySelector('[data-summary-type=reusable_returned]').textContent), /No data for selected filters/);
        await shot('desktop-destination-filter');
        await page.click('[data-summary-type=food_waste] a');
        await page.waitForSelector('#circularity-filters');
        assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 1);
        assert.match(await page.evaluate(() => document.querySelector('tbody').textContent), /10\.125 kg/);
        const query = new URL(await page.url()).searchParams;
        assert.equal(query.get('date_from'), fixture.month_from);
        assert.equal(query.get('date_to'), fixture.today);
        assert.equal(query.get('destination'), 'composting');
        await go('/circularity?' + scope);
        await page.click('#view-measurements');
        await page.waitForSelector('#circularity-filters');
        assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 6);
        await passed('Uniform destination filters and exact drilldowns');
    }
    if (!done('All-history list legacy dates and range pagination')) {
        await go('/circularity/entries');
        assert.equal(await page.evaluate(() => document.querySelector('#filter-from').value), '');
        assert.equal(await page.evaluate(() => document.querySelector('#filter-to').value), '');
        await go('/circularity/entries?measurement_date=' + fixture.prior_date);
        assert.equal(await page.evaluate(() => document.querySelector('#filter-from').value), fixture.prior_date);
        assert.equal(await page.evaluate(() => document.querySelector('#filter-to').value), fixture.prior_date);
        assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 25);
        const next = await page.evaluate(() => [...document.querySelectorAll('nav[aria-label="Measurement pages"] a')].find(a => a.textContent === 'Next').getAttribute('href'));
        assert.equal(new URL(next, base).searchParams.get('date_from'), fixture.prior_date);
        await page.click('nav[aria-label="Measurement pages"] a');
        await page.waitForFunction(() => document.querySelector('nav[aria-label="Measurement pages"]').textContent.includes('Page 2'));
        assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 1);
        await shot('desktop-filtered-list');
        await passed('All-history list legacy dates and range pagination');
    }
    if (!done('Invalid ranges are correctable and no data stays distinct')) {
        await go('/circularity?date_from=' + fixture.month_from);
        assert.equal(await page.evaluate(() => !!document.querySelector('#query-date_to')), true);
        await page.fill('#query-date_to', fixture.today);
        await submit('#query-error form');
        assert.equal(await quantity('food_waste'), '112.125 kg');
        for (const path of [
            '/circularity?date_from[]=bad&date_to=' + fixture.today,
            '/circularity?date_from=2026-02-01&date_to=2026-01-01',
            '/circularity/entries?measurement_date=2026-01-01&date_from=2026-01-01&date_to=2026-01-01',
        ]) assert.equal((await page.fetch(path)).status, 400);
        await go('/circularity?date_from=1900-01-01&date_to=1900-01-01');
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-summary-empty]').length), 5);
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-summary-quantity]').length), 0);
        await shot('desktop-no-data');
        await passed('Invalid ranges are correctable and no data stays distinct');
    }
    if (!done('Edits removals and inactive areas affect the next read')) {
        await go('/circularity/entries?uuid=' + fixture.entries.food);
        if (await page.evaluate(() => document.querySelector('#capture-quantity').value) !== '11.125') {
            await page.fill('#capture-quantity', '11.125');
            await submit('#circularity-entry-form');
        }
        await go('/circularity?' + scope);
        assert.equal(await quantity('food_waste'), '13.125 kg');
        await go('/circularity/entries?uuid=' + fixture.entries.unknown);
        if (await page.evaluate(() => !!document.querySelector('[data-request="circularityCapture::onRemoveEntry"]'))) {
            await page.click('[data-request="circularityCapture::onRemoveEntry"]');
            await page.acceptDialog();
            await page.waitForURL(base + '/circularity/entries');
        }
        await go('/circularity/areas?id=' + fixture.areas.main);
        if (await page.evaluate(() => document.querySelector('input[type=checkbox][name=is_active]').checked)) {
            await page.click('input[type=checkbox][name=is_active]');
            await submit('#circularity-area-form');
        }
        await go('/circularity?' + scope);
        assert.equal(await quantity('food_waste'), '11.125 kg');
        assert.match(await page.evaluate(() => document.querySelector('#filter-area').selectedOptions[0].textContent), /inactive/);
        await page.reload();
        assert.equal(await quantity('food_waste'), '11.125 kg');
        await passed('Edits removals and inactive areas affect the next read');
    }
    if (!done('Read-only scope and protected workspace recovery')) {
        await login(fixture.b);
        await go('/circularity');
        assert.equal(await quantity('food_waste'), '11.125 kg');
        assert.equal(await page.evaluate(() => document.querySelectorAll('a[href*="new=1"]').length), 0);
        assert.equal((await page.fetch('/circularity?branch_ref=' + fixture.restricted_branch_id)).status, 404);
        assert.equal((await page.fetch('/circularity?area_id=' + fixture.areas.other)).status, 404);
        await shot('desktop-read-only');
        const other = await task.newPage();
        await other.goto(base + '/workspaces');
        const form = `form:has(input[name=company_id][value="${fixture.b.company_id}"])`;
        await other.selectOption(form + ' select', '');
        await other.click(form + ' button[type=submit]');
        await other.waitForURL(base + '/');
        // Exercise the shared persisted-history handlers with a real changed server workspace.
        await page.evaluate(() => {
            window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
            window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
        });
        await page.waitForFunction(() => document.querySelectorAll('[data-summary-empty]').length === 5);
        assert.equal(await quantity('food_waste'), null);
        await other.close();
        await passed('Read-only scope and protected workspace recovery');
    }
    if (!done('Keyboard and mobile overview and measurement table')) {
        await login(fixture.a);
        await go('/circularity?' + scope);
        await page.focus('#filter-from');
        // Native date fields expose internal segments before the next control.
        for (let step = 0; step < 4 && await page.evaluate(() => document.activeElement.id) === 'filter-from'; step++) {
            await page.keyboard.press('Tab');
        }
        assert.equal(await page.evaluate(() => document.activeElement.id), 'filter-to');
        await page.cdp('Emulation.setDeviceMetricsOverride', mobile);
        await page.reload();
        await shot('mobile-overview');
        await page.hover('[data-summary-type=food_waste]');
        await shot('mobile-totals');
        await page.hover('[data-destination-type=food_waste]');
        await shot('mobile-destinations');
        await page.click('#view-measurements');
        await page.waitForSelector('tbody');
        await shot('mobile-measurements');
        await page.hover('tbody tr:first-child td:first-child');
        await shot('mobile-table');
        await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
        await passed('Keyboard and mobile overview and measurement table');
    }
    if (!done('Native unavailable rendering has no misleading totals')) {
        const html = await fs.readFile('/private/tmp/wesria-circularity-phase3/unavailable.html', 'utf8');
        await page.cdp('Fetch.enable', { patterns: [{ urlPattern: base + '/circularity?phase3_error_preview=1', requestStage: 'Request' }] });
        try {
            // Schedule navigation so the browser command returns before its intercepted request pauses.
            await page.evaluate(url => { setTimeout(() => window.location.assign(url), 0); }, base + '/circularity?phase3_error_preview=1');
            const deadline = Date.now() + 10000;
            let intercept;
            while (Date.now() < deadline && !intercept) {
                intercept = (await page.events()).find(event => event.method === 'Fetch.requestPaused')?.params;
                if (!intercept) await new Promise(resolve => setTimeout(resolve, 25));
            }
            assert.ok(intercept, 'Expected the error-preview navigation');
            await page.cdp('Fetch.fulfillRequest', { requestId: intercept.requestId, responseCode: 503,
                responseHeaders: [{ name: 'Content-Type', value: 'text/html' }], body: Buffer.from(html).toString('base64') });
            await page.waitForSelector('.alert-warning');
            assert.match(await page.evaluate(() => document.querySelector('.alert-warning').textContent), /temporarily unavailable/);
            assert.equal(await page.evaluate(() => document.querySelectorAll('[data-summary-quantity]').length), 0);
            await shot('desktop-unavailable');
            report.unavailableMode = 'Native HTML rendered in the connector test with simulated upstream 503, replayed as a browser HTTP 503.';
        } finally {
            await page.cdp('Fetch.disable');
        }
        await passed('Native unavailable rendering has no misleading totals');
    }
    report.runtimeErrors = (await page.events()).filter(event => event.method === 'Runtime.exceptionThrown').map(event => event.params.exceptionDetails.text);
    assert.deepEqual(report.runtimeErrors, []);
    report.passed = true;
    delete report.error;
    await persist();
    console.log({ passed: true, scenarios: report.scenarios.length, screenshots: report.screenshots.length });
    // Finish the TaskSpace once after visual inspection, outside this resumable runner.
} catch (error) {
    report.error = error.message;
    await persist();
    console.log(await page.snapshot());
    throw error;
}
