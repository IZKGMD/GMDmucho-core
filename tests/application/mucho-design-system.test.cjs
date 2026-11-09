const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root,p),'utf8');
const style = '/assets/mucho-design-system.css?v=1';

test('unified design layer is loaded in all PHP8/public web surfaces',()=>{
  for (const path of ['public/index.html','public/muchocore/index.html','public/muchocore/ru/index.html','public/muchocore/about/index.html','public/muchocore/compatibility/index.html','public/dashboard/index.php','public/dashboard/clans.php','public/install/index.html','public/admin/index.php']) {
    assert.ok(read(path).includes(style), path);
  }
});
test('MuchoCore product pages have a scoped design target',()=>{
  for (const path of ['public/muchocore/index.html','public/muchocore/ru/index.html'])
    assert.match(read(path),/<body class="mc-core-site">/);
});
test('discovery, clans, and installer have scoped design targets',()=>{
  for (const [path,target] of [['public/muchocore/about/index.html','mc-discovery-page'],['public/muchocore/compatibility/index.html','mc-discovery-page'],['public/dashboard/clans.php','mc-clans-page'],['public/install/index.html','mc-install-page']])
    assert.ok(read(path).includes(target), path);
});
test('design layer has no external dependencies and accommodates accessibility',()=>{
  const css=read('public/assets/mucho-design-system.css');
  assert.ok(css.includes('prefers-reduced-motion'));
  assert.ok(css.includes(':focus-visible'));
  assert.ok(css.includes('html[data-theme="light"]'));
  assert.ok(!css.includes('@import'));
  for (const surface of ['mc-home','mc-core-site','dashboard-page','mc-admin'])
    assert.ok(css.includes(surface),surface);
});
