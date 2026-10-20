import { bootstrap } from './tabler.min.js';
window.bootstrap = bootstrap;

const readOnlyHandlers = ['invoiceReferences::onChoices', 'circularityCapture::onGroup', 'circularityCapture::onCheckEntry'];
// Server validation is authoritative; no identity or form state is copied into browser storage.
async function checkPageContext(context) {
    try {
        const response = await fetch('/account', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest',
                'X-AJAX-HANDLER': 'identity::onCheckContext', 'X-Workspace-Context': context },
            body: new URLSearchParams({ _token: document.querySelector('input[name="_token"]')?.value ?? '' }),
            signal: AbortSignal.timeout(10000)
        });
        if (!response.ok) return response.status;
        return (await response.json()).valid === true ? 200 : 0;
    } catch { return 0; }
}

const historyContext = document.body.dataset.historyContext;
let historyFocus;
window.addEventListener('pagehide', event => {
    if (!event.persisted || !historyContext) return;
    historyFocus = document.activeElement;
    const content = document.querySelector('.page');
    content.style.display = 'none'; content.inert = true;
});
async function restoreProtectedHistory() {
    let recovery = document.querySelector('#history-recovery');
    if (!recovery) {
        recovery = document.createElement('div'); recovery.id = 'history-recovery';
        recovery.className = 'container-xl my-4 alert alert-warning';
        recovery.setAttribute('role', 'status'); recovery.tabIndex = -1;
        document.body.append(recovery);
    }
    recovery.textContent = 'Checking your current account and workspace…';
    const status = await checkPageContext(historyContext);
    if (status === 200) {
        recovery.remove();
        const content = document.querySelector('.page');
        content.style.display = ''; content.inert = false;
        historyFocus?.focus({ preventScroll: true });
    } else if ([401,403,409,419].includes(status)) {
        location.reload();
    } else {
        recovery.textContent = 'Your account and workspace could not be verified. Protected content remains hidden.';
        const retry = document.createElement('button'); retry.type = 'button';
        retry.className = 'btn btn-primary ms-3'; retry.textContent = 'Try again';
        retry.addEventListener('click', restoreProtectedHistory, { once: true });
        const login = document.createElement('a'); login.href = '/auth'; login.target = '_blank';
        login.rel = 'noopener'; login.className = 'btn btn-outline-secondary ms-2'; login.textContent = 'Sign in';
        recovery.append(retry, login); recovery.focus();
    }
}
window.addEventListener('pageshow', event => {
    if (event.persisted && historyContext) restoreProtectedHistory();
});

document.addEventListener('ajax:setup', event => {
    const form = event.target.closest?.('form[data-workspace-context]');
    if (form) {
        const options = event.detail.context.options;
        options.headers = { ...options.headers, 'X-Workspace-Context': form.dataset.workspaceContext };
        const handler = event.detail.context.handler;
        if (handler !== 'workspaces::onSwitch' && !handler.startsWith('identity::')) {
            const followSave = async href => {
                const status = await checkPageContext(form.dataset.workspaceContext);
                const target = new URL(href, location.href);
                if (target.origin !== location.origin) return;
                if (status === 200) { location.assign(target.href); return; }
                const message = status === 409
                    ? 'Save completed, but your workspace changed. Verify the original workspace and check the saved record before retrying.'
                    : 'Save completed, but your current account and workspace could not be verified. Sign in if needed and check the saved record before retrying.';
                showRequestError(message);
                const error = document.querySelector('#request-error');
                for (const [label, url] of [['Choose workspace', '/workspaces'], ['Check saved record', target.pathname + target.search]]) {
                    const link = document.createElement('a'); link.textContent = label; link.href = url;
                    link.target = '_blank'; link.rel = 'noopener'; link.className = 'd-inline-block me-3 mt-2';
                    error.append(document.createTextNode(' '), link);
                }
            };
            options.handleRedirectResponse = followSave;
            options.handleReloadResponse = () => followSave(location.href);
        }
        const product = form.querySelector('select[data-package-product]');
        if (product) {
            options.data = { ...options.data, expected_base_uom: product.selectedOptions[0]?.dataset.baseUom ?? '' };
        }
    }
    if (!readOnlyHandlers.includes(event.detail.context.handler)) {
        document.querySelector('#request-error')?.classList.add('d-none');
        document.querySelectorAll('[data-request-notice].alert-success, .flash-message.success').forEach(notice => notice.remove());
    }
    event.target.closest?.('form')?.querySelectorAll('.is-invalid').forEach(field => field.classList.remove('is-invalid'));
});
window.addEventListener('ajax:invalid-field', event => {
    const { element, isFirst } = event.detail;
    const field = element?.type === 'hidden'
        ? element.form?.querySelector(`input[type="checkbox"][name="${CSS.escape(element.name)}"]`) ?? element
        : element;
    if (field !== element) event.preventDefault();
    field?.classList.add('is-invalid');
    if (isFirst) field?.focus();
});
document.addEventListener('ajax:before-validate', event => {
    const { fields, message } = event.detail;
    if (!message || Object.keys(fields ?? {}).length) return;
    // Native validation handles field errors; keep non-field conflicts visible after the flash disappears.
    showRequestError(message);
});
window.addEventListener('ajax:error-message', event => {
    event.preventDefault();
    showRequestError(event.detail.message);
});

