const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const read = (p) => fs.readFileSync(path.join(__dirname,'../..',p),'utf8');
const css = read('public/assets/mucho-design-system.css');

test('the Studio UI is one dependency-free stylesheet across product and admin surfaces', () => {
  assert.ok(css.includes('html[data-theme="light"]'));
  assert.ok(css.includes(':focus-visible'));
  assert.ok(css.includes('prefers-reduced-motion'));
  assert.ok(!css.includes('@import'));
  for (const scope of ['mc-home','mc-core-site','dashboard-page','mc-clans-page','mc-admin','mc-install-page','mc-discovery-page']) {
    assert.ok(css.includes(scope),scope);
  }
});

test('design avoids generic gradient/glass decoration and motion', () => {
  assert.doesNotMatch(css,/radial-gradient\s*\(|linear-gradient\s*\(|backdrop-filter\s*:\s*blur\s*\(/);
  assert.doesNotMatch(css,/@keyframes\s+|drop-shadow\s*\(/);
  assert.match(css,/\.mc-platform\s*\{/);
  assert.match(css,/\.admin-kpi\s*\{/);
  assert.match(css,/\.dashboard-stat\s*\{/);
  assert.match(css,/\.system-panel\s*\{/);
});

test('all operational pages keep loading the same stylesheet last', () => {
  for (const p of ['public/index.html','public/muchocore/index.html','public/muchocore/ru/index.html','public/admin/index.php','public/dashboard/index.php','public/dashboard/clans.php','public/install/index.html','public/muchocore/about/index.html','public/muchocore/compatibility/index.html']) {
    assert.ok(read(p).includes('/assets/mucho-design-system.css?v=1'),p);
  }
});
