(() => {
  'use strict';
  document.documentElement.classList.add('mc-js');
  const toggle = document.getElementById('menu-toggle');
  const nav = document.getElementById('main-navigation');
  const close = (returnFocus = false) => {
    if (!toggle || !nav) return;
    nav.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    if (returnFocus) toggle.focus();
  };
  toggle?.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  nav?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => close()));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') close(true);
  });
  document.addEventListener('click', event => {
    if (nav?.classList.contains('open') && !nav.contains(event.target) && !toggle?.contains(event.target)) close();
  });
  window.matchMedia('(min-width: 821px)').addEventListener('change', event => {
    if (event.matches) close();
  });
  const tabs = [...document.querySelectorAll('[data-demo-target]')];
  const activate = target => {
    for (const tab of tabs) {
      const active = tab === target;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      const panel = document.getElementById(tab.dataset.demoTarget);
      if (panel) panel.hidden = !active;
    }
    const edition = document.querySelector('.mc-demo-edition');
    const index = tabs.indexOf(target);
    if (edition && index >= 0) edition.textContent = '0' + (index + 1) + ' / 03';
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(tab));
    tab.addEventListener('keydown', event => {
      if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 :
        (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      activate(tabs[next]);
      tabs[next].focus();
    });
  });
  const copy = document.getElementById('copy-install');
  copy?.addEventListener('click', async () => {
    const code = document.querySelector('#install-commands code');
    if (!code) return;
    const commands = code.textContent.split('\n').filter(line => line.trim() && !line.trim().startsWith('#')).join('\n');
    const prior = copy.textContent;
    let done = false;
    try {
      if (navigator.clipboard?.writeText && window.isSecureContext) {
        await navigator.clipboard.writeText(commands);
        done = true;
      } else {
        const area = document.createElement('textarea');
        area.value = commands;
        area.setAttribute('readonly', '');
        area.style.cssText = 'position:fixed;left:-9999px';
        document.body.appendChild(area);
        area.select();
        done = document.execCommand('copy');
        area.remove();
      }
    } catch (_) { done = false; }
    copy.textContent = done ? copy.dataset.copied : copy.dataset.error;
    copy.setAttribute('aria-live', 'polite');
    window.setTimeout(() => { copy.textContent = prior; }, 1600);
  });
})();