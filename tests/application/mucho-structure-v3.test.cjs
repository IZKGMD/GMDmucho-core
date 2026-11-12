const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const read=p=>fs.readFileSync(path.join(__dirname,'../..',p),'utf8');

test('product site is independently rebuilt, not another CSS overlay',()=>{
  for (const p of ['public/muchocore/index.html','public/muchocore/ru/index.html']) {
    const html=read(p);
    assert.match(html,/\/assets\/mucho-product-v3\.css\?v=2/);
    assert.match(html,/\/assets\/mucho-product-v3\.js\?v=2/);
    assert.doesNotMatch(html,/muchocore-landing\.css|mucho-design-system\.css/);
    for (const id of ['mc-demo-panel-0','mc-demo-panel-1','mc-demo-panel-2','mc-demo-tab-0','mc-demo-tab-1','mc-demo-tab-2'])
      assert.ok(html.includes(id),p+':'+id);
    assert.ok(html.includes('mc-feature-row'));
    assert.ok(html.includes('mc-flow-steps'));
  }
});

test('new work surfaces contain genuine structural markup and separate styles',()=>{
  const admin=read('public/admin/index.php');
  const overview=read('public/admin/pages/dashboard.php');
  const portal=read('public/dashboard/index.php');
  const clans=read('public/dashboard/clans.php');
  assert.ok(admin.includes('mc-instance'));
  assert.ok(admin.includes('/admin/mucho-console-v3.css?v=7'));
  for (const marker of ['mc-ops-attention','mc-ops-summary','mc-ops-table','mc-ops-actions','canAdminPage']) assert.ok(overview.includes(marker),marker);
  for (const marker of ['mc-community-home','mc-community-search','mc-community-statistics','mc-community-spotlight']) assert.ok(portal.includes(marker),marker);
  for (const p of [portal,clans]) assert.ok(p.includes('/assets/mucho-portal-v3.css?v=2'));
  assert.ok(clans.includes('mc-clan-workspace'));
  assert.ok(read('public/install/index.html').includes('mc-install-method-list'));
  assert.ok(read('public/install/index.html').includes('/deploy/'));
});

test('existing foundational styles, inputs, server data and reduced-motion remain available',()=>{
  const shared=read('public/assets/mucho-design-system.css');
  assert.ok(shared.includes('prefers-reduced-motion'));
  assert.ok(shared.includes(':focus-visible'));
  const landing=read('public/assets/mucho-product-v3.css');
  assert.ok(landing.includes(':focus-visible'));
  assert.ok(landing.includes('prefers-reduced-motion'));
  assert.ok(!landing.includes('@import'));
  assert.doesNotMatch(landing,/radial-gradient|linear-gradient|backdrop-filter:blur/);
  const portal=read('public/dashboard/index.php');
  for (const field of ['csrf','name="username"','name="password"','name="music_file"','pdFormatNumber($dashboardStats']) assert.ok(portal.includes(field),field);
  const admin=read('public/admin/pages/dashboard.php');
  assert.ok(admin.includes('auditDetails')&&admin.includes('muchoLiveAudit'));
});
