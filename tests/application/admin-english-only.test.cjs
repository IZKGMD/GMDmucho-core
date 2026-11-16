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

test('every PHP 8 and native PHP 7 administrator template has English-only source copy',()=>{
  const collect=(folder)=>fs.readdirSync(path.join(root,folder),{withFileTypes:true}).flatMap(entry=>{
    const location=path.join(folder,entry.name);
    return entry.isDirectory()?collect(location):/\.(?:php|js)$/i.test(entry.name)?[location]:[];
  });
  const files=[
    ...collect('public/admin'),
    ...collect('compat/php7/admin-ui'),
    'compat/php7/admin-view.php',
    'compat/php7/admin.php',
    'public/assets/php7-admin-product.js',
    'public/assets/php7-admin-dashboard.js',
  ];
  for(const file of files){
    assert.doesNotMatch(read(file),/[А-Яа-яЁё]/u,`Non-English administrator UI source: ${file}`);
  }
});

test('legacy language cookies and bookmarks cannot re-enable Russian administration',()=>{
  const shim=read('public/admin/admin-i18n.js');
  assert.match(shim,/document\.documentElement\.lang\s*=\s*'en'/);
  assert.doesNotMatch(shim,/mucho_admin_language|localStorage|document\.cookie|setLang\(/);
  assert.doesNotMatch(read('public/admin/admin-simple.js'),/document\.documentElement\.lang\s*===?\s*'ru'/);
  assert.doesNotMatch(read('public/admin/product-dashboard.js'),/document\.documentElement\.lang\s*===?\s*'ru'/);
  const native=read('compat/php7/admin-view.php');
  assert.match(native,/<html lang="en">/);
  assert.match(native,/data-admin-language="en"/);
});
