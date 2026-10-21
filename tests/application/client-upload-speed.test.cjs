'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const MiB = 1024 * 1024;

for (const variant of [
    {
        file: 'public/admin/client-patcher-module.php',
        form: 'mcClientPatcherForm',
        input: 'mcPatcherFile',
        status: 'mcPatcherStatus',
        progress: 'mcPatcherProgress',
        server: 'mcPatcherServer',
        start: 'client-patcher-start',
        chunk: 'client-patcher-chunk',
        check: 'client-patcher-status',
        finish: 'client-patcher-finish',
        filename: 'GeometryDash.exe',
    },
    {
        file: 'public/admin/android-client-patcher-module.php',
        form: 'mcAndroidPatcherForm',
        input: 'mcAndroidPatcherFile',
        status: 'mcAndroidPatcherStatus',
        progress: 'mcAndroidPatcherProgress',
        server: 'mcAndroidPatcherServer',
        start: 'android-patcher-start',
        chunk: 'android-patcher-chunk',
        check: 'android-patcher-status',
        finish: 'android-patcher-finish',
        filename: 'GeometryDash.apk',
    },
]) {
    test(variant.file + ': 8 MiB negotiation and reconnect without retransmitting', async () => {
        const php = fs.readFileSync(path.join(root, variant.file), 'utf8');
        const scriptStart = php.indexOf('<script>');
        const scriptEnd = php.indexOf('</script>', scriptStart);
        assert.ok(scriptStart !== -1 && scriptEnd > scriptStart, 'uploader script exists');
        const js = php.slice(scriptStart + '<script>'.length, scriptEnd).replace(
            '<?=json_encode(csrf(), JSON_UNESCAPED_SLASHES)?>',
            '"test-csrf-token"'
        );
        assert.ok(!js.includes('<?'), 'server bindings were replaced');
        const callbacks = Object.create(null);
        const nodes = Object.create(null);
        const document = {
            getElementById(id) {
                if (!nodes[id]) {
                    nodes[id] = {
                        style: {},
                        files: [],
                        value: 'https://muchogdps.space',
                        textContent: '',
                        innerHTML: '',
                        classList: { add() {}, remove() {} },
                        addEventListener(event, listener) {
                            callbacks[id + ':' + event] = listener;
                        },
                    };
                }
                return nodes[id];
            },
        };

        const uploadBytes = 17 * MiB;
        let serverOffset = 0;
        let lostFirstAck = false;
        let statusCalls = 0;
        let requestedFinish = false;
        const chunks = [];
        const response = data => ({
            ok: true,
            json: async () => data,
        });
        async function fetch(_url, options) {
            const data = options.body;
            const action = data.get('action');
            if (action === variant.start) {
                assert.equal(Number(data.get('total_size')), uploadBytes);
                return response({
                    ok: true,
                    upload_id: 'a'.repeat(32),
                    next_offset: 0,
                    chunk_size: 8 * MiB,
                });
            }
            if (action === variant.chunk) {
                const offset = Number(data.get('offset'));
                const binary = data.get('chunk');
                assert.equal(offset, serverOffset, 'no duplicated write after lost ACK');
                assert.ok(binary.size <= 8 * MiB, 'bounded chunk');
                assert.ok(binary.size >= 1, 'nonempty chunk');
                serverOffset += binary.size;
                chunks.push(binary.size);
                if (!lostFirstAck) {
                    lostFirstAck = true;
                    throw new Error('simulated network disconnect AFTER server wrote bytes');
                }
                return response({ ok: true, next_offset: serverOffset });
            }
            if (action === variant.check) {
                statusCalls++;
                return response({ ok: true, next_offset: serverOffset });
            }
            if (action === variant.finish) {
                requestedFinish = true;
                assert.equal(serverOffset, uploadBytes);
                return response({
                    ok: true,
                    replacement_count: 2,
                    patched_entries: 1,
                    output_size: uploadBytes,
                    output_sha256: '0123456789abcdef',
                    download_url: '/admin/?test-download',
                });
            }
            throw new Error('unexpected action: ' + action);
        }
        new vm.Script(js, { filename: variant.file }).runInNewContext({
            document,
            fetch,
            FormData,
            setTimeout,
            console,
        });
        const file = new Blob([Buffer.alloc(uploadBytes)]);
        file.name = variant.filename;
        nodes[variant.input].files = [file];
        callbacks[variant.input + ':change']();
        await callbacks[variant.form + ':submit']({ preventDefault() {} });

        assert.ok(requestedFinish, 'finalization called');
        assert.equal(serverOffset, uploadBytes, 'all bytes uploaded once');
        assert.ok(statusCalls >= 1, 'lost ACK was reconciled with server');
        assert.ok(chunks.some(size => size > 512 * 1024), 'uses larger chunks than legacy upload');
        assert.ok(chunks.length <= 7, 'far fewer requests than legacy 512 KiB uploader');
        assert.equal(nodes[variant.progress].style.width, '100%');
        assert.match(nodes[variant.status].textContent, /complete|verified/i);
    });
}
