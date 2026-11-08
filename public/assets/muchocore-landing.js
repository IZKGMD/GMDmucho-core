(() => {
  'use strict';
  document.documentElement.classList.add('js');

  const menu = document.getElementById('menu-toggle');
  const navigation = document.getElementById('main-navigation');
  const copy = document.getElementById('copy-install');
  const isRussian = document.documentElement.lang === 'ru';

  const closeMenu = (focus = false) => {
    if (!menu || !navigation) return;
    navigation.classList.remove('open');
    menu.setAttribute('aria-expanded', 'false');
    menu.setAttribute('aria-label', isRussian ? 'Открыть меню' : 'Open navigation');
    if (focus) menu.focus();
  };

  menu?.addEventListener('click', () => {
    const open = navigation?.classList.toggle('open') ?? false;
    menu.setAttribute('aria-expanded', String(open));
    menu.setAttribute('aria-label', open
      ? (isRussian ? 'Закрыть меню' : 'Close navigation')
      : (isRussian ? 'Открыть меню' : 'Open navigation'));
  });
  navigation?.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => closeMenu());
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && navigation?.classList.contains('open')) closeMenu(true);
  });
  document.addEventListener('click', e => {
    if (navigation?.classList.contains('open') && !navigation.contains(e.target) && !menu?.contains(e.target)) closeMenu();
  });
  window.matchMedia('(min-width: 821px)').addEventListener('change', e => { if (e.matches) closeMenu(); });

  copy?.addEventListener('click', async () => {
    const commands = [
      'git clone https://github.com/IZKGMD/GMDmucho-core.git',
      'cd GMDmucho-core',
      'sudo ./install'
    ].join('\n');
    let done = false;
    try {
      if (navigator.clipboard?.writeText && window.isSecureContext) {
        await navigator.clipboard.writeText(commands);
        done = true;
      } else {
        const area = document.createElement('textarea');
        area.value = commands;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        done = document.execCommand('copy');
        area.remove();
      }
    } catch (_) { /* Clipboard may be denied by browser permissions. */ }
    const original = isRussian ? 'Копировать' : 'Copy';
    copy.textContent = done ? copy.dataset.copied : copy.dataset.error;
    copy.setAttribute('aria-live', 'polite');
    window.setTimeout(() => { copy.textContent = original; }, 1800);
  });
})();