function showRequestError(message, recovery = false, kind = '') {
    const error = document.querySelector('#request-error');
    if (!error) return;
    error.textContent = message;
    error.dataset.errorKind = kind;
    if (recovery) {
        const url = new URL(location.href);
        const key = url.pathname.startsWith('/catalogue/') ? 'edit' : 'id';
        const id = url.searchParams.get(key);
        const uuid = url.searchParams.get('uuid');
        const recordUrl = url.pathname === '/circularity/entries' && validCircularityUuid(uuid)
            ? `/circularity/entries?uuid=${uuid}`
            : url.pathname + (/^[1-9]\d*$/.test(id ?? '') ? `?${key}=${id}` : '');
        for (const [label, href] of [['Sign in', '/auth'], ['Check saved records', recordUrl]]) {
            const link = document.createElement('a');
            link.textContent = label; link.href = href; link.target = '_blank'; link.rel = 'noopener';
            link.className = 'd-inline-block me-3 mt-2'; error.append(document.createTextNode(' '), link);
        }
    }
    error.classList.remove('d-none');
    error.focus();
}
document.addEventListener('ajax:request-error', event => {
    const { responseCode, context } = event.detail;
    const lookup = readOnlyHandlers.includes(context.handler);
    let message;
    if (responseCode === 419) message = 'Your security token has expired. This request was rejected.';
    else if (responseCode === 401) message = 'Your session has expired. This request was rejected.';
    else if (responseCode <= 0) message = lookup
        ? (context.handler === 'invoiceReferences::onChoices' ? 'Documents could not be loaded. Try Find documents again.' : 'The lookup failed. Try checking again; the earlier save outcome remains uncertain.')
        : 'The connection failed. Save outcome unknown. Check saved records before retrying.';
    if (!message) return;
    event.preventDefault();
    const recovery = !lookup || responseCode === 401 || responseCode === 419;
    if (recovery) message += ' If sign-in is needed, sign in in another tab, verify your workspace and open a fresh form. Keep this tab to copy your entered values.';
    showRequestError(message, recovery, lookup && !recovery ? 'lookup' : '');
});
window.addEventListener('ajax:before-send', event => { event.detail.context.requestSent = true; });
document.addEventListener('ajax:request-cancel', event => {
    const context = event.detail.context;
    // Confirmation dismissal precedes sending; only sent mutations have an unknown outcome.
    if (!context.requestSent || readOnlyHandlers.includes(context.handler)) return;
    showRequestError('The request was cancelled. Save outcome unknown. Check saved records before retrying. If sign-in is needed, sign in in another tab, verify your workspace and open a fresh form. Keep this tab to copy your entered values.', true);
});
document.addEventListener('ajax:request-success', event => {
    if (readOnlyHandlers.includes(event.detail.context.handler)) {
        document.querySelector('#request-error[data-error-kind="lookup"]')?.classList.add('d-none');
    }
});

