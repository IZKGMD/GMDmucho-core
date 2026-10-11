const {test}=require('node:test');
const assert=require('node:assert/strict');
const {normalize,matches,adminRoute}=require('../../public/assets/product-ui.js');

test('section and row search handles Russian, case, whitespace and full-width text',()=>{
    assert.equal(normalize('  РЕЙТИНГ   Уровней '),'рейтинг уровней');
    assert.equal(matches('Рейтинг уровней / Content','УРОВНЕЙ рейтинг'),true);
    assert.equal(matches('Rating Studio','ＳＴＵＤＩＯ'),true);
    assert.equal(matches('music queue',''),true);
    assert.equal(matches('music queue','players'),false);
    assert.equal(matches('admin <script>','<script>'),true);
});
test('quick navigation admits only same-origin page routes',()=>{
    const origin='https://example.test';
    assert.equal(adminRoute('/admin/?page=rating',origin),'/admin/?page=rating');
    assert.equal(adminRoute('/admin/?page=plugins',origin),'/admin/?page=plugins');
    for(const href of ['https://evil.test/admin/?page=players','//evil.test/admin/?page=players','javascript:alert(1)','/admin/?logout=1','/admin/?page=players&action=account-delete','/admin/?page=players#action','/admin/?page=../system','/clients/?page=players']){
        assert.equal(adminRoute(href,origin),null,href);
    }
});
