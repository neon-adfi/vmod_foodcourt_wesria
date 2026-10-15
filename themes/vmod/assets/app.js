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
    document.querySelector('#request-error')?.classList.add('d-none');
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
    const error = document.querySelector('#request-error');
    error.textContent = message;
    error.classList.remove('d-none');
    error.focus();
});
window.addEventListener('ajax:error-message', event => {
    event.preventDefault();
    const error = document.querySelector('#request-error');
    error.textContent = event.detail.message;
    error.classList.remove('d-none');
    error.focus();
});

function reindexDocumentRows(form) {
    form.querySelectorAll('[data-document-rows] > [data-document-row]').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach(field => { field.name = field.name.replace(/lines\[(?:\d+|__INDEX__)\]/, `lines[${index}]`); });
        row.querySelectorAll('[data-validate-for]').forEach(field => { field.dataset.validateFor = field.dataset.validateFor.replace(/lines\.(?:\d+|__INDEX__)\./, `lines.${index}.`); });
    });
}
function filterOffers(form) {
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
    if (add) form.querySelector('[data-document-rows]').append(form.querySelector('[data-row-template]').content.cloneNode(true));
    if (remove) remove.closest('[data-document-row]').remove();
    reindexDocumentRows(form);
    filterOffers(form);
});
document.addEventListener('change', event => {
    const form = event.target.closest('form');
    if (!form) return;
    if (event.target.matches('[data-po-supplier]')) filterOffers(form);
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
