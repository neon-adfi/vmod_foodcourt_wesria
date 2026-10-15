// Guarded seed first, then: ego-browser nodejs < tests/e2e/phase2.mjs
// Credentials and mailbox tokens stay in memory/private /tmp; reports never contain them.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const crypto = await import('node:crypto');
const root = '/Users/stev/htdoc/vmod_wesria';
const output = root + '/output/ego-lite/phase-2';
const fixture = JSON.parse(await fs.readFile('/private/tmp/wesria-phase2-acceptance.json', 'utf8'));
const base = 'https://wesria.local.test';
const run = crypto.randomBytes(4).toString('hex');
const task = await taskSpace(globalThis.vmodE2eSpaceId ?? 'Wesria Phase 2 acceptance');
const page = task.page('p1');
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

async function screenshot(name, viewport) {
    const state = await page.evaluate(async () => {
        await document.fonts.ready;
        return { width: innerWidth, height: innerHeight, contentWidth: document.documentElement.scrollWidth,
            styles: [...document.querySelectorAll('link[rel="stylesheet"]')].every(link => !!link.sheet),
            bootstrap: typeof window.bootstrap?.Collapse === 'function',
            framework: typeof window.oc?.request === 'function' };
    });
    assert.equal(state.width, viewport.width);
    assert.equal(state.height, viewport.height);
    assert.ok(state.contentWidth <= state.width + 1, 'No horizontal overflow');
    assert.ok(state.styles && state.bootstrap && state.framework, 'Native Tabler/October assets loaded');
    const file = output + '/' + name + '.png';
    await page.screenshot({ path: file, fullPage: false, raw: true });
    const bytes = await fs.readFile(file);
    assert.equal(bytes.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
    assert.equal(bytes.readUInt32BE(16), viewport.width);
    assert.equal(bytes.readUInt32BE(20), viewport.height);
    report.screenshots.push({ name, file, sha256: crypto.createHash('sha256').update(bytes).digest('hex') });
}

async function submit(form, path, target = page) {
    await target.evaluate(() => { document.documentElement.dataset.acceptanceNavigation = 'pending'; });
    await target.click(form + ' button[type="submit"]');
    await target.waitForFunction(() => !!document.documentElement && !document.documentElement.dataset.acceptanceNavigation, undefined, { timeout: 15000 });
    await target.waitForURL(base + path);
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

async function login(email, password) {
    await page.goto(base + '/auth');
    await page.fill('#email', email);
    await page.fill('#password', password);
    await submit('form[data-request="identity::onLogin"]', '/workspaces');
}

async function workspace(companyId, branchId = '') {
    await page.goto(base + '/workspaces');
    const form = `form:has(input[name="company_id"][value="${companyId}"])`;
    await page.selectOption(form + ' select', String(branchId));
    await submit(form, '/');
}

async function ajax(handler, data, { context, csrf = true, url } = {}, target = page) {
    return target.evaluate(async ({ handler, data, context, csrf, url }) => {
        const form = document.querySelector('form[data-workspace-context]');
        const headers = { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-AJAX-HANDLER': handler };
        if (context !== null) headers['X-Workspace-Context'] = context ?? form?.dataset.workspaceContext ?? '';
        const body = new URLSearchParams(data);
        if (csrf) body.set('_token', document.querySelector('input[name="_token"]').value);
        const response = await fetch(url || location.href, { method: 'POST', headers, body });
        return { status: response.status, body: await response.text() };
    }, { handler, data, context, csrf, url });
}

async function mailLink(email, kind) {
    const log = (await fs.readFile(root + '/storage/logs/system.log', 'utf8')).replace(/=\r?\n/g, '');
    const start = log.lastIndexOf('To: ' + email);
    assert.ok(start >= 0, 'Expected message in native log mail sink');
    const matches = log.slice(start).match(kind === 'invitation' ? /https:\/\/wesria\.local\.test\/invitations\/[a-f0-9]{64}/g : /https:\/\/wesria\.local\.test\/auth\/reset[^\s<>"']+/g);
    assert.ok(matches?.length, 'Expected native mail link');
    return matches.at(-1).replaceAll('=3D', '=').replaceAll('&amp;', '&');
}

const probe = output + '/checkout-' + run + '.txt';
try {
    await fs.writeFile(probe, run, { flag: 'wx' });
    await logout();
    await page.goto(base + '/auth');
    const served = await page.evaluate(async path => (await fetch(path, { cache: 'no-store' })).text(), '/output/ego-lite/phase-2/checkout-' + run + '.txt');
    assert.equal(served, run);
    passed('Exact served checkout provenance');
    await page.focus('#email');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'password');
    await screenshot('desktop-auth', desktop);
    passed('Desktop sign-in assets and keyboard order');

    await login(fixture.a.email, fixture.password);
    await screenshot('desktop-workspaces', desktop);
    await workspace(fixture.a.company_id);
    await page.goto(base + '/settings/company');
    const originalName = await page.evaluate(() => document.querySelector('#company-name').value);
    const stale = await ajax('workspaces::onCompany', { name: 'Must not save' }, { context: 'stale' });
    assert.equal(stale.status, 409);
    const missing = await ajax('workspaces::onCompany', { name: 'Must not save' }, { context: null });
    assert.equal(missing.status, 409);
    const csrf = await ajax('workspaces::onCompany', { name: 'Must not save' }, { csrf: false });
    assert.equal(csrf.status, 419);
    const foreign = await ajax('workspaces::onBranch', { id: fixture.b.branch_id, name: 'Must not save' });
    assert.equal(foreign.status, 404);
    assert.ok(!foreign.body.includes('vmod_core_'), 'Foreign response hides internal records');
    await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#company-name').value), originalName);
    passed('Real CSRF 419, foreign 404, missing/stale context 409 with no writes');

    await page.fill('#company-name', 'Browser company ' + run);
    await submit('form[data-request="workspaces::onCompany"]', '/settings/company');
    await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#company-name').value), 'Browser company ' + run);
    await page.click('#new-branch summary');
    await page.fill('#new-branch-name', 'Browser branch ' + run);
    await submit('#new-branch form', '/settings/company');
    await page.waitForFunction(text => document.body.innerText.includes(text), 'Browser branch ' + run);
    await screenshot('desktop-company', desktop);
    passed('Company and branch saves survive reload');

    await page.goto(base + '/account');
    await page.fill('#first_name', 'x'.repeat(256));
    await page.fill('#last_name', 'Preserved value');
    await page.click('form[data-request="identity::onProfile"] button');
    await page.waitForFunction(() => document.querySelector('#first_name').classList.contains('is-invalid'));
    assert.equal(await page.evaluate(() => document.querySelector('#last_name').value), 'Preserved value');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'first_name');
    passed('Server validation preserves values and focuses the field');
    await page.fill('#first_name', 'Owner A');
    await submit('form[data-request="identity::onProfile"]', '/account');
    await page.reload();
    assert.equal(await page.evaluate(() => document.querySelector('#last_name').value), 'Preserved value');
    await screenshot('desktop-account', desktop);
    passed('Self-account saves without requiring a workspace mutation token');

    await page.goto(base + '/settings/roles');
    await page.click('#new-role summary');
    await page.fill('#role-name-new', 'Browser reader ' + run);
    await page.click('#new-role input[value="catalogue.read"]');
    await submit('#new-role form', '/settings/roles');
    await page.reload();
    await page.waitForFunction(text => document.body.innerText.includes(text), 'Browser reader ' + run);
    await screenshot('desktop-roles', desktop);
    passed('Role creation persists explicit permissions');

    await page.goto(base + '/settings/members');
    const member = '#member-' + fixture.cross_member_id;
    await page.click(member + ' summary');
    await page.click(member + ' input[type="checkbox"][name="is_active"]');
    await submit(member + ' form', '/settings/members');
    assert.ok((await page.evaluate(selector => document.querySelector(selector).innerText, member)).includes('Disabled'));
    await page.click(member + ' summary');
    await page.click(member + ' input[type="checkbox"][name="is_active"]');
    await submit(member + ' form', '/settings/members');
    assert.ok((await page.evaluate(selector => document.querySelector(selector).innerText, member)).includes('Active'));
    const invitedEmail = 'browser-invite-' + run + '@example.invalid';
    await page.fill('#invite-email', invitedEmail);
    await page.selectOption('#invite-role', String(fixture.reader_role_id));
    await page.click(`form:has(#invite-email) input[name="branch_ids[]"][value="${fixture.a.branch_id}"]`);
    await submit('form:has(#invite-email)', '/settings/members');
    let invitationURL = await mailLink(invitedEmail, 'invitation');
    await screenshot('desktop-members', desktop);
    passed('Membership disable/restore and invitation persisted in local mail sink');

    const revoke = `form[data-request="workspaces::onRevoke"]:has(input[name="id"])`;
    const inviteId = await page.evaluate(email => {
        const row = [...document.querySelectorAll('.border-bottom.py-3')].find(row => row.innerText.includes(email));
        return row.querySelector('input[name="id"]').value;
    }, invitedEmail);
    const revokeButton = revoke + `:has(input[value="${inviteId}"]) button`;
    await page.click(revokeButton);
    await page.dismissDialog();
    await page.reload();
    await page.waitForSelector(revokeButton);
    await page.evaluate(() => { document.documentElement.dataset.acceptanceNavigation = 'pending'; });
    await page.click(revokeButton);
    await page.acceptDialog();
    await page.waitForFunction(() => !!document.documentElement && !document.documentElement.dataset.acceptanceNavigation);
    assert.equal(await page.evaluate(id => !!document.querySelector(`form[data-request="workspaces::onRevoke"] input[value="${id}"]`), inviteId), false);
    await page.goto(invitationURL);
    await page.waitForFunction(() => document.body.innerText.includes('Invitation unavailable'));
    await page.goto(base + '/settings/members');
    await page.fill('#invite-email', invitedEmail);
    await page.selectOption('#invite-role', String(fixture.reader_role_id));
    await page.click(`form:has(#invite-email) input[name="branch_ids[]"][value="${fixture.a.branch_id}"]`);
    await submit('form:has(#invite-email)', '/settings/members');
    invitationURL = await mailLink(invitedEmail, 'invitation');
    passed('Invitation revoke cancellation preserves access; confirmation invalidates the link');

    await logout();
    await page.goto(invitationURL);
    await page.waitForSelector('#first_name');
    await screenshot('desktop-invitation', desktop);
    const invitedPassword = crypto.randomBytes(16).toString('hex') + '9!';
    await page.fill('#first_name', 'Invited member');
    await page.fill('#password', invitedPassword);
    await page.fill('#password_confirmation', invitedPassword);
    await page.fill('#last_name', 'x'.repeat(256));
    await page.click('form[data-request="identity::onAccept"] button');
    await page.waitForFunction(() => document.querySelector('#last_name').classList.contains('is-invalid'));
    assert.equal(await page.evaluate(() => document.activeElement.id), 'last_name');
    assert.equal(await page.evaluate(() => document.querySelector('#first_name').value), 'Invited member');
    await page.fill('#last_name', '');
    await submit('form[data-request="identity::onAccept"]', '/workspaces');
    await workspace(fixture.a.company_id, fixture.a.branch_id);
    await page.goto(base + '/workspaces');
    assert.equal((await ajax('workspaces::onCompany', { name: 'Forbidden' }, { url: base + '/settings/company' })).status, 403);
    await page.goto(invitationURL);
    await page.waitForFunction(() => document.body.innerText.includes('Invitation unavailable'));
    passed('New-user one-form acceptance, native login, forbidden mutation, token replay');

    await page.goto(base + '/account');
    const changedPassword = crypto.randomBytes(16).toString('hex') + '8!';
    await page.fill('#current_password', invitedPassword);
    await page.fill('#password', changedPassword);
    await page.fill('#password_confirmation', changedPassword);
    await submit('form[data-request="identity::onPassword"]', '/account');
    await logout();
    await login(invitedEmail, changedPassword);
    passed('Native self-password change and fresh login');
    // Keep an authenticated tab while resetting; the native persist code must invalidate it.
    const other = await task.newPage();
    await other.goto(base + '/account');
    await page.goto(base + '/auth/recover');
    await page.fill('input[name="email"]', invitedEmail);
    await page.click('form[data-request="identity::onRecover"] button');
    await page.waitForFunction(() => document.body.innerText.includes('Recovery requested.'));
    const resetURL = await mailLink(invitedEmail, 'recovery');
    await page.goto(resetURL);
    await page.fill('#password', invitedPassword);
    await page.fill('#password_confirmation', invitedPassword);
    await submit('form[data-request="identity::onReset"]', '/auth');
    await other.goto(base + '/account');
    await other.waitForURL(base + '/auth');
    await other.close();
    await login(invitedEmail, invitedPassword);
    await logout();
    await page.goto(base + '/auth/recover');
    await page.fill('input[name="email"]', 'unknown-' + run + '@example.invalid');
    await page.click('form[data-request="identity::onRecover"] button');
    await page.waitForFunction(() => document.body.innerText.includes('Recovery requested.'));
    passed('Native recovery, generic unknown response, existing session revocation, fresh login');

    await login(fixture.b.email, fixture.password);
    const branches = await page.evaluate(companyId => {
        const form = [...document.querySelectorAll('form')].find(form => form.querySelector('[name="company_id"]')?.value === String(companyId));
        return [...form.querySelectorAll('option')].map(option => option.value);
    }, fixture.a.company_id);
    assert.deepEqual(branches.filter(Boolean), [String(fixture.a.branch_id)]);
    await workspace(fixture.b.company_id);
    await page.goto(base + '/settings/company');
    const oldContext = await page.evaluate(() => document.querySelector('form[data-workspace-context]').dataset.workspaceContext);
    await workspace(fixture.a.company_id, fixture.a.branch_id);
    await page.goto(base + '/workspaces');
    assert.equal((await ajax('workspaces::onCompany', { name: 'Stale foreign write' }, { context: oldContext, url: base + '/settings/company' })).status, 409);
    assert.equal((await ajax('workspaces::onCompany', { name: 'Forbidden' }, { url: base + '/settings/company' })).status, 403);
    await page.goto(base + '/workspaces');
    const simultaneous = await page.evaluate(async ids => {
        const token = document.querySelector('form[data-workspace-context]').dataset.workspaceContext;
        const csrf = document.querySelector('[name="_token"]').value;
        return Promise.all(ids.map(company_id => fetch(location.href, { method: 'POST', headers: {
            'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest',
            'X-AJAX-HANDLER': 'workspaces::onSwitch', 'X-Workspace-Context': token,
        }, body: new URLSearchParams({ company_id, _token: csrf }) }).then(response => response.status)));
    }, [fixture.a.company_id, fixture.b.company_id]);
    assert.deepEqual(simultaneous.sort(), [200, 409]);
    passed('Restricted branch visibility, stale cross-company forms, same-session simultaneous switching');

    await workspace(fixture.b.company_id);
    await page.goto(base + '/settings/members');
    await page.fill('#invite-email', invitedEmail);
    await page.selectOption('#invite-role', String(fixture.b.role_id));
    await page.click(`form:has(#invite-email) input[name="branch_ids[]"][value="${fixture.b.branch_id}"]`);
    await submit('form:has(#invite-email)', '/settings/members');
    const existingURL = await mailLink(invitedEmail, 'invitation');
    await page.goto(existingURL);
    await page.waitForFunction(() => document.body.innerText.includes('Sign out, then use the invited account'));
    await submit('main form[data-request="identity::onLogout"]', new URL(existingURL).pathname);
    await page.click('a[href="/auth"].btn');
    await page.fill('#email', invitedEmail);
    await page.fill('#password', invitedPassword);
    await submit('form[data-request="identity::onLogin"]', new URL(existingURL).pathname);
    assert.equal(await page.evaluate(() => document.querySelectorAll('input[type="password"]').length), 0);
    await submit('form[data-request="identity::onAccept"]', '/workspaces');
    await page.waitForFunction(() => document.querySelectorAll('form[data-request="workspaces::onSwitch"]').length === 2);
    passed('Existing-user explicit acceptance and wrong-account sign-out return flow');
    await logout();
    await login(fixture.b.email, fixture.password);

    await page.cdp('Emulation.setDeviceMetricsOverride', mobile);
    await workspace(fixture.b.company_id);
    await page.click('.navbar-toggler');
    await page.waitForSelector('#main-nav.show');
    await page.click('#main-nav a[href="/settings/company"]');
    await page.waitForURL(base + '/settings/company');
    await screenshot('mobile-company', mobile);
    await page.goto(base + '/account');
    await screenshot('mobile-account', mobile);
    await page.goto(base + '/workspaces');
    await screenshot('mobile-workspaces', mobile);
    await logout();
    await screenshot('mobile-auth', mobile);
    await page.fill('#email', fixture.b.email);
    await page.fill('#password', fixture.password);
    await submit('form[data-request="identity::onLogin"]', '/workspaces');
    passed('Mobile navigation, forms, workspaces and native login');

    const events = await page.events();
    const exceptions = events.filter(event => event.method === 'Runtime.exceptionThrown' || (event.method === 'Runtime.consoleAPICalled' && event.params.type === 'error'));
    assert.equal(exceptions.length, 0, 'No JavaScript runtime exceptions or console errors');
    report.runtimeErrors = 0;
    report.expectedRejections = [403, 404, 409, 419];
    passed('No unexpected browser runtime errors');
    report.passed = true;
    // The caller finishes the one task space after visually inspecting the screenshots.
} catch (error) {
    report.failure = error.name + ': ' + error.message.replace(/https:\/\/[^\s]+/g, '[URL omitted]');
    throw new Error(report.failure);
} finally {
    await fs.unlink(probe).catch(() => {});
    await fs.writeFile(output + '/report.json', JSON.stringify(report, null, 2) + '\n');
}
