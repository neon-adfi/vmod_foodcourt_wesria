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
