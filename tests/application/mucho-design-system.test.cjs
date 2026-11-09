const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'../..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

test('bilingual product uses its standalone v3 layout, no legacy CSS overlay',()=>{
 for(const p of ['public/muchocore/index.html','public/muchocore/ru/index.html']) {
   assert.match(read(p),/<body class="mc-core-site mc-editorial">/);
   assert.ok(read(p).includes('/assets/mucho-product-v3.css?v=2'));
   assert.ok(!read(p).includes('/assets/mucho-design-system.css?v=2'));
 }
});

test('operator and player workspaces keep shared foundation and dedicated rebuilt surfaces',()=>{
 for(const p of ['public/index.html','public/dashboard/index.php','public/dashboard/clans.php','public/admin/index.php'])
   assert.ok(read(p).includes('/assets/mucho-design-system.css?v=2'),p);
 assert.ok(read('public/admin/index.php').includes('/admin/mucho-console-v3.css?v=2'));
 assert.ok(read('public/install/index.html').includes('/assets/mucho-install-v3.css?v=1'));
 for(const p of ['public/muchocore/about/index.html','public/muchocore/compatibility/index.html']) {
   assert.ok(read(p).includes('/muchocore/discovery.css?v=4'));
   assert.ok(!read(p).includes('/assets/mucho-design-system.css'));
 }
 for(const p of ['public/dashboard/index.php','public/dashboard/clans.php'])
   assert.ok(read(p).includes('/assets/mucho-portal-v3.css?v=2'),p);
});

test('new and legacy surfaces support reduced motion, keyboard focus and independent tokens',()=>{
 for(const p of ['public/assets/mucho-design-system.css','public/assets/mucho-product-v3.css','public/admin/mucho-console-v3.css','public/assets/mucho-portal-v3.css','public/assets/mucho-install-v3.css','public/muchocore/discovery.css']) {
   const css=read(p);
   assert.ok(css.includes('prefers-reduced-motion'),p);
   assert.ok(!css.includes('@import'),p);
 }
 assert.ok(read('public/assets/mucho-product-v3.css').includes(':focus-visible'));
 assert.ok(read('public/assets/mucho-design-system.css').includes('html[data-theme="light"]'));
});

test('hybrid MuchoCore theme uses one logo-matched dark palette',()=>{
 const css=read('public/assets/mucho-product-v3.css');
 for(const token of ['--mc-paper:#101017','--mc-deep:#21192e','--mc-brand:#ba9cee','color-scheme:dark']) assert.ok(css.includes(token),token);
 assert.ok(read('public/admin/mucho-console-v3.css').includes('#ba9cee'));
 assert.ok(read('public/assets/mucho-portal-v3.css').includes('#ba9cee'));
 assert.ok(read('public/muchocore/discovery.css').includes('color-scheme:dark'));
});
