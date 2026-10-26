"""PE section bounds, native aliases and coverage-gap reporting."""
import importlib.util
from pathlib import Path
import struct
import unittest


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "native_inventory", ROOT / "tools/client/native-exe-inventory.py")
inventory = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inventory)


def fixture(strings):
    image = bytearray(0x400)
    image[:2] = b"MZ"
    struct.pack_into("<I", image, 60, 0x80)
    image[0x80:0x84] = b"PE\x00\x00"
    struct.pack_into("<HH", image, 0x84, 0x8664, 1)
    struct.pack_into("<H", image, 0x94, 0xf0)
    image[0x188:0x190] = b".rdata\x00\x00"
    payload = b"\x00".join(s.encode() for s in strings) + b"\x00"
    struct.pack_into("<II", image, 0x198, len(payload), len(image))
    return image + payload


class NativeInventoryTest(unittest.TestCase):
    def test_real_aliases_special_handlers_and_missing_operations(self):
        image = fixture([
            "https://www.boomlings.com/database/accounts/loginGJAccount22.php",
            "%s/database/accounts/backupGJAccountNew.php",
            "https://www.boomlings.com/databas/checkIfServerOnline.php",
            "https://www.boomlings.com/database/accounts/lostusername.php",
            "https://www.boomlings.com/database/accounts/accountManagement.php",
            "https://www.boomlings.com/database/unknownFutureOperation.php",
            "https://www.boomlings.com/FAQ",  # External webpage, not an API.
        ])
        report = inventory.inspect(image)
        self.assertEqual(report["direct_urls"], 5)
        self.assertEqual(report["url_templates"], 1)
        self.assertEqual(report["unimplemented_paths"], ["/unknownfutureoperation"])
        rows = report["endpoints"]
        self.assertEqual(rows[0]["canonical_path"], "/logingjaccount")
        self.assertEqual(rows[1]["canonical_path"], "/backupgjaccount")
        self.assertTrue(rows[2]["path_repair_required"])
        self.assertEqual(rows[2]["service"], "router-health")
        self.assertEqual(rows[3]["service"], "shared-recovery")
        self.assertEqual(rows[4]["service"], "application")
        self.assertFalse(report["native_client_certified"])

    def test_urls_outside_rdata_cannot_claim_coverage(self):
        image = fixture(["https://www.boomlings.com/database/getGJLevels21.php"])
        image += b"https://www.boomlings.com/database/notAnRdataEndpoint.php\x00"
        self.assertEqual(len(inventory.inspect(image)["endpoints"]), 1)

    def test_invalid_section_bounds_fail_closed(self):
        image = fixture(["https://www.boomlings.com/database/getGJLevels21.php"])
        struct.pack_into("<I", image, 0x198, 0xffffffff)
        with self.assertRaises(ValueError):
            inventory.inspect(image)

    def test_invalid_pe_fails_closed(self):
        for image in [b"", b"MZ", bytes(200), b"MZ" + bytes(198)]:
            with self.assertRaises(ValueError):
                inventory.inspect(image)


if __name__ == "__main__":
    unittest.main()
