"""Fault-injection checks for install retries; no hosting credentials or network."""
from contextlib import ExitStack, redirect_stdout
import copy
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/release/5v-installer'))
import INSTALL_5V as installer
from mucho5v import InstallError, merge_rules


class MemoryFtp:
    def __init__(self):
        self.root = '/site'
        self.files = {}
        self.uploads = 0
        self.fail_upload = False
        self.fail_config_commit = False
        self.writes = []

    def __enter__(self): return self
    def __exit__(self, *_): pass
    def find_root(self, *_): return self.root
    def exists_dir(self, base, path): return any(p.startswith(path + '/') for p in self.files)
    def get(self, base, path, optional=False): return self.files.get(path)
    def put(self, base, path, data):
        self.writes.append(path)
        self.files[path] = data
    def delete(self, base, path): self.files.pop(path, None)
    def rename(self, base, old, new):
        self.files[new] = self.files.pop(old)
        if new.endswith('php7-local.php') and self.fail_config_commit:
            self.fail_config_commit = False
            raise OSError('connection lost after config rename')
    def upload_tree(self, folder):
        self.uploads += 1
        self.files['mc/admin.php'] = b'core'
        if self.fail_upload:
            self.fail_upload = False
            raise OSError('interrupted transfer')
    def apply_routes(self, rules, resume=False):
        self.files['.htaccess'] = merge_rules(self.files.get('.htaccess'), rules, resume=resume)


class Site:
    def __init__(self):
        self.check_response = (200, b'{"ok":true}')
        self.health_response = (200, b'1')
        self.requests = []
    def request(self, path, form=None):
        self.requests.append((path, form))
        if path.startswith('/muchocore-db-check-'): return self.check_response
        if path == '/mc/health': return self.health_response
        return 200, b'<main>Administrator dashboard</main>'