function reindexDocumentRows(form) {
    form.querySelectorAll('[data-document-rows] > [data-document-row]').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach(field => { field.name = field.name.replace(/lines\[(?:\d+|__INDEX__)\]/, `lines[${index}]`); });
        row.querySelectorAll('[data-validate-for]').forEach(field => { field.dataset.validateFor = field.dataset.validateFor.replace(/lines\.(?:\d+|__INDEX__)\./, `lines.${index}.`); });
    });
}
function filterOffers(form) {
    const branch = form.querySelector('[name="branch_id"]')?.value;
    form.querySelectorAll('[data-sales-location] option[data-branch]').forEach(option => {
        option.disabled = option.hidden = !!branch && option.dataset.branch !== branch;
        if (option.disabled && option.selected) option.parentElement.value = '';
    });
    const supplier = form.querySelector('[name="supplier_id"]')?.value;
    form.querySelectorAll('[data-offer-select] option[data-supplier]').forEach(option => {
        option.disabled = option.hidden = !!supplier && option.dataset.supplier !== supplier;
        if (option.disabled && option.selected) option.parentElement.value = '';
    });
}
document.addEventListener('click', event => {
    const add = event.target.closest('[data-add-row]');
    const remove = event.target.closest('[data-remove-row]');
    const form = (add ?? remove)?.closest('form');
    if (!form) return;
    let focusRow;
    if (add) {
        const rows = form.querySelector('[data-document-rows]');
        rows.append(form.querySelector('[data-row-template]').content.cloneNode(true));
        focusRow = rows.lastElementChild;
    }
    if (remove) {
        const row = remove.closest('[data-document-row]');
        focusRow = row.nextElementSibling ?? row.previousElementSibling;
        row.remove();
    }
    reindexDocumentRows(form);
    filterOffers(form);
    (focusRow?.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)') ?? form.querySelector('[data-add-row]'))?.focus();
});
document.addEventListener('change', event => {
    const form = event.target.closest('form');
    if (!form) return;
    if (event.target.matches('[data-po-supplier], [data-sales-branch]')) filterOffers(form);
    if (event.target.matches('[data-reverse-only]')) {
        form.querySelectorAll('[data-document-rows] input, [data-document-rows] select, [data-add-row], [data-remove-row]').forEach(field => { field.disabled = event.target.checked; });
    }
});
document.addEventListener('ajax:setup', event => {
    const form = event.target.closest?.('form');
    if (!form) return;
    if (form.matches('[data-document-form]')) reindexDocumentRows(form);
    const product = form.querySelector('[data-stock-product]');
    const location = form.querySelector('[data-stock-location]');
    if (product && location) {
        event.detail.context.options.data = { ...event.detail.context.options.data,
            expected_base_uom: product.selectedOptions[0]?.dataset.baseUom ?? '',
            branch_id: location.selectedOptions[0]?.dataset.branchId ?? '' };
    }
});
document.querySelectorAll('[data-document-form]').forEach(filterOffers);

function invoiceKinds() {
    const form = document.querySelector('#invoice-form');
    if (!form) return;
    const direction = form.querySelector('[name="direction"]').value;
    const select = form.querySelector('[name="kind"]');
    for (const option of select.options) option.disabled = option.hidden = option.dataset.direction !== direction;
    if (select.selectedOptions[0]?.disabled) select.value = direction === 'purchase' ? 'po' : 'so';
}
function reindexInvoiceLinks() {
    document.querySelectorAll('[data-invoice-link]').forEach((row, index) => {
        row.querySelectorAll('input').forEach(input => { input.name = input.name.replace(/links\[\d+\]/, `links[${index}]`); });
    });
}
document.addEventListener('click', event => {
    const add = event.target.closest('[data-invoice-add]');
    const remove = event.target.closest('[data-invoice-remove]');
    if (add) {
        const list = document.querySelector('#invoice-selected');
        const existing = [...list.children].find(row => row.dataset.kind === add.dataset.kind && row.dataset.id === add.dataset.id);
        if (existing) { existing.querySelector('button').focus(); return; }
        const row = document.createElement('li');
        row.className = 'd-flex gap-2 align-items-center mb-2';
        Object.assign(row.dataset, { invoiceLink: '', kind: add.dataset.kind, id: add.dataset.id, branch: add.dataset.branch, party: add.dataset.party, direction: add.dataset.direction });
        const label = document.createElement('span');
        label.textContent = `${add.dataset.kind.toUpperCase()} ${add.dataset.number} · ${add.dataset.date}`;
        row.append(label);
        for (const [field, value] of Object.entries({ kind: add.dataset.kind, id: add.dataset.id, revision_id: add.dataset.revision })) {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = `links[0][${field}]`; input.value = value; row.append(input);
        }
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'btn btn-sm btn-outline-secondary'; button.dataset.invoiceRemove = '';
        button.textContent = 'Remove'; button.setAttribute('aria-label', `Remove ${add.dataset.number}`); row.append(button);
        list.append(row); reindexInvoiceLinks(); validateInvoiceLinks(); button.focus();
    }
    if (remove) { remove.closest('[data-invoice-link]').remove(); reindexInvoiceLinks(); validateInvoiceLinks(); document.querySelector('#ref-search').focus(); }
});
document.addEventListener('change', event => {
    if (event.target.closest('#invoice-form') && ['direction', 'branch_id', 'party_id', 'kind'].includes(event.target.name)) {
        invoiceKinds(); validateInvoiceLinks(); document.querySelector('#invoice-choices').replaceChildren();
    }
});
invoiceKinds();

