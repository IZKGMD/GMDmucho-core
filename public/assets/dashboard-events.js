/* Progressive enhancement for the server-rendered MuchoGDPS event board. */
(() => {
  'use strict';

  const hub = document.querySelector('.me-hub');
  if (!hub) return;

  const filters = [...hub.querySelectorAll('[data-me-filter]')];
  const cards = [...hub.querySelectorAll('.me-event-card[data-me-state]')];
  const empty = hub.querySelector('.me-filter-empty');
  const deadlines = [...hub.querySelectorAll('[data-me-deadline]')];

  const updateFilter = (selected) => {
    let visible = 0;
    filters.forEach((button) => {
      const active = button.dataset.meFilter === selected;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', String(active));
    });
    cards.forEach((card) => {
      const active = selected === 'all' || card.dataset.meState === selected;
      card.hidden = !active;
      if (active) visible++;
    });
    if (empty) empty.hidden = visible !== 0 || cards.length === 0;
  };

  filters.forEach((button) => {
    button.addEventListener('click', () => updateFilter(button.dataset.meFilter || 'all'));
  });

  const updateDeadlines = () => {
    const now = Date.now();
    deadlines.forEach((label) => {
      const deadline = Date.parse(label.dataset.meDeadline || '');
      if (!Number.isFinite(deadline)) return;
      const remaining = Math.max(0, deadline - now);
      if (remaining <= 0) {
        label.textContent = 'Submissions closed';
        const card = label.closest('.me-event-card');
        const action = card?.querySelector('.me-enter-link');
        if (action && action.textContent.includes('Enter event')) {
          action.innerHTML = 'View event <span aria-hidden="true">↗</span>';
        }
        return;
      }
      const days = Math.floor(remaining / 86400000);
      const hours = Math.floor(remaining / 3600000) % 24;
      const minutes = Math.floor(remaining / 60000) % 60;
      label.textContent = 'Closes in ' + (days ? days + 'd ' : '') + hours + 'h ' + minutes + 'm';
    });
  };

  updateDeadlines();
  setInterval(updateDeadlines, 60000);
})();