class ResumeTests(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.temp = Path(self.stack.enter_context(tempfile.TemporaryDirectory()))
        self.core = self.temp / 'mc'
        self.core.mkdir()
        (self.core / 'admin.php').write_text('<?php // test payload')
        self.rules = self.temp / 'rules.txt'
        self.rules.write_text('\n'.join('RewriteRule ^test%d$ mc/public/php7.php [L]' % i for i in range(8)))
        self.ftp, self.site = MemoryFtp(), Site()
        self.bootstrap = self.stack.enter_context(patch.object(installer, 'bootstrap_owner'))
        self.migrate = self.stack.enter_context(patch.object(installer, 'perform_migration'))
        for name, value in [('CORE_DIR', self.core), ('RULE_FILE', self.rules), ('RELEASE_DIR', self.temp)]:
            self.stack.enter_context(patch.object(installer, name, value))
        self.stack.enter_context(patch.object(installer, 'FtpSite', return_value=self.ftp))
        self.stack.enter_context(patch.object(installer, 'HttpsSite', return_value=self.site))
        self.stack.enter_context(patch.object(installer, 'verify_private_folder'))
        self.login = self.stack.enter_context(patch('PUBLISH_CLIENTS_5V.login_owner'))
        self.cfg = dict(site='https://demo.5v.pl', host='ftp.5v.pl', user='ftp-user', password='ftp-secret',
                        secure=True, root='', mysqlhost='localhost', mysqlport=3306,
                        dbname='testdb', dbuser='testuser', dbpassword='db-secret-123',
                        admin='admin', email='admin@example.test', adminpassword='owner-secret-123')

    def install(self):
        with redirect_stdout(io.StringIO()):
            return installer.perform_install(copy.deepcopy(self.cfg), prepare_clients=False)

    def test_upload_can_resume_after_process_restart(self):
        self.ftp.fail_upload = True
        with self.assertRaises(OSError): self.install()
        self.assertNotIn('mc/config/php7-local.php', self.ftp.files)
        report = self.install()
        self.assertEqual(report['status'], 'admin_and_migration_ok')
        self.assertEqual(self.ftp.uploads, 2)
        self.assertFalse(any(p.startswith('muchocore-db-check-') for p in self.ftp.files))

    def test_config_commit_response_loss_never_rotates_auth_pepper(self):
        self.ftp.fail_config_commit = True
        with self.assertRaises(OSError): self.install()
        config = self.ftp.files['mc/config/php7-local.php']
        self.install()
        self.assertEqual(self.ftp.files['mc/config/php7-local.php'], config)
        self.assertEqual(self.ftp.uploads, 1)

    def test_migration_retry_preserves_owner_config_and_htaccess(self):
        self.ftp.files['.htaccess'] = b'# existing site rules\n'
        self.migrate.side_effect = [InstallError('migration interrupted'), None]
        with self.assertRaises(InstallError): self.install()
        config = self.ftp.files['mc/config/php7-local.php']
        routes = self.ftp.files['.htaccess']
        self.install()
        self.assertEqual(self.ftp.uploads, 1)
        self.assertEqual(self.ftp.files['mc/config/php7-local.php'], config)
        self.assertEqual(self.ftp.files['.htaccess'], routes)
        self.assertEqual(routes.count(b'# BEGIN MUCHOCORE'), 1)

    def test_unmarked_existing_server_is_untouched(self):
        self.ftp.files['mc/config/php7-local.php'] = b'live secret config'
        before = self.ftp.files.copy()
        with self.assertRaises(InstallError): self.install()
        self.assertEqual(self.ftp.files, before)
        self.assertEqual(self.ftp.uploads, 0)
        self.bootstrap.assert_not_called()

    def test_changed_database_or_payload_cannot_resume(self):
        self.ftp.fail_upload = True
        with self.assertRaises(OSError): self.install()
        before = self.ftp.files.copy()
        self.cfg['dbname'] = 'differentdb'
        with self.assertRaises(InstallError): self.install()
        self.assertEqual(self.ftp.files, before)
        self.cfg['dbname'] = 'testdb'
        (self.core / 'admin.php').write_text('different build')
        with self.assertRaises(InstallError): self.install()
        self.assertEqual(self.ftp.files, before)

    def test_nonempty_database_is_rejected_before_payload_upload(self):
        self.site.check_response = (409, b'{"ok":false,"error":"database_not_empty"}')
        with self.assertRaisesRegex(InstallError, 'уже содержит таблицы'): self.install()
        self.assertEqual(self.ftp.files, {})
        self.assertEqual(self.ftp.uploads, 0)

    def test_bad_database_credentials_are_correctable_before_upload(self):
        self.site.check_response = (400, b'{"ok":false,"error":"database_connection_failed"}')
        with self.assertRaises(InstallError): self.install()
        self.assertEqual(self.ftp.files, {})
        self.site.check_response = (200, b'{"ok":true}')
        self.cfg['dbpassword'] = 'corrected-db-password'
        self.install()
        self.assertEqual(self.ftp.uploads, 1)

    def test_completed_retry_authenticates_without_bootstrap_or_migration(self):
        self.install()
        self.bootstrap.reset_mock(); self.migrate.reset_mock()
        before = self.ftp.files['mc/config/php7-local.php']
        self.install()
        self.login.assert_called_once()
        self.bootstrap.assert_not_called(); self.migrate.assert_not_called()
        self.assertEqual(before, self.ftp.files['mc/config/php7-local.php'])

    def test_healthy_status_requires_exact_health_response(self):
        self.site.health_response = (503, b'1')
        with self.assertRaises(InstallError): self.install()
        state = installer.read_checkpoint(self.ftp, installer.install_identity(self.cfg))
        self.assertNotEqual(state['stage'], 'complete')

    def test_checkpoint_and_report_never_store_credentials(self):
        self.install()
        persisted = self.ftp.files[installer.STATE_FILE] + (self.temp / 'muchocore-install-report.json').read_bytes()
        for key in ('password', 'dbpassword', 'adminpassword'):
            self.assertNotIn(self.cfg[key].encode(), persisted)

    def test_modified_existing_routes_are_not_overwritten_on_resume(self):
        self.migrate.side_effect = InstallError('pause')
        with self.assertRaises(InstallError): self.install()
        self.ftp.files['.htaccess'] = self.ftp.files['.htaccess'].replace(b'^test1$', b'^custom$')
        before = self.ftp.files['.htaccess']
        with self.assertRaises(InstallError): self.install()
        self.assertEqual(self.ftp.files['.htaccess'], before)


class OwnerRetryTests(unittest.TestCase):
    def test_lost_owner_creation_response_uses_authenticated_login(self):
        site = Site()
        with patch('PUBLISH_CLIENTS_5V.login_owner') as login, redirect_stdout(io.StringIO()):
            installer.bootstrap_owner(site, {'admin': 'admin', 'adminpassword': 'owner-password'})
            login.assert_called_once_with(site, 'admin', 'owner-password')
        self.assertTrue(all(form is None for _, form in site.requests))


if __name__ == '__main__':
    unittest.main()
