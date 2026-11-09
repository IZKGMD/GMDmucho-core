'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/install/shared/index.html'), 'utf8');
const match = source.match(/<script>\s*([\s\S]*?)\s*<\/script>/i);

test('5V.PL wizard script parses and does not persist secret fields', () => {
  assert.ok(match, 'inline application script missing');
  assert.doesNotThrow(() => new vm.Script(match[1], {filename:'5v-only-installer.js'}));
  assert.match(match[1], /provider:'5v\.pl'/);
  const fields = match[1].match(/const draftFields=\[([^\]]+)\]/);
  assert.ok(fields);
  assert.doesNotMatch(fields[1], /Password|Secret|dbHost|dbUser|admin/);
});

test('only exact HTTPS *.5v.pl roots are accepted', () => {
  const fake = {
    addEventListener() {},
    value: '',
    disabled: false,
    textContent: '',
  };
  const context = vm.createContext({
    document: {getElementById: () => ({...fake})},
    location: {protocol:'https:'},
    localStorage: {getItem:()=>null,setItem(){}},
    URL,
  });
  vm.runInContext(match[1], context, {filename:'5v-only-installer.js'});
  const accepts = input => vm.runInContext('validFiveVUrl(' + JSON.stringify(input) + ')', context);
  for (const address of ['https://gdps.5v.pl', 'https://my-server.5v.pl/',
                         'https://example123.5v.pl']) {
    assert.equal(accepts(address), true, address);
  }
  for (const address of ['http://gdps.5v.pl', 'https://5v.pl',
                         'https://evil5v.pl', 'https://gdps.5v.pl.evil.com',
                         'https://gdps.5v.pl@evil.com', 'https://gdps.5v.pl:8443',
                         'https://gdps.5v.pl/mc', 'https://gdps.5v.pl/?x=1',
                         'https://gdps.5v.pl/#hi', 'https://gdps.beget.com',
                         'https://gdps.infinityfreeapp.com']) {
    assert.equal(accepts(address), false, address);
  }
});
