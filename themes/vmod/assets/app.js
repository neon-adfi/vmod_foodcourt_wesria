import { bootstrap } from './tabler.min.js';
window.bootstrap = bootstrap;

document.addEventListener('ajax:setup', event => {
    const form = event.target.closest?.('form[data-workspace-context]');
    if (form) {
        const options = event.detail.context.options;
        options.headers = { ...options.headers, 'X-Workspace-Context': form.dataset.workspaceContext };
        const product = form.querySelector('select[data-package-product]');
        if (product) {
            options.data = { ...options.data, expected_base_uom: product.selectedOptions[0]?.dataset.baseUom ?? '' };
        }
    }
    if (event.detail.context.handler !== 'invoiceReferences::onChoices') {
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
        const recordUrl = url.pathname + (/^[1-9]\d*$/.test(id ?? '') ? `?${key}=${id}` : '');
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
    const lookup = context.handler === 'invoiceReferences::onChoices';
    let message;
    if (responseCode === 419) message = 'Your security token has expired. This request was rejected.';
    else if (responseCode === 401) message = 'Your session has expired. This request was rejected.';
    else if (responseCode <= 0) message = lookup
        ? 'Documents could not be loaded. Try Find documents again.'
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
    if (!context.requestSent || context.handler === 'invoiceReferences::onChoices') return;
    showRequestError('The request was cancelled. Save outcome unknown. Check saved records before retrying. If sign-in is needed, sign in in another tab, verify your workspace and open a fresh form. Keep this tab to copy your entered values.', true);
});
document.addEventListener('ajax:request-success', event => {
    if (event.detail.context.handler === 'invoiceReferences::onChoices') {
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
