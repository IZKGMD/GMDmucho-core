'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const MiB = 1048576;

test('Android APK upload: 3 concurrent streams, failed ACK recovery, every part once', async () => {
    const php = fs.readFileSync(
        path.join(root, 'public/admin/android-client-patcher-module.php'), 'utf8'
    );
    const start = php.indexOf('<script>');
    const end = php.indexOf('</script>', start);
    assert.ok(start >= 0 && end > start);
    const js = php.slice(start + '<script>'.length, end)
        .replace('<?=json_encode(csrf(), JSON_UNESCAPED_SLASHES)?>',
            '"test-csrf-token"');

    const callbacks = Object.create(null);
    const nodes = Object.create(null);
    const document = {
        getElementById(id) {
            if (!nodes[id]) {
                nodes[id] = {
                    style: {}, files: [], value: 'https://gdps.example.com',
                    textContent: '', innerHTML: '',
                    checked: true,
                    classList: { add() {}, remove() {} },
                    addEventListener(event, listener) {
                        callbacks[id + ':' + event] = listener;
                    },
                };
            }
            return nodes[id];
        },
    };
    const fileBytes = 17 * MiB + 17;
    const partSize = 4 * MiB;
    const partCount = Math.ceil(fileBytes / partSize);
    const committed = new Map();
    let sentBytes = 0;
    let inflight = 0;
    let maximumInflight = 0;
    let lostAck = false;
    let checks = 0;
    let finish = false;
    const response = data => ({ ok: true, json: async () => data });

    async function fetch(_url, options) {
        const data = options.body;
        const action = data.get('action');
        if (action === 'android-patcher-start') {
            assert.equal(data.get('supports_parallel'), '1');
            assert.equal(data.get('preferred_part_bytes'), String(2 * MiB));
            return response({
                ok: true, upload_id: 'a'.repeat(32), next_offset: 0,
                upload_mode: 'parallel-v1', chunk_size: partSize, max_parallel: 3,
            });
        }
        if (action === 'android-patcher-part') {
            assert.match(nodes.mcAndroidPatcherStatus.textContent, /Uploading APK/);
            assert.doesNotMatch(nodes.mcAndroidPatcherStatus.textContent, /Creating APK upload session/);
            const index = Number(data.get('part_index'));
            const chunk = data.get('chunk');
            assert.ok(index >= 0 && index < partCount);
            assert.equal(chunk.size, Math.min(partSize, fileBytes - index * partSize));
            inflight++;
            maximumInflight = Math.max(maximumInflight, inflight);
            try {
                // Force requests to overlap and arrive out of order.
                await new Promise(resolve => setTimeout(resolve, index === 0 ? 10 : 1));
                assert.ok(!committed.has(index), 'An acknowledged range was resent');
                committed.set(index, chunk.size);
                sentBytes += chunk.size;
                if (index === 1 && !lostAck) {
                    lostAck = true;
                    throw new Error('simulated lost acknowledgement after write');
                }
                return response({ ok: true, part_index: index, new: true });
            } finally {
                inflight--;
            }
        }
        if (action === 'android-patcher-status') {
            checks++;
            return response({
                ok: true, upload_mode: 'parallel-v1',
                completed_parts: [...committed.keys()].sort((a, b) => a - b),
            });
        }
        if (action === 'android-patcher-finish') {
            assert.equal(sentBytes, fileBytes);
            assert.equal(committed.size, partCount);
            finish = true;
            return response({
                ok: true, output_size: fileBytes, output_sha256: 'a'.repeat(64),
                source_build: 'Android GD', package_id: 'com.example.gd',
                patched_entries: 1, replacement_count: 113,
                download_url: '/admin/?android_download=test',
            });
        }
        throw new Error('Unexpected backend action: ' + action);
    }

    new vm.Script(js, {
        filename: 'public/admin/android-client-patcher-module.php',
    }).runInNewContext({
        document, fetch, FormData, setTimeout, console,
        navigator: { userAgent: 'Mozilla/5.0 (Linux; Android 17) Chrome/155 Mobile' },
    });

    const file = new Blob([Buffer.alloc(fileBytes)]);
    file.name = 'GeometryDash.apk';
    nodes.mcAndroidPatcherFile.files = [file];
    callbacks['mcAndroidPatcherFile:change']();
    await callbacks['mcAndroidPatcherForm:submit']({ preventDefault() {} });

    assert.equal(finish, true, 'APK patch finalization was requested');
    assert.equal(committed.size, partCount, 'Every part was committed');
    assert.equal(sentBytes, fileBytes, 'No bytes missing or duplicated');
    assert.ok(maximumInflight >= 2, 'Uploads were not concurrent');
    assert.ok(maximumInflight <= 3, 'Exceeded safe concurrency limit');
    assert.ok(checks >= 1, 'A lost acknowledgement was not reconciled');
    assert.equal(nodes.mcAndroidPatcherProgress.style.width, '100%');
    assert.match(nodes.mcAndroidPatcherStatus.textContent, /verified/i);
});
