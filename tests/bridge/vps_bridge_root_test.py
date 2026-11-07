#!/usr/bin/env python3
"""Contract tests for the opt-in disposable VPS root transport."""
from __future__ import annotations

import base64
import importlib.util
import pathlib
import unittest
from unittest import mock

HELPER = pathlib.Path(__file__).resolve().parents[2] / "tools" / "bridge" / "vps-bridge-root-exec.py"
SPEC = importlib.util.spec_from_file_location("muchocore_root_bridge", HELPER)
assert SPEC and SPEC.loader
bridge = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bridge)


def encoded(command: str) -> str:
    return base64.urlsafe_b64encode(command.encode("utf-8")).decode("ascii").rstrip("=")


class RootBridgeTransportTest(unittest.TestCase):
    def test_decodes_bash_with_unicode_and_newline(self):
        command = "printf 'Готово\\n'\\nid -u"
        self.assertEqual(bridge.decode_command(encoded(command)), command)

    def test_rejects_invalid_payload(self):
        for bad in ("", "abc!", "a" * 4097, encoded("id\x00whoami"), encoded("echo hi\r"), encoded("\x1b[31m")):
            with self.subTest(bad=bad[:30]):
                with self.assertRaises(ValueError):
                    bridge.decode_command(bad)

    def test_refuses_unprivileged_execution(self):
        with mock.patch.object(bridge.os, "geteuid", return_value=1000):
            self.assertEqual(bridge.main(["root-exec", encoded("id -u")]), 77)

    def test_refuses_malformed_arguments_as_root(self):
        with mock.patch.object(bridge.os, "geteuid", return_value=0):
            self.assertEqual(bridge.main(["root-exec"]), 64)
            self.assertEqual(bridge.main(["root-exec", "not*base64"]), 64)

    def test_root_exec_uses_bounded_bash_command(self):
        fake_result = mock.Mock(returncode=0)
        with mock.patch.object(bridge.os, "geteuid", return_value=0), mock.patch.object(
            bridge.subprocess, "run", return_value=fake_result
        ) as proc:
            self.assertEqual(bridge.main(["root-exec", encoded("id -u")]), 0)
        args, kwargs = proc.call_args
        self.assertEqual(args[0][-4:], ["--norc", "-c", "id -u"][-4:]) if False else None
        self.assertEqual(args[0][-3:], ["--norc", "-c", "id -u"])
        self.assertEqual(args[0][0], "/usr/bin/timeout")
        self.assertEqual(args[0][-4], "--noprofile")
        self.assertEqual(kwargs["env"]["HOME"], "/root")


if __name__ == "__main__":
    unittest.main()
