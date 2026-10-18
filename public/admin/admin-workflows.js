(() => {
    'use strict';

    // Enhancements only. Native GET/POST forms must work without JavaScript,
    // including the deliberately CSS-free administration interface.
    const limitMiB = file => Number(file?.dataset.maxFileMib || 0);
    document.querySelectorAll('input[type="file"][data-max-file-mib]').forEach(input => {
        const check = () => {
            const files = [...(input.files || [])];
            const max = limitMiB(input) * 1024 * 1024;
            const maxFiles = Number(input.dataset.maxFiles || Infinity);
            let message = '';
            if (files.length > maxFiles) message = 'Maximum ' + maxFiles + ' files per upload.';
            else if (max && files.some(file => file.size > max)) {
                message = 'Each file must be at most ' + limitMiB(input) + ' MiB.';
            }
            input.setCustomValidity(message);
            if (message) input.reportValidity();
        };
        input.addEventListener('change', check);
        input.form?.addEventListener('submit', () => { if (!input.checkValidity()) input.reportValidity(); });
    });

    const editor = document.getElementById('newsEditorForm');
    if (editor instanceof HTMLFormElement) {
        const title = editor.elements.namedItem('title');
        const body = editor.elements.namedItem('body');
        const counter = document.getElementById('newsBodyCounter');
        const titlePreview = document.getElementById('newsPreviewTitle');
        const bodyPreview = document.getElementById('newsPreviewBody');

        const refresh = () => {
            const headline = String(title?.value || '').trim();
            const message = String(body?.value || '').trim();
            // textContent prevents user-authored news from injecting HTML.
            if (titlePreview) titlePreview.textContent = headline || 'Your headline';
            if (bodyPreview) bodyPreview.textContent = message || 'Your message will appear here.';
            if (counter) counter.textContent = [...String(body?.value || '')].length + ' / 5000 characters';
        };
        title?.addEventListener('input', refresh);
        body?.addEventListener('input', refresh);
        refresh();
    }
})();
