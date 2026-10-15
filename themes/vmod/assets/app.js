import { bootstrap } from './tabler.min.js';
window.bootstrap = bootstrap;

document.addEventListener('ajax:setup', event => {
    const form = event.target.closest?.('form[data-workspace-context]');
    if (form) {
        const options = event.detail.context.options;
        options.headers = { ...options.headers, 'X-Workspace-Context': form.dataset.workspaceContext };
    }
    document.querySelector('#request-error')?.classList.add('d-none');
    event.target.closest?.('form')?.querySelectorAll('.is-invalid').forEach(field => field.classList.remove('is-invalid'));
});
window.addEventListener('ajax:invalid-field', event => {
    const { element, isFirst } = event.detail;
    element?.classList.add('is-invalid');
    if (isFirst) element?.focus();
});
window.addEventListener('ajax:error-message', event => {
    event.preventDefault();
    const error = document.querySelector('#request-error');
    error.textContent = event.detail.message;
    error.classList.remove('d-none');
    error.focus();
});
