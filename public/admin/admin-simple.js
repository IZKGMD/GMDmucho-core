(() => {
    'use strict';
    const ru = () => document.documentElement.lang === 'ru';
    document.querySelectorAll('[data-password-confirm]').forEach(form => {
        const password = form.elements.new_password;
        const confirm = form.elements.new_password_confirm;
        const validate = () => confirm.setCustomValidity(confirm.value && password.value !== confirm.value
            ? (ru() ? 'Пароли не совпадают.' : 'Passwords do not match.') : '');
        password.addEventListener('input', validate);
        confirm.addEventListener('input', validate);
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        const action = form.elements.namedItem('action')?.value || '';
        if (!form.hasAttribute('onsubmit') && (form.dataset.confirm || /(?:-delete|delete-)/.test(action))) {
            if (!window.confirm(ru() ? 'Подтвердить удаление? Это действие нельзя отменить.' : (form.dataset.confirm || 'Confirm deletion? This cannot be undone.'))) {
                event.preventDefault();
                return;
            }
        }
        // Preserve submit-button names and values used by the server.
        if (event.defaultPrevented || form.dataset.submitting === 'true') {
            if (form.dataset.submitting === 'true') event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-submitting]').forEach(form => {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
        });
    });
})();
