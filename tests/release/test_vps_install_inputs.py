"""Exercise installer input handling without root, Docker or network calls."""
from pathlib import Path
import os
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SOURCE = (ROOT / 'install.sh').read_text()


def function(name):
    return re.search(r'^' + name + r'\(\) \{\n.*?^\}', SOURCE, re.M | re.S).group(0)


class VpsInputTests(unittest.TestCase):
    def shell(self, script, *args):
        return subprocess.run(['bash', '-eu', '-c', script, 'test', *args], capture_output=True, text=True)

    def test_valid_hostnames_and_pasted_urls(self):
        script = function('normalize_domain_input') + '\n' + function('valid_domain') + '\nvalue=$(normalize_domain_input "$1"); valid_domain "$value"; printf "%s" "$value"'
        for value, expected in [('gdps.example.com', 'gdps.example.com'),
                                ('https://gdps.example.com/admin/', 'gdps.example.com'),
                                ('  gdps.example.com  ', 'gdps.example.com'),
                                ('http://test-server.example.com/', 'test-server.example.com')]:
            result = self.shell(script, value)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout, expected)

    def test_invalid_dns_labels_are_rejected_before_deploy(self):
        script = function('valid_domain') + '\nvalid_domain "$1"'
        for value in ['localhost', '127.0.0.1', '-bad.example.com', 'bad-.example.com',
                      'bad..example.com', '.example.com', 'test.example.com.',
                      'user@example.com', 'example.com:443', 'a' * 64 + '.example.com']:
            self.assertNotEqual(self.shell(script, value).returncode, 0, value)

    def test_saved_brand_round_trips_quotes_slashes_and_dollars(self):
        with tempfile.TemporaryDirectory() as folder:
            for name in ['School GDPS', 'Мой GDPS', 'A "quoted" \\ $name', "Player's GDPS"]:
                script = function('dotenv_escape') + '\n' + function('read_saved_server_name') + '\nINSTALL_DIR=$1; printf \'MUCHO_SERVER_NAME="%s"\\n\' "$(dotenv_escape "$2")" > "$INSTALL_DIR/.env"; read_saved_server_name'
                result = self.shell(script, folder, name)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout, name)

    def test_saved_brand_cannot_execute_shell_commands(self):
        with tempfile.TemporaryDirectory() as folder:
            marker = Path(folder) / 'executed'
            name = '$(touch ' + str(marker) + ')'
            (Path(folder) / '.env').write_text('MUCHO_SERVER_NAME="' + name + '"\n')
            result = self.shell(function('read_saved_server_name') + '\nINSTALL_DIR=$1; read_saved_server_name', folder)
            self.assertEqual(result.stdout, name)
            self.assertFalse(marker.exists())

    def test_non_git_installation_is_never_deleted(self):
        # Exercise the real fresh-clone branch with an existing private config.
        start = SOURCE.index('  # A missing .git never authorizes')
        end = SOURCE.index('\nfi\n', start)
        script = 'fail() { printf "%s" "$*" >&2; exit 1; }; INSTALL_DIR=$1;\n' + SOURCE[start:end]
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / '.env'
            path.write_text('existing private settings')
            result = self.shell(script, folder)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Keep it intact', result.stderr)
            self.assertEqual(path.read_text(), 'existing private settings')

    def test_native_news_survives_reinstall_unless_explicitly_overridden(self):
        script = function('resolve_native_news_setting') + '''
fail() { printf "%s" "$*" >&2; exit 1; }
INSTALL_DIR=$1
NATIVE_NEWS=$2
resolve_native_news_setting
printf '%s' "$NATIVE_NEWS"
'''
        with tempfile.TemporaryDirectory() as folder:
            self.assertEqual(self.shell(script, folder, '').stdout, '0')
            self.assertEqual(self.shell(script, folder, '1').stdout, '1')
            for saved in ('MUCHO_NATIVE_NEWS=1', "export MUCHO_NATIVE_NEWS = '1' # enabled",
                          'MUCHO_NATIVE_NEWS="1"'):
                (Path(folder) / '.env').write_text(saved + '\n')
                self.assertEqual(self.shell(script, folder, '').stdout, '1')
                self.assertEqual(self.shell(script, folder, '0').stdout, '0')
            self.assertNotEqual(self.shell(script, folder, 'unexpected').returncode, 0)


if __name__ == '__main__':
    unittest.main()
