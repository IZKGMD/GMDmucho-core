(() => {
  'use strict';

  const navigation = document.getElementById('main-navigation');
  const menu = document.getElementById('menu-toggle');
  const langButton = document.getElementById('language-switch');
  const copyButton = document.getElementById('copy-install');

  function closeMenu(restoreFocus = false) {
    if (!navigation || !menu) return;
    navigation.classList.remove('is-open');
    menu.setAttribute('aria-expanded', 'false');
    menu.setAttribute('aria-label', document.documentElement.lang === 'ru' ? 'Открыть меню' : 'Open navigation');
    if (restoreFocus) menu.focus();
  }

  if (navigation && menu) {
    menu.addEventListener('click', () => {
      const open = navigation.classList.toggle('is-open');
      menu.setAttribute('aria-expanded', String(open));
      menu.setAttribute('aria-label', open
        ? (document.documentElement.lang === 'ru' ? 'Закрыть меню' : 'Close navigation')
        : (document.documentElement.lang === 'ru' ? 'Открыть меню' : 'Open navigation'));
    });
    navigation.querySelectorAll('a').forEach(link => link.addEventListener('click', () => closeMenu()));
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && navigation.classList.contains('is-open')) closeMenu(true);
    });
    document.addEventListener('click', event => {
      if (navigation.classList.contains('is-open') &&
          !navigation.contains(event.target) && !menu.contains(event.target)) closeMenu();
    });
    window.matchMedia('(min-width: 941px)').addEventListener('change', event => {
      if (event.matches) closeMenu();
    });
  }

  const translatable = [...document.querySelectorAll('[data-ru]')];
  translatable.forEach(node => {
    // Preserve original English HTML where possible. Text-only replacement
    // applies to translated nodes; it never parses user-provided HTML.
    node.dataset.enDefault = node.textContent;
  });

  function setLanguage(lang) {
    const isRussian = lang === 'ru';
    document.documentElement.lang = isRussian ? 'ru' : 'en';
    translatable.forEach(node => {
      node.textContent = isRussian ? node.dataset.ru : node.dataset.enDefault;
    });
    if (langButton) {
      langButton.textContent = isRussian ? 'EN' : 'RU';
      langButton.setAttribute('aria-label', isRussian ? 'Switch to English' : 'Переключить на русский');
    }
    if (menu) menu.setAttribute('aria-label', isRussian ? 'Открыть меню' : 'Open navigation');
    try { localStorage.setItem('muchocore.landing.language', isRussian ? 'ru' : 'en'); }
    catch (_) { /* Private browsing is supported. */ }
  }

  let stored;
  try { stored = localStorage.getItem('muchocore.landing.language'); } catch (_) {}
  const initial = stored === 'ru' || stored === 'en'
    ? stored
    : (navigator.language || '').toLowerCase().startsWith('ru') ? 'ru' : 'en';
  setLanguage(initial);

  langButton?.addEventListener('click', () => {
    setLanguage(document.documentElement.lang === 'ru' ? 'en' : 'ru');
  });

  async function copyCommand() {
    // Do not copy illustrative prompts or comments from the terminal preview.
    const command = 'git clone https://github.com/IZKGMD/GMDmucho-core.git\ncd GMDmucho-core\nsudo ./install';
    let success = false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(command);
        success = true;
      } else {
        const area = document.createElement('textarea');
        area.value = command;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.append(area);
        area.select();
        success = document.execCommand('copy');
        area.remove();
      }
    } catch (_) { success = false; }
    if (copyButton) {
      const original = document.documentElement.lang === 'ru' ? 'Копировать' : 'Copy';
      copyButton.textContent = success
        ? (document.documentElement.lang === 'ru' ? 'Скопировано ✓' : 'Copied ✓')
        : (document.documentElement.lang === 'ru' ? 'Не удалось скопировать' : 'Copy failed');
      window.setTimeout(() => { copyButton.textContent = original; }, 1800);
    }
  }
  copyButton?.addEventListener('click', copyCommand);
})();