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
 assert.ok(read('public/admin/index.php').includes('/admin/mucho-console-v3.css?v=7'));
 assert.ok(read('public/admin/index.php').includes('/admin/admin-unified.css?v=1'));

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

test('MuchoCore product keeps its logo palette while admin uses neutral graphite surfaces',()=>{
 const css=read('public/assets/mucho-product-v3.css');
 for(const token of ['--mc-paper:#101017','--mc-deep:#21192e','--mc-brand:#ba9cee','color-scheme:dark']) assert.ok(css.includes(token),token);
 const admin=read('public/admin/mucho-console-v3.css');
 for(const token of ['--mc-bg:#101218','--mc-surface:#191c26','--mc-accent:#b4a2e8'])
   assert.ok(admin.includes(token),token);
 assert.ok(read('public/assets/mucho-portal-v3.css').includes('#ba9cee'));
 assert.ok(read('public/muchocore/discovery.css').includes('color-scheme:dark'));
});

test('all key admin modules use the same accessible controls and compact surface layer',()=>{
 const unified=read('public/admin/admin-unified.css');
 for(const selector of [
  '.mc-work-filter','.admin-record>summary','.admin-stats-grid',
  '.rating-layout','.rating-savebar','.mc-plugins-overview',
  '.mc-news-workspace','.mc-settings-preview','.mc-role-permission',
  '.mc-contentpack-slots','.admin-pagination','.mc-table-tools',
  'body.mc-admin.mc-compact','@media(max-width:650px)','prefers-reduced-motion'
 ]) assert.ok(unified.includes(selector),selector);
 const modules=[
  'public/admin/pages/players.php',
  'public/admin/pages/levels-moderation.php',
  'public/admin/pages/songs.php'
 ];
 for(const p of modules){
  const page=read(p);
  assert.ok(page.includes('mc-work-filter'),p);
  assert.ok(page.includes('mc-work-toolbar'),p);
  assert.ok(page.includes('adminListPager('),p);
 }
 assert.ok(read('public/admin/pages/news.php').includes('mc-news-workspace'));
 assert.ok(!read('public/admin/pages/news.php').includes('<style>'));
 assert.ok(read('public/admin/pages/plugins.php').includes('mc-plugins-overview'));
 assert.ok(read('public/admin/pages/contentpacks.php').includes('mc-contentpack-slots'));
 assert.ok(read('public/admin/index.php').includes('mc-role-permission'));
 assert.ok(read('public/admin/index.php').includes('mc-page-description'));
 assert.ok(read('public/admin/pages/songs.php').includes('data-confirm='));
});