function invoiceScope(form) {
    return ['branch_id', 'party_id', 'direction'].map(name => form.elements[name].value);
}
function validateInvoiceLinks() {
    const form = document.querySelector('#invoice-form');
    if (!form) return true;
    const [branch, party, direction] = invoiceScope(form);
    let valid = true;
    form.querySelectorAll('[data-invoice-link]').forEach(row => {
        const mismatch = row.dataset.branch !== branch || row.dataset.party !== party || row.dataset.direction !== direction;
        let warning = row.querySelector('[data-link-warning]');
        if (mismatch && !warning) {
            warning = document.createElement('span'); warning.dataset.linkWarning = '';
            warning.className = 'text-danger'; warning.textContent = 'Incompatible branch, counterparty or direction. Remove this link or restore its original scope.';
            row.append(warning);
        }
        if (!mismatch) warning?.remove();
        valid &&= !mismatch;
    });
    if (valid) document.querySelector('#request-error[data-error-kind="invoice-scope"]')?.classList.add('d-none');
    return valid;
}
document.addEventListener('ajax:setup', event => {
    const form = event.target.closest?.('#invoice-form');
    if (!form) return;
    const context = event.detail.context;
    if (context.handler === 'invoiceReferences::onChoices') {
        context.invoiceScope = JSON.stringify([...invoiceScope(form), form.elements.kind.value, form.elements.q.value]);
    } else if (context.handler === 'invoiceReferences::onSave' && !validateInvoiceLinks()) {
        event.preventDefault();
        showRequestError('Remove incompatible document links or restore their original scope before saving.', false, 'invoice-scope');
    }
});
document.addEventListener('ajax:before-update', event => {
    const context = event.detail.context;
    const form = document.querySelector('#invoice-form');
    if (context.handler === 'invoiceReferences::onChoices' && form && context.invoiceScope !== JSON.stringify([...invoiceScope(form), form.elements.kind.value, form.elements.q.value])) {
        event.preventDefault();
    }
});
validateInvoiceLinks();

