import base64
import io
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest.mock import patch
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/moderation'))
from service import Classifier, Server, decision


def png():
    data = io.BytesIO()
    Image.new('RGB', (64, 64), 'white').save(data, 'PNG')
    return base64.b64encode(data.getvalue()).decode()


def call(path, data):
    body = json.dumps(data).encode()
    with socket.socket(socket.AF_UNIX) as client:
        client.connect(str(path))
        client.sendall(b'POST /classify HTTP/1.0\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: ' + str(len(body)).encode() + b'\r\n\r\n' + body)
        result = b''
        while chunk := client.recv(8192):
            result += chunk
    return json.loads(result.split(b'\r\n\r\n', 1)[1])


class FixedClassifier(Classifier):
    def __init__(self, score):
        self.rules = []
        self.value = score

    def score(self, _):
        return self.value


class LocalTests(unittest.TestCase):
    def test_policy_and_uncertainty(self):
        self.assertEqual(decision(.10)['state'], 'approved')
        self.assertEqual(decision(.40)['state'], 'pending')
        self.assertEqual(decision(.95)['state'], 'rejected')
        for score in (float('nan'), float('inf'), -1, 2, True):
            self.assertEqual(decision(score)['state'], 'pending')

    def test_image_input_and_views(self):
        c = FixedClassifier(.1)
        self.assertEqual(c.classify(png(), 'caption')['state'], 'approved')
        self.assertEqual(FixedClassifier(.9).classify(png(), '')['state'], 'rejected')
        for data in ('https://example.com/image.png', '!!!!', base64.b64encode(b'<svg/>').decode()):
            with self.assertRaises((ValueError, OSError)):
                c.classify(data, '')
        self.assertEqual(len(list(c.views(Image.new('RGB', (2048, 2048))))), 6)
        self.assertEqual(len(list(c.views(Image.new('RGB', (2048, 800))))), 4)

    def test_ipc_with_network_disabled(self):
        original = socket.socket
        def only_unix(family=socket.AF_UNIX, *args, **kwargs):
            if family != socket.AF_UNIX:
                raise AssertionError('Network socket attempted by offline worker')
            return original(family, *args, **kwargs)
        if os.environ.get('MUCHO_SKIP_IPC_TEST')=='1':
            self.skipTest('This executor denies AF_UNIX socket creation; CI runs the IPC check')
        with tempfile.TemporaryDirectory() as tmp, patch('socket.socket', only_unix):
            path = Path(tmp) / 'worker.sock'
            with Server(path, FixedClassifier(.1)) as server:
                thread = threading.Thread(target=server.serve_forever, daemon=True)
                thread.start()
                try:
                    result = call(path, {'imageData': png(), 'text': 'hello'})
                    self.assertTrue(result['local'])
                    self.assertEqual(result['state'], 'approved')
                    self.assertEqual(call(path, {'imageData': png(), 'text': 'hello', 'url': 'https://example.com'})['state'], 'pending')
                    self.assertEqual(call(path, {'imageData': '!!!', 'text': ''})['state'], 'pending')
                    # PHP adapter exercises libcurl's Unix socket path too.
                    binary = os.environ.get('MUCHO_TEST_PHP', 'php')
                    args = json.loads(os.environ.get('MUCHO_TEST_PHP_ARGS', '[]'))
                    php = '$root=$argv[1];spl_autoload_register(static function($c)use($root){if(str_starts_with($c,"MuchoCore\\\\"))require $root."/src/".str_replace("\\\\","/",substr($c,10)).".php";});$r=(new MuchoCore\\Media\\LocalImageModerator($argv[2]))->classify("","hello");echo json_encode($r);'
                    reply = subprocess.check_output([binary, *args, '-r', php, str(ROOT), str(path)], text=True)
                    self.assertEqual(json.loads(reply)['state'], 'approved')
                finally:
                    server.shutdown()
                    thread.join(timeout=2)

    @unittest.skipUnless(os.environ.get('MUCHO_TEST_MODEL'), 'Pinned model not requested')
    def test_actual_pinned_cpu_model_offline(self):
        with patch('socket.create_connection', side_effect=AssertionError('Network attempted')):
            c = Classifier(Path(os.environ['MUCHO_TEST_MODEL']))
            score = c.score(Image.new('RGB', (224, 224), 'white'))
            self.assertLess(score, .15)
            self.assertEqual(c.classify(png(), '')['state'], 'approved')
        with tempfile.TemporaryDirectory() as tmp:
            changed = Path(tmp) / 'invalid.onnx'
            changed.write_bytes(b'bad weights')
            with self.assertRaises(ValueError):
                Classifier(changed)


if __name__ == '__main__':
    unittest.main()
