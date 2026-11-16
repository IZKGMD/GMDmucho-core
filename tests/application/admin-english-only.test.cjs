const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

test('PHP8 operator and sign-in views use a stable English document language',()=>{
  const php=read('public/admin/index.php');
  assert.ok((php.match(/<html lang="en">/g)||[]).length>=2);
  assert.ok(!php.includes('<html lang="ru">'));
  assert.ok(!php.includes('muchoLangSwitcher'),'remove locale controls rather than hiding them');
  assert.ok(!php.includes('src="/admin/admin-i18n.js'),'do not translate server-rendered English at runtime');
  assert.ok(!/[А-Яа-яЁё]/u.test(php),'no Russian copy should be rendered from the core admin shell');
});

test('first-run ignores legacy language preferences and uses only English copy',()=>{
  const php=read('public/admin/first-run.php');
  const method=php.match(/function muchoAdminFirstRunLanguage\([^]*?\n\}/);
  assert.ok(method,'English-only locale helper must remain available for existing callers');
  assert.match(method[0],/return 'en';/);
  assert.ok(!php.includes('class="language-switcher"'));
  assert.ok(!php.includes("setcookie('mucho_admin_language'"));
  assert.ok(!/[А-Яа-яЁё]/u.test(php));
});

test('navigation labels are English before any JavaScript runs',()=>{
  const nav=read('public/admin/core/navigation.php');
  const pages=read('public/admin/config/pages.php');
  for(const source of [nav,pages])assert.ok(!/[А-Яа-яЁё]/u.test(source));
  const entry=read('public/admin/index.php');
  assert.match(entry,/require __DIR__\.'\/core\/navigation.php';/);
});
