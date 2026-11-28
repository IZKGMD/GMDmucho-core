const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const view=fs.readFileSync(path.join(root,'public/admin/index.php'),'utf8');
const css=fs.readFileSync(path.join(root,'public/admin/admin-alignment.css'),'utf8');

test('admin geometry layer is last and only applies to the operator shell',()=>{
  const theme='/admin/admin-light.css?v=2';
  const layout='/admin/admin-alignment.css?v=2';
  assert.equal(view.split(layout).length-1,1,'alignment stylesheet should be loaded once');
  assert.equal(view.split(theme).length-1,2,'login and operator still load light theme');
  assert.ok(view.indexOf(layout)>view.lastIndexOf(theme),'operator alignment follows theme');
  assert.ok(view.indexOf(layout)<view.indexOf('<body class="mc-admin"'),'load before operator body');
});

test('admin geometry covers header, collapsed sidebar, scrollable tables and small screens',()=>{
  for(const rule of [
    'body.mc-admin .head','body.mc-admin .head .global-search',
    'body.mc-admin.sidebar-collapsed .shell>main',
    'body.mc-admin :is(.table,.mc-ops-table-scroll,.mc-database-data .table)',
    '@media(min-width:1340px)','@media(max-width:980px)',
    '@media(max-width:600px)','@media(max-width:420px)',
    '@media(prefers-reduced-motion:reduce)'
  ]){
    assert.ok(css.includes(rule),rule);
  }
  assert.ok(css.includes('grid-row:3!important'),'mobile search follows controls');
  assert.ok(css.includes('overscroll-behavior-x:contain'),'tables should scroll inside content');
  assert.ok(!css.includes('@import'),'do not reintroduce stacked external CSS');
  assert.ok(!css.includes('linear-gradient('),'geometry-only patch must not change palette');
  const open=(css.match(/\{/g)||[]).length;
  const close=(css.match(/\}/g)||[]).length;
  assert.equal(open,close,'balanced CSS blocks');
});
