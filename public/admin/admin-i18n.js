(() => {
    'use strict';

    // Compatibility shim for old PHP 7 builds that still load this asset.
    // The administration interface is English-only on every page.
    function enforceEnglish() {
        document.documentElement.lang = 'en';
        document.querySelectorAll('#muchoLangSwitcher').forEach(node => node.remove());
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enforceEnglish, {once: true});
    } else {
        enforceEnglish();
    }
})();
