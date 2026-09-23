(() => {
    const KEY = 'mucho.theme';

    function preferredTheme(){
        const saved = localStorage.getItem(KEY);
        if (saved === 'light' || saved === 'dark') return saved;
        return 'dark';
    }

    function applyTheme(theme){
        document.documentElement.dataset.theme = theme;
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'light' ? '#f3f5f9' : '#090b10');

        const button = document.querySelector('.mucho-theme-toggle');
        if (button) {
            const isLight = theme === 'light';
            button.innerHTML = isLight
                ? '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 15.4A8.5 8.5 0 0 1 8.6 4a8.5 8.5 0 1 0 11.4 11.4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
            button.title = isLight ? 'Switch to dark theme' : 'Switch to light theme';
            button.setAttribute('aria-label', button.title);
            button.setAttribute('aria-pressed', String(isLight));
        }
    }

    function init(){
        applyTheme(preferredTheme());

        if (!document.querySelector('.mucho-theme-toggle')) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'mucho-theme-toggle';
            button.addEventListener('click', () => {
                const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
                localStorage.setItem(KEY, next);
                applyTheme(next);
            });
            document.body.appendChild(button);
            applyTheme(document.documentElement.dataset.theme || 'dark');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once:true});
    } else {
        init();
    }
})();