function validCircularityUuid(uuid) {
    return /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/.test(uuid ?? '');
}
function circularityLink(container, uuid, label, newTab = true) {
    if (!validCircularityUuid(uuid)) return;
    const link = document.createElement('a');
    link.href = `/circularity/entries?uuid=${uuid}`; link.textContent = label;
    link.className = 'd-inline-block me-3 mt-2';
    if (newTab) { link.target = '_blank'; link.rel = 'noopener'; }
    container.append(document.createTextNode(' '), link);
}
function circularityScope(form) {
    return JSON.stringify(['branch_ref', 'area_id', 'measurement_date', 'measurement_type', 'destination'].map(key => form.elements[key].value));
}
function circularityControls() {
    const form = document.querySelector('#circularity-entry-form');
    if (!form) return;
    const court = form.elements.branch_ref.value;
    for (const option of form.elements.area_id.options) {
        if (!option.value) continue;
        option.hidden = option.disabled = option.dataset.court !== court;
        if (option.disabled && option.selected) form.elements.area_id.value = '';
    }
    const unit = form.elements.measurement_type.selectedOptions[0].dataset.unit;
    document.querySelector('#capture-unit').textContent = unit;
    form.elements.quantity.inputMode = unit === 'count' ? 'numeric' : 'decimal';
    for (const option of form.elements.destination.options) {
        option.hidden = option.disabled = (option.value === 'not_applicable') !== (unit === 'count');
    }
    if (form.elements.destination.selectedOptions[0]?.disabled) {
        form.elements.destination.value = unit === 'count' ? 'not_applicable' : 'unknown';
    }
}
function checkCircularityGroup() {
    const form = document.querySelector('#circularity-entry-form');
    if (!form) return;
    circularityControls();
    if (['branch_ref', 'area_id', 'measurement_date'].some(key => !form.elements[key].value)) return;
    window.oc.request(form, 'circularityCapture::onGroup').catch(() => {});
}
function requireCircularityCheck(form) {
    form.dataset.needsCheck = '1';
    form.querySelectorAll('[data-circularity-save]').forEach(button => { button.disabled = true; });
    document.querySelector('#capture-recovery').textContent = 'Your values and displayed version are retained. Check saved entry before trying again.';
}
document.addEventListener('input', event => {
    if (event.target.closest('#circularity-entry-form') && ['quantity', 'reference', 'notes'].includes(event.target.name)) {
        event.target.form.dataset.measurementTouched = '1';
    }
});
document.addEventListener('change', event => {
    const form = event.target.closest('#circularity-entry-form');
    if (form && ['branch_ref', 'area_id', 'measurement_date', 'measurement_type', 'destination'].includes(event.target.name)) checkCircularityGroup();
    if (form && event.target.id === 'capture-mode') {
        form.elements.mode.value = event.target.value;
        form.dataset.modeScope = JSON.stringify(JSON.parse(circularityScope(form)).slice(0, 4));
    }
    if (event.target.id === 'filter-court') {
        document.querySelectorAll('#filter-area option[data-court]').forEach(option => {
            option.hidden = option.disabled = !!event.target.value && option.dataset.court !== event.target.value;
            if (option.disabled && option.selected) option.parentElement.value = '';
        });
    }
});
document.addEventListener('ajax:setup', event => {
    const form = event.target.closest?.('#circularity-entry-form');
    if (!form) return;
    const context = event.detail.context;
    if (context.handler === 'circularityCapture::onGroup') context.circularityScope = circularityScope(form);
    if (['circularityCapture::onSaveEntry', 'circularityCapture::onRemoveEntry'].includes(context.handler) && form.dataset.needsCheck === '1') {
        event.preventDefault();
        showRequestError('Check saved entry before trying again. Your values and displayed version are retained.');
    }
});
document.addEventListener('ajax:request-error', event => {
    const form = event.target.closest?.('#circularity-entry-form');
    const { context, responseCode, message } = event.detail;
    const serverError = responseCode >= 500 && responseCode <= 599;
    if (!form || readOnlyHandlers.includes(context.handler) || (!serverError && ![0, 409].includes(Math.max(0, responseCode)))) return;
    requireCircularityCheck(form);
    if (responseCode === 409 || serverError) {
        event.preventDefault();
        showRequestError(serverError ? 'Circularity is unavailable. Save outcome unknown. Check saved entry before retrying.'
            : 'This measurement conflicts with saved data. Check saved entry before retrying.');
        if (message?.circularityConflict?.code === 'daily_total_exists') {
            circularityLink(document.querySelector('#capture-recovery'), message.circularityConflict.uuid, 'Open existing total');
        }
    }
});
document.addEventListener('ajax:request-cancel', event => {
    const form = event.target.closest?.('#circularity-entry-form');
    if (form && event.detail.context.requestSent && !readOnlyHandlers.includes(event.detail.context.handler)) requireCircularityCheck(form);
});
document.addEventListener('ajax:request-success', async event => {
    const form = event.target.closest?.('#circularity-entry-form');
    if (!form) return;
    const { data, context } = event.detail;
    if (context.handler === 'circularityCapture::onCheckEntry') {
        const result = data.circularityCheck;
        const recovery = document.querySelector('#capture-recovery');
        const messages = {
            active: 'A saved entry exists. Open it to review. Your entered values and displayed version have not changed.',
            removed: 'This entry was removed. It cannot be saved again with this identity.',
            not_found: 'No entry was found yet. An earlier request may still finish. Any retry will use the same entry identity.'
        };
        recovery.textContent = messages[result.status];
        if (result.status === 'active') circularityLink(recovery, result.uuid, 'Open saved entry');
        form.dataset.needsCheck = result.status === 'removed' ? '1' : '';
        form.querySelectorAll('[data-circularity-save]').forEach(button => { button.disabled = result.status === 'removed'; });
    }
    if (context.handler !== 'circularityCapture::onGroup' || context.circularityScope !== circularityScope(form)) return;
    const group = data.circularityGroup;
    const modeScope = JSON.stringify(JSON.parse(context.circularityScope).slice(0, 4));
    const mode = document.querySelector('#capture-mode');
    mode.disabled = form.dataset.editing === '1' || !!group.mode;
    if (form.dataset.editing !== '1' && (group.mode || form.dataset.modeScope !== modeScope)) {
        mode.value = form.elements.mode.value = group.mode ?? 'daily_total';
    }
    form.dataset.modeScope = modeScope;
    const notice = document.querySelector('#capture-group');
    notice.textContent = group.mode ? `This group uses ${group.mode === 'daily_total' ? 'daily totals' : 'individual entries'}. Remove all its measurements before switching.`
        : 'No measurements in this group yet. Choose daily total or individual entries.';
    if (!group.uuid || group.uuid === form.elements.uuid.value) return;
    circularityLink(notice, group.uuid, 'Open existing total');
    if (form.dataset.editing === '1' || form.dataset.measurementTouched || form.dataset.needsCheck) return;
    const status = await checkPageContext(form.dataset.workspaceContext);
    if (status === 200 && !form.dataset.measurementTouched && !form.dataset.needsCheck && context.circularityScope === circularityScope(form)) {
        location.assign(`/circularity/entries?uuid=${group.uuid}`);
    }
});
circularityControls();
checkCircularityGroup();
