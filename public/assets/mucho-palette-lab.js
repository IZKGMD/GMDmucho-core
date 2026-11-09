(() => {
  // Temporary palette preview: nothing is stored, no permanent default is changed.
  const valid = ['amethyst', 'titanium', 'midnight'];
  const params = new URLSearchParams(location.search);
  const name = params.get('palette');
  if (valid.includes(name)) document.documentElement.dataset.mcPalette = name;
  const backgrounds = {
  "snow": {
    "background": "#FFFFFF",
    "mode": "light"
  },
  "porcelain": {
    "background": "#F9FAFC",
    "mode": "light"
  },
  "paper": {
    "background": "#F5F5F7",
    "mode": "light"
  },
  "frost": {
    "background": "#EFF5FB",
    "mode": "light"
  },
  "ice": {
    "background": "#E7F0FA",
    "mode": "light"
  },
  "cloud": {
    "background": "#E8EDF4",
    "mode": "light"
  },
  "silver": {
    "background": "#E1E5ED",
    "mode": "light"
  },
  "titanium": {
    "background": "#ECEFF3",
    "mode": "light"
  },
  "steel": {
    "background": "#D9E0E9",
    "mode": "light"
  },
  "bluegray": {
    "background": "#D2DBE7",
    "mode": "light"
  },
  "lavender": {
    "background": "#F3EFFA",
    "mode": "light"
  },
  "lilac": {
    "background": "#EAE4F5",
    "mode": "light"
  },
  "orchid": {
    "background": "#E3DCEF",
    "mode": "light"
  },
  "violetmist": {
    "background": "#DCD7EB",
    "mode": "light"
  },
  "stone": {
    "background": "#F0EFEC",
    "mode": "light"
  },
  "pearl": {
    "background": "#F8F5FA",
    "mode": "light"
  },
  "night": {
    "background": "#171823",
    "mode": "dark"
  },
  "graphite": {
    "background": "#262833",
    "mode": "dark"
  },
  "charcoal": {
    "background": "#1F2027",
    "mode": "dark"
  },
  "deepviolet": {
    "background": "#21192E",
    "mode": "dark"
  },
  "navy": {
    "background": "#151F30",
    "mode": "dark"
  },
  "midnight": {
    "background": "#14131C",
    "mode": "dark"
  },
  "ink": {
    "background": "#101017",
    "mode": "dark"
  },
  "smoky": {
    "background": "#2B2734",
    "mode": "dark"
  }
};
  const bgName = params.get('background');
  const config = backgrounds[bgName];
  if (!config) return;
  const root = document.documentElement;
  root.dataset.mcPalette = config.mode === 'dark' ? 'midnight' : 'titanium';
  root.dataset.mcBackground = bgName;
  const asRgb = hex => [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16));
  const blend = (base, target, proportion) => {
    const a = asRgb(base), b = asRgb(target);
    return '#' + a.map((v, i) => Math.round(v * (1 - proportion) + b[i] * proportion).toString(16).padStart(2, '0')).join('');
  };
  const dark = config.mode === 'dark';
  const set = (key, val) => root.style.setProperty(key, val);
  set('--mc-paper', config.background);
  set('--mc-paper-hi', blend(config.background, dark ? '#B9A2E2' : '#FFFFFF', dark ? 0.08 : 0.42));
  set('--mc-alt', blend(config.background, dark ? '#9A83BB' : '#586177', dark ? 0.05 : 0.08));
  set('--mc-footer', blend(config.background, dark ? '#9A83BB' : '#77809C', dark ? 0.035 : 0.07));
  set('--mc-muted-panel', blend(config.background, dark ? '#9A83BB' : '#77809C', dark ? 0.11 : 0.11));
  set('--mc-rule', blend(config.background, dark ? '#B0A7C6' : '#75778C', dark ? 0.25 : 0.21));
})();