// Run check-circularity-http.sh --browser first; resume this one TaskSpace after interruption.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const { proof_dir: proof } = JSON.parse(await fs.readFile('/private/tmp/wesria-circularity-phase4-runtime.json', 'utf8'));
const fixture = JSON.parse(await fs.readFile(proof + '/fixture.json', 'utf8'));
const output = '/Users/stev/htdoc/vmod_wesria/output/ego-lite/circularity-phase8';
const base = fixture.base_url;
const sourceSha256 = {};
const { createHash } = await import('node:crypto');
for (const path of ['plugins/vmod/circularity/classes/EntryService.php', 'themes/vmod/pages/circularity-entries.htm', 'themes/vmod/layouts/default.htm', 'themes/vmod/pages/circularity-reporting.htm',
    'plugins/vmod/circularityui/components/Capture.php', 'plugins/vmod/circularityui/classes/CircularityClient.php',
    'plugins/vmod/circularity/classes/CaptureApi.php', 'tests/e2e/circularity-reporting.mjs', 'tests/vmod/check-circularity-http.php']) {
    sourceSha256[path] = createHash('sha256').update(await fs.readFile('/Users/stev/htdoc/vmod_wesria/' + path)).digest('hex');
}
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
assert.equal(fixture.runtime.host, 'mysql-test');
assert.equal(fixture.runtime.database, 'vmod_wesria_test');
await fs.mkdir(output, { recursive: true });
const task = await taskSpace('Wesria Circularity Phase 8 acceptance');
const page = task.page('p1');
let report = { spaceId: task.spaceId, origin: base, runtime: fixture.runtime, fixtureNonce: fixture.nonce, sourceSha256, started: new Date().toISOString(), scenarios: [], screenshots: [], history: [], runtimeErrors: [], passed: false };
let old;
try { old = JSON.parse(await fs.readFile(output + '/report.json', 'utf8')); } catch {}
if (old?.origin === base && old.fixtureNonce === fixture.nonce) {
    assert.deepEqual(old.sourceSha256, sourceSha256, 'Source changed: restart the guarded fixture runtime before acceptance');
    report = old;
}
report.passed = false;
const done = name => report.scenarios.includes(name);
console.log({ spaceId: task.spaceId, origin: base });
const desktop = { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false };
const mobile = { width: 390, height: 844, deviceScaleFactor: 1, mobile: true };
await page.cdp('Runtime.enable');
await page.events();
await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
async function persist() {
    await fs.writeFile(proof + '/fixture.json', JSON.stringify(fixture), { mode: 0o600 });
    await fs.writeFile(output + '/report.json', JSON.stringify(report, null, 2));
}
async function passed(name) {
    report.runtimeErrors.push(...(await page.events()).filter(e => e.method === 'Runtime.exceptionThrown').map(e => e.params.exceptionDetails.text));
    report.scenarios.push(name); await persist(); console.log('PASS ' + name);
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
async function shot(name) {
    const size = await page.evaluate(async () => { await document.fonts.ready; return [innerWidth, document.documentElement.scrollWidth]; });
    assert.ok(size[1] <= size[0] + 1, 'No horizontal page overflow');
    const path = output + '/' + name + '.png';
    await page.screenshot({ path, fullPage: false, raw: true });
    if (!report.screenshots.includes(path)) report.screenshots.push(path); await persist();
}
const scope = new URLSearchParams({ branch_ref: String(fixture.a.branch_id), area_id: String(fixture.areas.main), date_from: fixture.today, date_to: fixture.today });
const periodValue = (type, period = 'current') => page.evaluate(({ type, period }) => document.querySelector(`[data-report-type="${type}"] [data-period="${period}"]`)?.textContent.trim(), { type, period });
async function poll(read, label) {
    const until = Date.now() + 15000;
    while (Date.now() < until) { const value = await read(); if (value) return value; await new Promise(resolve => setTimeout(resolve, 40)); }
    throw new Error('Timed out: ' + label);
}
async function control(action, value) {
    const path = proof + '/controls/' + crypto.randomUUID();
    await fs.writeFile(path + '.tmp', JSON.stringify({ action, value }));
    await fs.rename(path + '.tmp', path + '.request.json');
    return poll(async () => { try { return JSON.parse(await fs.readFile(path + '.response.json', 'utf8')); } catch { return null; } }, action);
}
async function receipts() { return (await fs.readFile(proof + '/receipts.jsonl', 'utf8')).trim().split('\n').filter(Boolean).map(line => JSON.parse(line)); }
async function arm(mode) {
    const id = crypto.randomUUID().replaceAll('-', '');
    await fs.writeFile(proof + '/barriers/armed.tmp', JSON.stringify({ id, mode, uuid: null, method: 'GET', path: 'api/circularity/v1/summary' }));
    await fs.rename(proof + '/barriers/armed.tmp', proof + '/barriers/armed.json'); return id;
}
async function login() {
    await go('/account');
    if ((await page.url()).includes('/account')) await submit('header form[data-request="identity::onLogout"]');
    await page.fill('#email', fixture.b.email); await page.fill('#password', fixture.password);
    await submit('form[data-request="identity::onLogin"]');
    const workspace = `form:has(input[name=company_id][value="${fixture.a.company_id}"])`;
    await page.selectOption(workspace + ' select', ''); await submit(workspace);
}
async function historyEntry() { const h = await page.cdp('Page.getNavigationHistory'); return h.entries[h.currentIndex].id; }
async function historyReturn(name, path, change, verify, forward = false) {
    await go('/account'); const anchor = await historyEntry();
    await go(path); const target = await historyEntry(); const key = 'reporting-' + crypto.randomUUID();
    await page.evaluate(key => {
        window.__reportDocument = key;
        addEventListener('pagehide', e => sessionStorage.setItem(key + '-hide', JSON.stringify({ persisted: e.persisted,
            hidden: document.querySelector('.page').style.display === 'none', inert: document.querySelector('.page').inert })));
        addEventListener('pageshow', e => { if (e.persisted) sessionStorage.setItem(key + '-show', 'true'); });
    }, key);
    if (forward) { await page.cdp('Page.navigateToHistoryEntry', { entryId: anchor }); await page.waitForSelector('#first_name'); }
    else await go('/account');
    assert.deepEqual(await page.evaluate(key => JSON.parse(sessionStorage.getItem(key + '-hide')), key), { persisted: true, hidden: true, inert: true });
    await change(); await page.cdp('Page.navigateToHistoryEntry', { entryId: target });
    await page.waitForFunction(key => sessionStorage.getItem(key + '-show') === 'true' && !!document.body && !window.__reportDocument, key, { timeout: 15000 });
    await verify();
    report.history.push({ name, direction: forward ? 'Forward' : 'Back', concealed: true, persisted: true, replaced: true });
    await page.evaluate(key => { sessionStorage.removeItem(key + '-hide'); sessionStorage.removeItem(key + '-show'); }, key);
}
const groupedValue = (key, type = 'food_waste') => page.evaluate(({ key, type }) => document.querySelector(`[data-report-key="${key}"] [data-report-type="${type}"]`)?.textContent.trim(), { key, type });
async function switchReport(value) { await page.selectOption('#filter-report', value); await submit('#circularity-filters'); }
async function showTable() {
    await page.hover('#report-title'); await page.mouse.move(3, 700); await page.mouse.wheel(0, 400);
}
try {
    await control('permissions', 'full'); await control('branches', 'all'); await login();
    if (!done('Desktop and mobile navigation, exact period totals and record drilldowns')) {
        await go('/circularity');
        await page.click('nav[aria-label="Circularity sections"] a[href="/circularity/reporting"]');
        await page.waitForSelector('#period-report');
        assert.equal(await page.evaluate(() => document.querySelector('nav[aria-label="Circularity sections"] [aria-current="page"]').textContent.trim()), 'Reporting');
        assert.equal(await page.evaluate(() => document.querySelector('#filter-from').value), fixture.today.slice(0, 8) + '01');
        await go('/circularity/reporting?' + scope);
        for (const [type, expected] of Object.entries({ food_waste: '12.125', used_cooking_oil: '12.125', mixed_waste: '3.250', packaging_waste: '0.000', reusable_issued: '8', reusable_returned: '12' })) assert.equal(await periodValue(type), expected);
        assert.equal(await periodValue('food_waste', 'previous'), 'No data');
        await shot('desktop-period-filters'); await showTable(); await shot('desktop-period');
        await page.click('[data-report-type="food_waste"] [data-period="current"] a');
        await page.waitForSelector('#circularity-filters');
        const drill = new URL(await page.url()).searchParams;
        for (const [key, value] of scope) assert.equal(drill.get(key), value);
        assert.equal(drill.get('measurement_type'), 'food_waste'); assert.equal(drill.has('report'), false);
        assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 2);
        await go('/circularity/reporting?' + scope);
        await page.cdp('Emulation.setDeviceMetricsOverride', mobile);
        await page.click('button[aria-label="Toggle navigation"]'); await page.waitForSelector('#main-nav.show');
        assert.equal(await page.evaluate(() => document.querySelector('#main-nav a[href="/circularity/reporting"]').getAttribute('aria-current')), 'page');
        await shot('mobile-navigation');
        await page.click('#main-nav a[href="/circularity/reporting"]'); await page.waitForSelector('#period-report');
        await showTable(); await shot('mobile-period');
        await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
        await passed('Desktop and mobile navigation, exact period totals and record drilldowns');
    }
    if (!done('Grouped reports reconcile with scoped records, filters, refresh and reset')) {
        await go('/circularity/reporting?' + scope);
        await switchReport('area'); assert.equal(await groupedValue(fixture.areas.main), '12.125');
        await page.reload(); await page.waitForSelector('#grouped-report');
        assert.equal(await page.evaluate(() => document.querySelector('#filter-report').value), 'area');
        assert.equal(await page.evaluate(() => document.querySelector('#filter-area').value), String(fixture.areas.main));
        await page.selectOption('#filter-destination', 'composting'); await submit('#circularity-filters');
        assert.equal(await groupedValue(fixture.areas.main), '10.125');
        assert.equal(await groupedValue(fixture.areas.main, 'reusable_issued'), 'No data');
        await page.click(`[data-report-key="${fixture.areas.main}"] [data-report-type=food_waste] a`);
        await page.waitForSelector('tbody'); assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 1);
        const drill = new URL(await page.url()).searchParams;
        assert.equal(drill.get('destination'), 'composting'); assert.equal(drill.has('report'), false); assert.equal(drill.has('group_by'), false);
        await go('/circularity/reporting?' + scope); await switchReport('daily');
        assert.equal(await groupedValue(fixture.today), '12.125');
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-report-key]').length), 1);
        await page.click(`[data-report-key="${fixture.today}"] [data-report-type=food_waste] a`);
        await page.waitForSelector('tbody');
        assert.equal(new URL(await page.url()).searchParams.get('date_from'), fixture.today);
        assert.equal(new URL(await page.url()).searchParams.get('date_to'), fixture.today);
        await go('/circularity/reporting?report=court');
        assert.equal(await groupedValue(fixture.a.branch_id), '12.125'); assert.equal(await groupedValue(fixture.other_branch_id), '100.000');
        assert.equal(await page.evaluate(() => document.querySelector('[data-report-total=food_waste]').textContent.trim()), '112.125');
        await shot('desktop-court-filters'); await showTable(); await shot('desktop-court');
        await page.click(`[data-report-key="${fixture.other_branch_id}"] [data-report-type=food_waste] a`);
        await page.waitForSelector('tbody'); assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 1);
        assert.match(await page.evaluate(() => document.querySelector('tbody').textContent), /100\.000/);
        await go('/circularity/reporting?' + scope + '&report=area');
        await page.click('a[href="/circularity/reporting"].btn'); await page.waitForSelector('#period-report');
        assert.equal(new URL(await page.url()).search, '');
        assert.equal(await page.evaluate(() => document.querySelector('#filter-from').value), fixture.today.slice(0, 8) + '01');
        await go('/circularity/reporting?' + scope);
        await page.click('a[href*="destination=unknown"][href*="measurement_type=food_waste"]'); await page.waitForSelector('tbody');
        assert.match(await page.evaluate(() => document.querySelector('tbody').textContent), /2\.000/);
        await go('/circularity/reporting?' + scope);
        await page.click('[data-report-type=mixed_waste] [data-period=current] a'); await page.waitForSelector('tbody');
        assert.match(await page.evaluate(() => document.querySelector('tbody').textContent), /3\.250/);
        await passed('Grouped reports reconcile with scoped records, filters, refresh and reset');
    }
    if (!done('Oil reconciles in Overview and all reports on desktop and mobile')) {
        for (const [screen, viewport] of [['desktop', desktop], ['mobile', mobile]]) {
            await page.cdp('Emulation.setDeviceMetricsOverride', viewport);
            await go('/circularity?' + scope);
            assert.match(await page.evaluate(() => document.querySelector('#summary-used_cooking_oil').closest('.card').textContent), /12\.125/);
            await page.hover('#summary-used_cooking_oil'); await shot(screen + '-oil-overview');
            await go('/circularity/reporting?' + scope);
            for (const kind of ['period', 'area', 'daily', 'court']) {
                await page.selectOption('#filter-court', ''); await switchReport(kind);
                assert.equal(new URL(await page.url()).searchParams.get('branch_ref'), '');
                const value = kind === 'period' ? await periodValue('used_cooking_oil')
                    : await page.evaluate(() => document.querySelector('[data-report-total=used_cooking_oil]').textContent.trim());
                assert.equal(value, '12.125');
                await showTable(); await shot(screen + '-oil-' + kind);
                await page.click(kind === 'period' ? '[data-report-type=used_cooking_oil] [data-period=current] a' : '[data-report-total=used_cooking_oil] a');
                await page.waitForSelector('tbody');
                assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 2);
                const query = new URL(await page.url()).searchParams;
                assert.equal(query.get('measurement_type'), 'used_cooking_oil'); assert.equal(query.has('report'), false);
                await go('/circularity/reporting?' + scope + '&report=' + kind);
            }
            await go('/circularity/entries?new=1');
            await page.selectOption('#capture-type', 'used_cooking_oil');
            await page.focus('details summary'); await page.keyboard.press('Enter');
            assert.equal(await page.evaluate(() => document.querySelector('details').open), true);
            await page.hover('details'); await shot(screen + '-oil-guidance');
        }
        await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
        await passed('Oil reconciles in Overview and all reports on desktop and mobile');
    }
    if (!done('UI create edit remove affect the next read; inactive history and unmatched rows stay explicit')) {
        if (!fixture.reporting_area) {
            await go('/circularity/areas?new=1'); await page.selectOption('#area-court', String(fixture.a.branch_id));
            await page.fill('#area-name', 'Reporting acceptance ' + fixture.nonce); await submit('#circularity-area-form');
            fixture.reporting_area = Number(new URL(await page.url()).searchParams.get('id')); await persist();
        }
        const areaScope = new URLSearchParams({ area_id: String(fixture.reporting_area), date_from: fixture.today, date_to: fixture.today });
        await go('/circularity/reporting?report=area&' + areaScope);
        assert.match(await page.evaluate(() => document.querySelector('#grouped-report tbody').textContent), /No matching records for these filters/);
        if (!fixture.reporting_entry) {
            await go('/circularity/entries?new=1'); fixture.reporting_entry = new URL(await page.url()).searchParams.get('uuid'); await persist();
        } else await go('/circularity/entries?new=1&uuid=' + fixture.reporting_entry);
        await page.selectOption('#capture-court', String(fixture.a.branch_id)); await page.selectOption('#capture-type', 'used_cooking_oil');
        await page.focus('details summary'); await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => document.querySelector('details').open), true);
        assert.equal(await page.evaluate(() => document.querySelector('#capture-unit').textContent.trim()), 'kg');
        await shot('desktop-oil-capture');
        await page.fill('#capture-date', fixture.today); await page.selectOption('#capture-destination', 'unknown');
        await page.selectOption('#capture-area', String(fixture.reporting_area)); await page.fill('#capture-quantity', '1.125'); await submit('#circularity-entry-form');
        await go('/circularity/reporting?report=area&' + areaScope); assert.equal(await groupedValue(fixture.reporting_area, 'used_cooking_oil'), '1.125');
        await go('/circularity/entries?uuid=' + fixture.reporting_entry); await page.fill('#capture-quantity', '2.250'); await submit('#circularity-entry-form');
        await go('/circularity/reporting?report=daily&' + areaScope); assert.equal(await groupedValue(fixture.today, 'used_cooking_oil'), '2.250');
        await go('/circularity/entries?uuid=' + fixture.reporting_entry); await page.fill('#capture-quantity', '0'); await submit('#circularity-entry-form');
        await go('/circularity/reporting?' + areaScope); assert.equal(await periodValue('used_cooking_oil'), '0.000');
        await go('/circularity/entries?uuid=' + fixture.reporting_entry); await page.fill('#capture-quantity', '2.250'); await submit('#circularity-entry-form');
        await go('/circularity/areas?id=' + fixture.reporting_area); await page.click('input[type=checkbox][name=is_active]'); await submit('#circularity-area-form');
        await go('/circularity/reporting?report=area&' + areaScope); assert.equal(await groupedValue(fixture.reporting_area, 'used_cooking_oil'), '2.250');
        assert.match(await page.evaluate(() => document.querySelector('#grouped-report tbody th').textContent), /inactive/);
        await go('/circularity/entries?uuid=' + fixture.reporting_entry); await page.click('[data-request="circularityCapture::onRemoveEntry"]');
        await page.acceptDialog(); await page.waitForURL(base + '/circularity/entries');
        await go('/circularity/reporting?report=daily&' + areaScope);
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-report-key]').length), 0);
        assert.equal(await page.evaluate(() => document.querySelector('[data-report-total=used_cooking_oil]').textContent.trim()), 'No data');
        await go('/circularity/areas?id=' + fixture.reporting_area); await page.click('input[type=checkbox][name=is_active]'); await submit('#circularity-area-form');
        await go('/circularity/reporting?report=area'); await showTable(); await shot('desktop-area');
        assert.match(await page.evaluate(key => document.querySelector(`[data-report-key="${key}"]`).textContent, fixture.reporting_area), /No matching records/);
        await go('/circularity/reporting?report=court&date_from=1900-01-01&date_to=1900-01-01');
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-report-key]').length), 2);
        assert.equal(await page.evaluate(() => [...document.querySelectorAll('[data-report-key]')].every(row => row.textContent.includes('No matching records'))), true);
        await passed('UI create edit remove affect the next read; inactive history and unmatched rows stay explicit');
    }
    if (!done('Both inclusive periods reconcile to their own saved records')) {
        const previousDate = new Date(fixture.today + 'T12:00:00Z'); previousDate.setUTCDate(previousDate.getUTCDate() - 1);
        const date = previousDate.toISOString().slice(0, 10);
        await go('/circularity/entries?new=1' + (fixture.previous_entry ? '&uuid=' + fixture.previous_entry : ''));
        fixture.previous_entry = new URL(await page.url()).searchParams.get('uuid'); await persist();
        await page.selectOption('#capture-court', String(fixture.a.branch_id)); await page.selectOption('#capture-type', 'used_cooking_oil');
        await page.fill('#capture-date', date); await page.selectOption('#capture-destination', 'unknown');
        await page.selectOption('#capture-area', String(fixture.areas.main)); await page.fill('#capture-quantity', '2.750');
        await submit('#circularity-entry-form');
        await go('/circularity/reporting?' + scope);
        assert.equal(await periodValue('used_cooking_oil'), '12.125'); assert.equal(await periodValue('used_cooking_oil', 'previous'), '2.750');
        await showTable(); await shot('desktop-both-periods');
        await page.click('[data-report-type=used_cooking_oil] [data-period=previous] a'); await page.waitForSelector('tbody');
        const query = new URL(await page.url()).searchParams;
        assert.equal(query.get('date_from'), date); assert.equal(query.get('date_to'), date);
        assert.equal(query.get('area_id'), String(fixture.areas.main));
        assert.equal(await page.evaluate(() => document.querySelectorAll('tbody tr').length), 1);
        assert.match(await page.evaluate(() => document.querySelector('tbody').textContent), /2\.750/);
        await go('/circularity/entries?uuid=' + fixture.previous_entry); await page.click('[data-request="circularityCapture::onRemoveEntry"]');
        await page.acceptDialog(); await page.waitForURL(base + '/circularity/entries');
        await passed('Both inclusive periods reconcile to their own saved records');
    }
    if (!done('Read-only denial foreign courts and actual Back Forward revocation and workspace changes')) {
        await control('permissions', 'read'); await go('/circularity/reporting?' + scope);
        assert.equal(await periodValue('food_waste'), '12.125');
        await page.click('[data-report-type=food_waste] [data-period=current] a'); await page.waitForSelector('tbody');
        await page.click(`a[href="/circularity/entries?uuid=${fixture.entries.food}"]`); await page.waitForSelector('#circularity-detail');
        assert.equal(await page.evaluate(() => !!document.querySelector('#circularity-entry-form')), false);
        await control('permissions', 'full');
        await historyReturn('read-revoked', '/circularity/reporting?' + scope, () => control('permissions', 'none'), async () => {
            assert.equal((await page.fetch('/circularity/reporting')).status, 403);
            assert.equal(await page.evaluate(() => !!document.querySelector('#period-report, #grouped-report')), false);
        });
        await control('permissions', 'full');
        await historyReturn('court-revoked', '/circularity/reporting?report=court&branch_ref=' + fixture.other_branch_id, () => control('branches', 'first'), async () => {
            assert.equal((await page.fetch('/circularity/reporting?branch_ref=' + fixture.other_branch_id)).status, 404);
            assert.equal((await page.fetch('/circularity/reporting?report=area&area_id=' + fixture.areas.other)).status, 404);
            assert.equal(await page.evaluate(() => !!document.querySelector('#period-report, #grouped-report')), false);
        }, true);
        await go('/circularity/reporting?report=court');
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-report-key]').length), 1);
        await control('branches', 'all');
        await historyReturn('workspace-changed', '/circularity/reporting?report=court', async () => {
            const other = await task.newPage(); await other.goto(base + '/workspaces');
            const form = `form:has(input[name=company_id][value="${fixture.b.company_id}"])`;
            await other.selectOption(form + ' select', ''); await other.click(form + ' button[type=submit]');
            await other.waitForURL(base + '/'); await other.close();
        }, async () => {
            await page.waitForSelector('#grouped-report');
            assert.equal(await page.evaluate(() => document.querySelectorAll('[data-report-key]').length), 1);
            assert.equal(await page.evaluate(() => document.querySelector('[data-report-total=food_waste]').textContent.trim()), 'No data');
            assert.equal(await page.evaluate(key => !!document.querySelector(`[data-report-key="${key}"]`), fixture.a.branch_id), false);
        });
        await login(); await passed('Read-only denial foreign courts and actual Back Forward revocation and workspace changes');
    }
    if (!done('Real provider failure never becomes zero; previous failure preserves current totals')) {
        for (const kind of ['period', 'area']) {
            await arm('unavailable'); await go('/circularity/reporting?report=' + kind);
            assert.match(await page.evaluate(() => document.body.textContent), /temporarily unavailable/);
            assert.equal(await page.evaluate(() => !!document.querySelector('#period-report, #grouped-report')), false);
        }
        await shot('desktop-unavailable');
        // Hold the first live read, arm failure for the second, then release. Neither response is mocked.
        const barrier = await arm('revocation');
        const navigation = go('/circularity/reporting?' + scope);
        const hold = await poll(async () => (await receipts()).find(r => r.stage === 'held' && r.barrier === barrier), 'first summary held');
        await arm('unavailable'); await fs.writeFile(proof + '/barriers/' + barrier + '.release', 'release');
        await navigation;
        assert.equal(await periodValue('food_waste'), '12.125'); assert.equal(await periodValue('food_waste', 'previous'), 'Unavailable');
        assert.match(await page.evaluate(() => document.body.textContent), /previous period is temporarily unavailable/);
        assert.ok((await receipts()).some(r => r.request === hold.request && r.stage === 'completed' && r.status === 200));
        await showTable(); await shot('desktop-previous-unavailable');
        await passed('Real provider failure never becomes zero; previous failure preserves current totals');
    }
    if (!done('Keyboard filters and 390px grouped table scroll without page overflow')) {
        await go('/circularity/reporting?report=court'); await page.focus('#filter-report'); await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'filter-court');
        await page.keyboard.press('Tab'); assert.equal(await page.evaluate(() => document.activeElement.id), 'filter-area');
        await page.cdp('Emulation.setDeviceMetricsOverride', mobile); await page.reload(); await shot('mobile-filters');
        await showTable(); await page.hover('#grouped-report tbody tr:first-child th');
        await shot('mobile-grouped');
        const size = await page.evaluate(() => { const el = document.querySelector('#grouped-report').parentElement; return [el.clientWidth, el.scrollWidth]; });
        assert.ok(size[1] > size[0], 'Grouped table scrolls inside its card');
        const target = await page.evaluate(() => { const r = document.querySelector('#grouped-report tbody tr:first-child th').getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });
        await page.mouse.move(target.x, target.y); await page.mouse.wheel(900, 0);
        await page.waitForFunction(() => document.querySelector('#grouped-report').parentElement.scrollLeft > 0);
        await shot('mobile-grouped-scrolled');
        await page.cdp('Emulation.setDeviceMetricsOverride', desktop);
        await passed('Keyboard filters and 390px grouped table scroll without page overflow');
    }
    assert.deepEqual(report.runtimeErrors, []);
    await fs.copyFile(proof + '/receipts.jsonl', output + '/receipts.jsonl');
    report.passed = true; delete report.error; await persist();
    console.log({ passed: true, scenarios: report.scenarios.length, screenshots: report.screenshots.length, history: report.history });
} catch (error) {
    report.error = error.stack ?? String(error); await persist(); console.log(await page.snapshot()); throw error;
}
// Inspect screenshots, complete the private HTTP harness, then finish the TaskSpace once after final acceptance.
