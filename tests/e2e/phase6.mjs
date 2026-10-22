// Guarded seed first. Resume the same space after failure with globalThis.vmodE2eSpaceId.
const assert = (await import('node:assert/strict')).default;
const fs = await import('node:fs/promises');
const crypto = await import('node:crypto');
const root = '/Users/stev/htdoc/vmod_wesria';
const output = root + '/output/ego-lite/phase-6';
const fixture = JSON.parse(await fs.readFile('/private/tmp/wesria-phase6-acceptance.json', 'utf8'));
const base = 'https://wesria.local.test';
const run = crypto.randomBytes(4).toString('hex');
const task = await taskSpace(globalThis.vmodE2eSpaceId ?? fixture.space_id ?? 'Wesria Phase 6 acceptance');
const page = task.page('p1');
console.log({ spaceId: task.spaceId });
fixture.space_id = task.spaceId;
let report = { date: new Date().toISOString(), spaceId: task.spaceId, runtime: fixture.runtime, scenarios: [], screenshots: [], passed: false };
await fs.mkdir(output, { recursive: true });
try { const previous = JSON.parse(await fs.readFile(output + '/report.json', 'utf8')); if (previous.company_id === fixture.a.company_id) report = previous; } catch {}
report.company_id = fixture.a.company_id;
const done = name => report.scenarios.some(s => s.name === name && s.passed);
async function persist() { report.screenshots = [...new Map(report.screenshots.map(s => [s.name, s])).values()]; report.pdfs = [...new Map((report.pdfs || []).map(s => [s.file, s])).values()]; await fs.writeFile('/private/tmp/wesria-phase6-acceptance.json', JSON.stringify(fixture), {mode: 0o600}); await fs.writeFile(output + '/report.json', JSON.stringify(report,null,2)); }

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
                && (location.pathname.includes('/print') || (typeof window.bootstrap?.Collapse === 'function' && typeof window.oc?.request === 'function')) };
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

