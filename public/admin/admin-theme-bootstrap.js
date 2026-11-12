/* Apply the saved preference before stylesheet paint to prevent a dark flash. */
(() => {
  let theme = 'dark';
  try {
    const saved = localStorage.getItem('mucho.theme');
    if (saved === 'light' || saved === 'dark') theme = saved;
  } catch {}
  document.documentElement.dataset.theme = theme;
})();
