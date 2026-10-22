'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('Android patcher refuses obviously prepatched APK name before uploading', async () => {
    const src = fs.readFileSync(
        path.resolve(__dirname, '../../public/admin/android-client-patcher-module.php'),
        'utf8'
    );
    const from = src.indexOf('<script>');
    const to = src.indexOf('</script>', from);
    assert.ok(from >= 0 && to > from);
    const code = src.slice(from + 8, to).replace(
        '<?=json_encode(csrf(), JSON_UNESCAPED_SLASHES)?>',
        '"test-csrf-token"'
    );

    const callbacks = new Map();
    const nodes = new Map();
    const document = {
        getElementById(id) {
            if (!nodes.has(id)) nodes.set(id, {
                style: {}, textContent: '', innerHTML: '',
                value: 'https://gdps.example',
                files: [], classList: { add() {}, remove() {} },
                addEventListener(event, fn) { callbacks.set(id + ':' + event, fn); },
            });
            return nodes.get(id);
        },
    };
    let uploaded = false;
    new vm.Script(code).runInNewContext({
        document, FormData, setTimeout, console,
        fetch: async () => {
            uploaded = true;
            throw new Error('Repatched APK must not be sent');
        },
    });
    const apk = new Blob([Buffer.alloc(4096)]);
    apk.name = 'GeometryDash-MuchoCore (1).apk';
    document.getElementById('mcAndroidPatcherFile').files = [apk];
    callbacks.get('mcAndroidPatcherFile:change')();
    assert.match(document.getElementById('mcAndroidPatcherStatus').textContent,
        /already patched/i);

    await callbacks.get('mcAndroidPatcherForm:submit')({
        preventDefault() {},
    });
    assert.equal(uploaded, false, 'Previously patched APK must not be uploaded');
    assert.match(document.getElementById('mcAndroidPatcherStatus').textContent,
        /ORIGINAL unmodified APK/i);
});