async function catalogue(kind, values, selects = {}, checks = []) {
    await page.goto(base + '/catalogue/' + kind + '?new=1');
    for (const [key,value] of Object.entries(selects)) await page.selectOption('#' + key, String(value));
    for (const [key,value] of Object.entries(values)) await page.fill('#' + key, value);
    for (const key of checks) await page.click(`input[type="checkbox"][name="${key}"]`);
    await submit('#catalogue-form');
    const id = Number(await page.evaluate(() => document.querySelector('tr[data-record-id]')?.dataset.recordId));
    assert.ok(id); return id;
}
async function action(handler, reason) {
    const form = `form[data-request="${handler}"]`;
    if (reason) await page.fill(form + ' [name="reason"]', reason);
    await submit(form);
}
async function state(text) { await page.reload(); await stateContains(text); }
async function addLink(kind, number) {
    await page.selectOption('#ref-kind',kind); await page.fill('#ref-search',number);
    await page.click('button[data-request="invoiceReferences::onChoices"][data-request-data="page: 1"]');
    await page.waitForSelector('#invoice-choices [data-invoice-add]');
    await page.click('#invoice-choices [data-invoice-add]');
}
try {
    await logout(); await login(fixture.a); await workspace(fixture.a.company_id, fixture.a.branch_id);
    if (!done('Catalogue created through native UI')) {
        if (!fixture.party_id) { fixture.party_id = await catalogue('parties',{code:'PARTY',name:'Supplier <script>window.injected=true</script>',address:'15 Acceptance Road\nKuala Lumpur'}, {}, ['is_supplier','is_customer']); await persist(); }
        if (!fixture.product_id) { fixture.product_id = await catalogue('products',{sku:'RICE',name:'Acceptance rice',base_uom:'kg'}); await persist(); }
        if (!fixture.package_id) { fixture.package_id = await catalogue('packages',{code:'BAG',name:'Rice bag',conversion:'1',sales_price:'10'}, {product_id:fixture.product_id}); await persist(); }
        if (!fixture.offer_id) { fixture.offer_id = await catalogue('offers',{unit_price:'10'},{supplier_id:fixture.party_id,package_id:fixture.package_id}); await persist(); }
        if (!fixture.tax_id) { fixture.tax_id = await catalogue('tax_rates',{name:'SST',rate:'6'}); await persist(); }
        if (!fixture.location_id) { fixture.location_id = await catalogue('locations',{code:'MAIN',name:'Original store'},{branch_id:fixture.a.branch_id}); await persist(); }
        await page.reload(); await screenshot('catalogue'); passed('Catalogue created through native UI'); await persist();
    }
    if (!done('PO approval and partial/full receipts through UI')) {
        if (!fixture.po_id) {
            await page.goto(base + '/purchasing?new=1'); await page.selectOption('#po-supplier',String(fixture.party_id));
            await page.selectOption('[name="lines[0][offer_id]"]',String(fixture.offer_id));
            await page.fill('[name="lines[0][quantity]"]','10'); await page.selectOption('[name="lines[0][tax_rate_id]"]',String(fixture.tax_id));
            await page.fill('#po-notes','Saved commercial snapshot'); await submit('#purchase-form');
            fixture.po_id = Number(new URL(await page.url()).searchParams.get('id')); await persist();
        }
        await page.goto(base + '/purchasing?id=' + fixture.po_id);
        if (await page.evaluate(() => !!document.querySelector('form[data-request="purchasing::onApprove"]'))) await action('purchasing::onApprove');
        fixture.po_print = await page.evaluate(() => document.querySelector('a[href*="/documents/po/"]').getAttribute('href'));
        for (const [key,qty] of [['grn1_id','4'],['grn2_id','6']]) {
            if (!fixture[key]) {
                await page.goto(base + '/receiving?new=1&po=' + fixture.po_id); await page.fill('#receipt-ref','DEL-' + key);
                await page.selectOption('[name="lines[0][location_id]"]',String(fixture.location_id)); await page.fill('[name="lines[0][quantity]"]',qty);
                await submit('#receipt-form'); fixture[key] = Number(new URL(await page.url()).searchParams.get('id')); await persist();
            }
            await page.goto(base + '/receiving?id=' + fixture[key]);
            if (await page.evaluate(() => !!document.querySelector('form[data-request="purchasing::onConfirm"]'))) await action('purchasing::onConfirm');
            await state('confirmed'); await page.goto(base + '/purchasing?id=' + fixture.po_id); await state(key === 'grn1_id' ? 'partially_received' : 'completed');
        }
        await screenshot('purchase-completed'); passed('PO approval and partial/full receipts through UI'); await persist();
    }
    if (!done('SO reservation and partial/full dispatch through UI')) {
        if (!fixture.so_id) {
            await page.goto(base + '/sales?new=1'); await page.selectOption('#so-customer',String(fixture.party_id));
            await page.selectOption('[name="lines[0][package_id]"]',String(fixture.package_id)); await page.selectOption('[name="lines[0][location_id]"]',String(fixture.location_id));
            await page.fill('[name="lines[0][quantity]"]','6'); await page.selectOption('[name="lines[0][tax_rate_id]"]',String(fixture.tax_id));
            await submit('#sales-form'); fixture.so_id = Number(new URL(await page.url()).searchParams.get('id')); await persist();
        }
        await page.goto(base + '/sales?id=' + fixture.so_id);
        if (await page.evaluate(() => !!document.querySelector('form[data-request="sales::onApprove"]'))) await action('sales::onApprove');
        fixture.so_print = await page.evaluate(() => document.querySelector('a[href*="/documents/so/"]').getAttribute('href'));
        for (const [key,qty] of [['do1_id','2'],['do2_id','4']]) {
            if (!fixture[key]) {
                await page.goto(base + '/delivery?new=1&so=' + fixture.so_id); await page.fill('[name="lines[0][quantity]"]',qty);
                await submit('#delivery-form'); fixture[key] = Number(new URL(await page.url()).searchParams.get('id')); await persist();
            }
            await page.goto(base + '/delivery?id=' + fixture[key]);
            if (await page.evaluate(() => !!document.querySelector('form[data-request="sales::onDispatch"]'))) await action('sales::onDispatch');
            await state('dispatched'); await page.goto(base + '/sales?id=' + fixture.so_id); await state(fixture.do2_id ? 'completed' : 'partially_delivered');
        }
        await screenshot('sales-completed');
        await page.goto(base + '/inventory?q=RICE');
        const values = await page.evaluate(() => [...document.querySelectorAll('.table tbody tr:first-child td')].slice(2,5).map(td => td.textContent.trim()));
        assert.deepEqual(values,['4.0000 kg','0.0000','4.0000']);
        passed('SO reservation and partial/full dispatch through UI'); await persist();
    }
    if (!done('Invoice links, validation retention and audit through UI')) {
        await page.goto(base + '/invoice-references?new=1'); await page.selectOption('#ref-party',String(fixture.party_id));
        await page.fill('#ref-number','  inv <b>1</b>  '); await page.fill('#ref-amount','1.001');
        await addLink('po','PO-000001'); await addLink('grn','GRN-000001');
        await page.click('#invoice-form button[type="submit"]');
        await page.waitForSelector('[data-validate-for="amount"]:not(:empty)');
        assert.equal(await page.evaluate(() => document.activeElement.id),'ref-amount');
        assert.equal(await page.evaluate(() => document.querySelector('#ref-number').value),'  inv <b>1</b>  ');
        assert.equal(await page.evaluate(() => document.querySelectorAll('[data-invoice-link]').length),2);
        await screenshot('invoice-validation'); await page.fill('#ref-amount','0'); await submit('#invoice-form');
        fixture.invoice_id = Number(new URL(await page.url()).searchParams.get('id')); await persist();
        await page.reload(); await page.waitForSelector('#invoice-detail');
        assert.ok((await page.evaluate(() => document.querySelector('#invoice-detail').innerText)).includes('MYR 0.00'));
        assert.equal(await page.evaluate(() => document.querySelector('#invoice-detail b') !== null || !!window.injected),false);
        await page.fill('#ref-amount','106'); await page.fill('#ref-reason','Confirm external gross'); await submit('#invoice-form');
        await page.click('#invoice-history-tab'); await screenshot('invoice-audit');
        passed('Invoice links, validation retention and audit through UI'); await persist();
    }
    if (process.env.WESRIA_PHASE6_PRINCIPAL_ONLY === '1') {
        report.passed=false;await persist();console.log('Principal UI workflow passed; prepare the additive legacy print fixture, then resume this space.');
    } else {
    if (!done('Print snapshots, rename stability and browser PDF')) {
        await page.goto(base + '/settings/company');
        fixture.original_company_name ||= await page.evaluate(() => document.querySelector('#company-name').value);
        await page.fill('#company-name','Renamed acceptance company'); await submit('form[data-request="workspaces::onCompany"]');
        await page.click('#branch-' + fixture.a.branch_id + ' summary'); await page.fill('#branch-name-' + fixture.a.branch_id,'Renamed main');
        await submit('#branch-' + fixture.a.branch_id + ' form');
        await page.goto(base + '/catalogue/parties?edit=' + fixture.party_id); await page.fill('#name','Renamed supplier'); await submit('#catalogue-form');
        await page.goto(base + '/catalogue/locations?edit=' + fixture.location_id); await page.fill('#name','Renamed store'); await submit('#catalogue-form');
        for (const [kind,path] of [['po',fixture.po_print],['so',fixture.so_print],['grn','/documents/grn/'+fixture.grn1_id+'/print'],['do','/documents/do/'+fixture.do1_id+'/print']]) {
            await page.goto(base + path); await page.waitForSelector('#document-print');
            const text = await page.evaluate(() => document.querySelector('#document-print').innerText);
            assert.ok(text.includes(fixture.original_company_name)); assert.ok(text.includes('Supplier <script>window.injected=true</script>'));
            assert.equal(await page.evaluate(() => !!window.injected),false);
            if (['grn','do'].includes(kind)) { assert.ok(text.includes('Original store')); assert.equal(/Unit price|Tax|MYR/.test(text),false); }
            else { assert.ok(text.includes(kind === 'po' ? '106.00' : '63.60')); assert.ok(text.includes(kind === 'po' ? '10.0000 kg' : '6.0000 kg')); }
            const response = await page.fetch(path); assert.equal(response.status,200); assert.match(response.headers['cache-control'],/private.*no-store|no-store.*private/);
            await screenshot('print-'+kind);
            const pdf = await page.cdp('Page.printToPDF',{printBackground:true,preferCSSPageSize:true});
            const bytes = Buffer.from(pdf.data,'base64'); assert.equal(bytes.subarray(0,5).toString(),'%PDF-');
            const file=output+'/print-'+kind+'.pdf'; await fs.writeFile(file,bytes);
            report.pdfs ||= []; report.pdfs.push({file,sha256:crypto.createHash('sha256').update(bytes).digest('hex')});
        }
        const legacy = JSON.parse(await fs.readFile('/private/tmp/wesria-phase6-legacy.json','utf8'));
        fixture.legacy_po_id = legacy.legacy_po_id;
        await page.goto(base+'/documents/po/'+legacy.legacy_po_id+'/print');
        assert.ok((await page.evaluate(()=>document.body.innerText)).includes('Historical snapshot unavailable; current value shown'));
        assert.ok((await page.evaluate(()=>document.body.innerText)).includes('Renamed acceptance company'));
        assert.equal(await page.evaluate(()=>document.querySelectorAll('.print-table tbody tr').length),60);
        await screenshot('print-legacy-long');
        const pdf = await page.cdp('Page.printToPDF',{printBackground:true,preferCSSPageSize:true});
        const bytes=Buffer.from(pdf.data,'base64'),file=output+'/print-legacy-long.pdf';await fs.writeFile(file,bytes);
        report.pdfs.push({file,sha256:crypto.createHash('sha256').update(bytes).digest('hex')});
        passed('Print snapshots, rename stability and browser PDF'); await persist();
    }
    if (!done('Historical revisions and corrected originals remain individual records')) {
        if (!fixture.history_po_id) {
            await page.goto(base+'/purchasing?new=1');await page.selectOption('#po-supplier',String(fixture.party_id));
            await page.selectOption('[name="lines[0][offer_id]"]',String(fixture.offer_id));await submit('#purchase-form');
            fixture.history_po_id=Number(new URL(await page.url()).searchParams.get('id'));await persist();
            await action('purchasing::onApprove');
            fixture.history_print=await page.evaluate(()=>document.querySelector('a[href*="/documents/po/"]').getAttribute('href'));await persist();
            await page.goto(base+'/invoice-references?id='+fixture.invoice_id); await addLink('po','PO-000002');await page.fill('#ref-reason','Link second order');await submit('#invoice-form');
            await page.goto(base+'/purchasing?id='+fixture.history_po_id);await action('purchasing::onReopen','Revise saved commercial order');
        }
        await page.goto(base+fixture.history_print);assert.ok((await page.evaluate(()=>document.body.innerText)).includes('HISTORICAL REVISION'));
        await screenshot('print-history');
        await page.goto(base+'/invoice-references?id='+fixture.invoice_id);
        assert.ok((await page.evaluate(()=>[...document.querySelectorAll('#invoice-detail a')].map(a=>a.getAttribute('href')))).includes(fixture.history_print));
        if (!fixture.corrected_do_id) {
            await page.goto(base+'/delivery?id='+fixture.do1_id);await page.fill('[name="lines[0][quantity]"]','1');await page.fill('#correction-reason','Correct original quantity');await submit('#delivery-form');
            fixture.corrected_do_id=Number(new URL(await page.url()).searchParams.get('id'));await persist();
            await page.goto(base+'/sales?id='+fixture.so_id);await action('sales::onCloseShort','Close remaining restored reservation');
        }
        if (!fixture.corrected_grn_id) {
            await page.goto(base+'/receiving?id='+fixture.grn1_id);await page.fill('[name="lines[0][quantity]"]','3');await page.fill('#receipt-ref','DEL-CORRECTED');await page.fill('#correction-reason','Correct receipt quantity');await submit('#receipt-form');
            fixture.corrected_grn_id=Number(new URL(await page.url()).searchParams.get('id'));await persist();
            await page.goto(base+'/purchasing?id='+fixture.po_id);await action('purchasing::onCloseShort','Close outstanding receipt');
        }
        for (const [kind,id,quantity] of [['grn',fixture.grn1_id,'4.0000'],['do',fixture.do1_id,'2.0000']]) {
            await page.goto(base+'/documents/'+kind+'/'+id+'/print');const text=await page.evaluate(()=>document.body.innerText);
            assert.ok(text.includes('VOIDED')&&text.includes('Replaced by')&&text.includes(quantity));await screenshot('print-corrected-'+kind);
        }
        await page.goto(base+'/documents/so/'+fixture.so_id+'/print');assert.ok((await page.evaluate(()=>document.body.innerText)).includes('CLOSED SHORT'));await screenshot('print-closed-short');
        await page.goto(base+'/invoice-references?id='+fixture.invoice_id);await page.fill('#ref-reason','Keep terminal history');await submit('#invoice-form');
        assert.ok((await page.evaluate(()=>document.querySelector('#invoice-detail').innerText)).includes('currently voided'));
        passed('Historical revisions and corrected originals remain individual records');await persist();
    }
    if (!done('Invoice duplicate, void/restore, search and pagination')) {
        // Existing linked terminal documents are retained; pagination fixtures use the remaining confirmed receipt.
        await page.goto(base+'/invoice-references?new=1');await page.selectOption('#ref-party',String(fixture.party_id));await page.fill('#ref-number','PAGE-0');await page.fill('#ref-amount','0');await addLink('grn','GRN-000002');
        const create=await formData('#invoice-form');
        for(let n=0;n<16;n++) { const result=await ajax('invoiceReferences::onSave',{...create,number:'PAGE-'+n,operation_key:crypto.randomUUID()}); assert.ok(result.status===200 || (result.status===409 && result.body.includes('active invoice reference'))); }
        await page.goto(base+'/invoice-references');await page.click('a:has-text("Next")');assert.equal(new URL(await page.url()).searchParams.get('page'),'2');
        await page.fill('#invoice-query','PAGE-15');await page.click('form[method="get"] button');await page.waitForFunction(()=>document.querySelectorAll('.table tbody tr').length===1);
        assert.ok((await page.evaluate(()=>document.querySelector('.table tbody').innerText)).includes('PAGE-15'));await screenshot('invoice-search');
        await page.goto(base+'/invoice-references?id='+fixture.invoice_id);
        await action('invoiceReferences::onVoid','Wrong external reference');assert.equal(await page.evaluate(()=>!!document.querySelector('#invoice-form')),false);
        await action('invoiceReferences::onRestore','Restore retained links');await page.waitForSelector('#invoice-form');
        const saved=await formData('#invoice-form');saved.reason='Security probe';saved.operation_key=crypto.randomUUID();
        assert.equal((await ajax('invoiceReferences::onSave',saved,{csrf:false})).status,419);
        assert.equal((await ajax('invoiceReferences::onSave',{...saved,version:'1'})).status,409);
        assert.equal((await ajax('invoiceReferences::onSave',saved,{context:'stale'})).status,409);
        const duplicate={...create,number:' INV <B>1</B> ',operation_key:crypto.randomUUID()};assert.equal((await ajax('invoiceReferences::onSave',duplicate)).status,409);
        await page.reload();assert.equal(await page.evaluate(()=>document.querySelector('#ref-amount').value),'106.00');
        fixture.saved_form=saved;passed('Invoice duplicate, void/restore, search and pagination');await persist();
    }
    if (!done('Direct access, restricted AJAX/print and branch isolation')) {
        const saved=fixture.saved_form;
        await workspace(fixture.a.company_id,fixture.restricted_branch_id);
        for(const path of ['/invoice-references?id='+fixture.invoice_id,fixture.po_print,fixture.so_print,'/documents/grn/'+fixture.grn1_id+'/print','/documents/do/'+fixture.do1_id+'/print']) assert.equal((await page.fetch(path)).status,404);
        await logout();await login(fixture.b);await workspace(fixture.b.company_id,fixture.b.branch_id);
        assert.equal((await page.fetch('/invoice-references?id='+fixture.invoice_id)).status,404);
        assert.equal((await page.fetch(fixture.po_print)).status,404);
        await workspace(fixture.a.company_id,fixture.a.branch_id);await page.goto(base+'/invoice-references?id='+fixture.invoice_id);
        assert.equal(await page.evaluate(()=>document.querySelectorAll('#invoice-detail a').length),0);
        assert.equal(await page.evaluate(()=>!!document.querySelector('header a[href="/purchasing"]')),false);
        for(const path of [fixture.po_print,fixture.so_print,'/documents/grn/'+fixture.grn1_id+'/print','/documents/do/'+fixture.do1_id+'/print']) assert.equal((await page.fetch(path)).status,403);
        assert.equal((await ajax('purchasing::onApprove',{id:fixture.history_po_id,version:'1',operation_key:crypto.randomUUID()},{url:'/purchasing'})).status,403);
        const choices=await ajax('invoiceReferences::onChoices',{branch_id:fixture.a.branch_id,party_id:fixture.party_id,direction:'purchase',kind:'grn'});
        assert.equal(choices.status,200);assert.equal(/unit_price|supplier_snapshot|636\.00/.test(choices.body),false);await screenshot('invoice-restricted');
        await logout();assert.equal((await page.fetch(fixture.po_print)).url,base+'/auth');
        await login(fixture.a);await workspace(fixture.a.company_id,fixture.a.branch_id);
        assert.equal((await page.fetch('/documents/po/'+fixture.history_po_id+'/print?revision='+new URL(base+fixture.po_print).searchParams.get('revision'))).status,404);
        passed('Direct access, restricted AJAX/print and branch isolation');await persist();
    }
    if (!done('Mobile and keyboard acceptance')) {
        await page.cdp('Emulation.setDeviceMetricsOverride',mobile);await page.goto(base+'/invoice-references?new=1');
        await page.focus('#ref-number');await page.keyboard.press('Tab');assert.equal(await page.evaluate(()=>document.activeElement.id),'ref-date');
        await screenshot('mobile-invoice',mobile);
        await page.goto(base+'/invoice-references?id='+fixture.invoice_id);await screenshot('mobile-invoice-detail',mobile);
        await page.goto(base+fixture.po_print);await screenshot('mobile-print',mobile);
        await page.cdp('Emulation.setDeviceMetricsOverride',desktop);passed('Mobile and keyboard acceptance');await persist();
    }
    if (!done('Printed unit labels and mobile columns')) {
        await page.cdp('Emulation.setDeviceMetricsOverride',desktop);
        for (const [name,path] of [['print-po',fixture.po_print],['print-so',fixture.so_print],['print-history',fixture.history_print],['print-legacy-long','/documents/po/'+fixture.legacy_po_id+'/print'],['print-closed-short',fixture.so_print]]) {
            await page.goto(base+path);assert.match(await page.evaluate(()=>document.querySelector('.print-table').innerText),/\d+\.\d{4} kg/);
            await screenshot(name);
            if(['print-po','print-so','print-legacy-long'].includes(name)) {
                const pdf=await page.cdp('Page.printToPDF',{printBackground:true,preferCSSPageSize:true}),bytes=Buffer.from(pdf.data,'base64'),file=output+'/'+name+'.pdf';
                await fs.writeFile(file,bytes);report.pdfs.push({file,sha256:crypto.createHash('sha256').update(bytes).digest('hex')});
            }
        }
        await page.cdp('Emulation.setDeviceMetricsOverride',mobile);await page.goto(base+fixture.po_print);
        const dimensions=await page.evaluate(()=>({table:document.querySelector('.print-table').scrollWidth,wrapper:document.querySelector('.print-table-wrap').clientWidth}));
        assert.ok(dimensions.table>dimensions.wrapper);await page.focus('.print-table-wrap');await page.keyboard.press('ArrowRight');
        assert.ok(await page.evaluate(()=>document.querySelector('.print-table-wrap').scrollLeft>0));
        await page.keyboard.press('ArrowLeft');await page.waitForFunction(()=>document.querySelector('.print-table-wrap').scrollLeft===0);await screenshot('mobile-print',mobile);
        await page.cdp('Emulation.setDeviceMetricsOverride',desktop);passed('Printed unit labels and mobile columns');await persist();
    }
    await page.goto(base+'/inventory?q=RICE');
    assert.deepEqual(await page.evaluate(()=>[...document.querySelectorAll('.table tbody tr:first-child td')].slice(2,5).map(td=>td.textContent.trim())),['4.0000 kg','0.0000','4.0000']);
    await screenshot('final-stock');
    const events=await page.events(); report.runtimeErrors=events.filter(e=>e.method==='Runtime.exceptionThrown'||(e.method==='Runtime.consoleAPICalled'&&e.params.type==='error'));
    assert.equal(report.runtimeErrors.length,0,JSON.stringify(report.runtimeErrors).slice(0,1000));
    report.passed=true;delete report.failure;await persist();console.log({passed:true,scenarios:report.scenarios.length,screenshots:report.screenshots.length,runtimeErrors:0});

    }
} catch (error) {
    report.passed = false; report.failure = String(error); await persist(); console.error(error); throw error;
}
