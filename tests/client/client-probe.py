import base64
import hashlib
import importlib.util
from pathlib import Path
import struct
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("client_probe", ROOT / "tools/client/client-probe.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def fixture(payload):
    data = bytearray(0x400)
    data[:2] = b"MZ"
    struct.pack_into("<I", data, 0x3c, 0x80)
    data[0x80:0x84] = b"PE\0\0"
    struct.pack_into("<HHIIIHH", data, 0x84, 0x8664, 1, 1234, 0, 0, 240, 0x22)
    struct.pack_into("<H", data, 0x98, 0x20b)
    struct.pack_into("<I", data, 0xa8, 0x1000)
    struct.pack_into("<8sIIIIIIHHI", data, 0x188, b".text", len(payload), 0x1000,
                     len(payload), 0x400, 0, 0, 0, 0, 0x60000020)
    return bytes(data) + payload


class Probe(unittest.TestCase):
    def test_three_encodings_and_no_private_values(self):
        payload = (b"https://www.boomlings.com/database/getGJLevels21.php\0"
                   + "downloadGJLevel22.php".encode("utf-16le") + b"\0\0"
                   + base64.b64encode(b"https://www.boomlings.com/database/getGJUserInfo20.php")
                   + b"\0password=private-fixture-secret\0")
        data = fixture(payload)
        report = module.probe(data)
        endpoints = {item["path"]: item["encodings"] for item in report["endpoints"]}
        self.assertEqual(endpoints["/getGJLevels21.php"], ["ascii"])
        self.assertEqual(endpoints["/downloadGJLevel22.php"], ["utf16le"])
        self.assertEqual(endpoints["/getGJUserInfo20.php"], ["base64"])
        self.assertEqual(report["text_sha256"], hashlib.sha256(payload).hexdigest())
        self.assertFalse(report["runtime_verified"])
        self.assertNotIn("private-fixture-secret", str(report))
        self.assertEqual(data, fixture(payload))

    def test_unknown_new_endpoint_is_retained(self):
        names = module.probe(fixture(b"getGJNewFeature2209.php\0"))["endpoints"]
        self.assertEqual(names[0]["path"], "/getGJNewFeature2209.php")

    def test_malformed_pe_rejected(self):
        good = fixture(b"getGJLevels21.php\0")
        cases = [b"", b"MZ", good[:90]]
        bad = bytearray(good)
        struct.pack_into("<I", bad, 0x3c, 0xffffffff)
        cases.append(bytes(bad))
        bad = bytearray(good)
        struct.pack_into("<I", bad, 0x188 + 20, 0xffffff00)
        cases.append(bytes(bad))
        for data in cases:
            with self.subTest(size=len(data)), self.assertRaises(ValueError):
                module.probe(data)


if __name__ == "__main__":
    unittest.main()
