// Run with ego-browser nodejs < tests/e2e/phase1.mjs (no browser framework).
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const path = await import('node:path');
const base = 'https://wesria.local.test';
// ego-browser's Node runtime starts at / and does not inherit shell environment.
const output = '/Users/stev/htdoc/vmod_wesria/output/ego-lite/phase-1';
const nonce = (await import('node:crypto')).randomUUID();
const probe = path.join(output, `checkout-${nonce}.txt`);
await fs.mkdir(output, { recursive: true });
const task = await taskSpace(globalThis.vmodE2eSpaceId ?? 'Wesria Phase 1 acceptance');
const page = task.page('p1');
const report = { date: new Date().toISOString(), base, spaceId: task.spaceId, scenarios: [], passed: false };
console.log({ spaceId: task.spaceId });
await page.cdp('Runtime.enable');
await page.cdp('Log.enable');
await page.cdp('Network.enable');
await page.cdp('Network.setCacheDisabled', { cacheDisabled: true });
await page.events(); // Discard prior observations; never persist raw CDP events or credentials.

async function checkPage(name, requiredText, viewport) {
    await page.waitForFunction(text => document.body.innerText.includes(text), requiredText);
    const state = await page.evaluate(async () => {
        await document.fonts.ready;
        const styles = [...document.querySelectorAll('link[rel="stylesheet"]')];
        const scripts = [...document.querySelectorAll('script[src]')];
        const assets = await Promise.all([...styles, ...scripts].map(async element => {
            const url = element.href || element.src;
            const response = await fetch(url, { cache: 'reload' });
            return { url, status: response.status, type: response.headers.get('content-type') };
        }));
        return {
            url: location.href, width: innerWidth, height: innerHeight,
            contentWidth: document.documentElement.scrollWidth,
            styles: styles.length, scripts: scripts.length,
            loadedStyles: styles.filter(element => !!element.sheet).length, assets,
        };
    });
    const errors = (await page.events()).flatMap(event => {
        if (event.method === 'Runtime.exceptionThrown') return [event.params.exceptionDetails.exception?.description || event.params.exceptionDetails.text];
        if (event.method === 'Runtime.consoleAPICalled' && event.params.type === 'error') return ['Console error'];
        if (event.method === 'Log.entryAdded' && event.params.entry.level === 'error') return [event.params.entry.text];
        return [];
    });
    const scenario = { name, ...state, errors, screenshot: null, passed: false };
    report.scenarios.push(scenario);
    assert.equal(state.width, viewport.width, `${name}: viewport width`);
    assert.equal(state.height, viewport.height, `${name}: viewport height`);
    assert.ok(state.url.startsWith(base + '/'), 'Expected the owned Wesria application');
    assert.ok(state.contentWidth <= state.width + 1, `${name}: horizontal overflow`);
    assert.ok(state.styles > 0 && state.scripts > 0, `${name}: missing assets`);
    assert.equal(state.loadedStyles, state.styles, `${name}: stylesheet did not load`);
    for (const asset of state.assets) {
        assert.equal(asset.status, 200, `${name}: ${asset.url}`);
        assert.match(asset.type || '', /javascript|css/, `${name}: unexpected asset response`);
    }
    assert.deepEqual(errors, [], `${name}: browser runtime errors`);
    const screenshot = path.join(output, name + '.png');
    await page.screenshot({ path: screenshot, fullPage: false, raw: true });
    const png = await fs.readFile(screenshot);
    assert.equal(png.subarray(0, 8).toString('hex'), '89504e470d0a1a0a', `${name}: valid PNG`);
    assert.equal(png.readUInt32BE(16), viewport.width, `${name}: screenshot width`);
    assert.equal(png.readUInt32BE(20), viewport.height, `${name}: screenshot height`);
    scenario.screenshot = screenshot;
    scenario.passed = true;
}

try {
    await fs.writeFile(probe, nonce, { flag: 'wx' });
    for (const viewport of [{ name: 'desktop', width: 1440, height: 900, mobile: false }, { name: 'mobile', width: 390, height: 844, mobile: true }]) {
        await page.cdp('Emulation.setDeviceMetricsOverride', { width: viewport.width, height: viewport.height, deviceScaleFactor: 1, mobile: viewport.mobile });
        await page.goto(base + '/');
        if (!report.runtime) {
            const served = await page.evaluate(async url => {
                const response = await fetch(url, { cache: 'no-store' });
                return { status: response.status, body: await response.text() };
            }, base + '/output/ego-lite/phase-1/' + path.basename(probe));
            assert.equal(served.status, 200, 'Served checkout probe');
            assert.equal(served.body, nonce, 'HTTP route must serve this exact checkout');
            report.runtime = { checkout: '/Users/stev/htdoc/vmod_wesria', uniqueServedFileMatched: true };
        }
        await checkPage(viewport.name + '-frontend', 'Welcome to October CMS!', viewport);
        if (viewport.mobile) {
            await page.click('#layout-nav .navbar-toggler');
            await page.waitForSelector('#navbarSupportedContent.show');
        }
        const navigation = viewport.mobile ? '#navbarSupportedContent' : '#layout-nav';
        await page.click(navigation + ' a[href="https://wesria.local.test/about"]');
        await page.waitForURL(base + '/about');
        await checkPage(viewport.name + '-navigation', 'Hello! This is October CMS!', viewport);
        await page.reload();
        await checkPage(viewport.name + '-reload', 'Hello! This is October CMS!', viewport);
        await page.goto(base + '/ajax');
        await page.waitForSelector('.code-block .CodeMirror');
        const codeblocks = await page.evaluate(() => {
            const blocks = [...document.querySelectorAll('.code-block > pre')];
            const count = document.querySelectorAll('.CodeMirror').length;
            window.jQuery(document).trigger('render');
            return {
                blocks: blocks.length, count,
                afterRender: document.querySelectorAll('.CodeMirror').length,
                readable: blocks.every(block => block.querySelector('.CodeMirror')?.CodeMirror.getValue().length > 0),
                readOnly: blocks.every(block => block.querySelector('.CodeMirror')?.CodeMirror.getOption('readOnly') === true),
            };
        });
        assert.ok(codeblocks.blocks > 0, 'Native codeblocks exist');
        assert.equal(codeblocks.count, codeblocks.blocks, 'All native codeblocks initialized');
        assert.equal(codeblocks.afterRender, codeblocks.count, 'October render is idempotent');
        assert.ok(codeblocks.readable && codeblocks.readOnly, 'CodeMirror preserves readable, read-only content');
        await page.click('.explanation .code-block:first-of-type .expand-code');
        assert.equal(await page.evaluate(() => document.querySelector('.collapsed-code-block').classList.contains('collapsed')), false, 'Codeblock expands');
        await checkPage(viewport.name + '-codeblocks', 'AJAX Framework', viewport);
        await page.goto(base + '/admin');
        await page.waitForSelector('input[name="login"]');
        await page.focus('input[name="login"]');
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.name), 'password', 'Login keyboard focus order');
        await checkPage(viewport.name + '-backend-login', 'Administration Panel', viewport);
    }
    await task.finish({ keep: [] });
    report.passed = true;
} catch (error) {
    report.error = error.message;
    throw error;
} finally {
    await fs.rm(probe, { force: true });
    await fs.writeFile(path.join(output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
    console.log({ passed: report.passed, scenarios: report.scenarios.length, output });
}
// Leave a failed space available for diagnosis, as required by ego-browser.
