(() => {
  // Preview-only parameter; does not persist anything or change the default UI.
  const valid = ['amethyst','titanium','midnight'];
  const name = new URLSearchParams(location.search).get('palette');
  if (valid.includes(name)) document.documentElement.setAttribute('data-mc-palette',name);
})();