import ftplib
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("updater", Path(__file__).with_name("updater.py"))
u = importlib.util.module_from_spec(spec)
spec.loader.exec_module(u)


class MemoryRemote:
    def __init__(self, files=None, fail_once=None):
        self.files = dict(files or {})
        self.fail_once = fail_once
        self.put_count = 0
    def get(self, rel, required=False, max_size=None):
        value = self.files.get(rel)
        if required and value is None:
            raise ftplib.error_perm("550 missing")
        return value
    def put(self, rel, data):
        self.put_count += 1
        if rel == self.fail_once:
            self.fail_once = None
            self.files[rel] = data[:1]
            raise EOFError("broken transfer")
        self.files[rel] = data
    def delete(self, rel):
        self.files.pop(rel, None)


class FakeFtp:
    def __init__(self, auth_error=None, login_error=None):
        self.auth_error = auth_error
        self.login_error = login_error
        self.files = {}
        self.path = '/home'
    def connect(self, *args, **kwargs): return '220 ready'
    def auth(self):
        if self.auth_error: raise self.auth_error
        return '234 ready'
    def login(self, *args):
        if self.login_error: raise self.login_error
        return '230 welcome'
    def prot_p(self): pass
    def set_pasv(self, flag): pass
    def close(self): pass
    def pwd(self): return self.path
    def cwd(self, path):
        if path == '/home': self.path = path
        elif path == 'public_html': self.path = '/home/public_html'
        elif path == '.': pass
        else: raise ftplib.error_perm('550 missing')
    def storbinary(self, command, stream): self.files[(self.path, command[5:])] = stream.read()
    def delete(self, name): self.files.pop((self.path, name), None)


class MockHttps:
    def __init__(self, ftp, match=False): self.ftp, self.match = ftp, match
    def request(self, path, **kw):
        name = path.lstrip('/')
        data = self.ftp.files.get((self.ftp.path, name), b'')
        return (200, data if self.match else b'wrong-site')


class UpdateTests(unittest.TestCase):
    def package(self, path, incomplete=False, corrupt_pack=False, private=False):
        pack = b'valid-core-pack'
        metadata = json.dumps({'sha256': hashlib.sha256(pack).hexdigest()}).encode()
        with zipfile.ZipFile(path, 'w') as z:
            for rel in u.REQUIRED:
                if incomplete and rel == 'admin.php': continue
                data = b'<?php mucho7_core_upgrade();' if rel.endswith('.php') else b'allow'
                if rel == 'config/php7-core-update.pack': data = pack + (b'bad' if corrupt_pack else b'')
                if rel == 'config/php7-core-update.json': data = metadata
                z.writestr('mc/' + rel, data)
            if private:
                z.writestr('mc/config/php7-local.php', b'PRIVATE')
        return path

    def test_invalid_ftp_login_never_downgrades(self):
        ftp = FakeFtp(login_error=ftplib.error_perm('530 denied'))
        with patch.object(u.ftplib, 'FTP_TLS', return_value=ftp), patch.object(u.ftplib, 'FTP') as plain:
            with self.assertRaisesRegex(RuntimeError, 'login was rejected'):
                u.connect_ftp('host4.5v.pl', 'user', 'secret', True)
            plain.assert_not_called()

    def test_missing_ftps_requires_explicit_opt_in(self):
        tls = FakeFtp(auth_error=ftplib.error_perm('500 AUTH not understood'))
        with patch.object(u.ftplib, 'FTP_TLS', return_value=tls), patch.object(u.ftplib, 'FTP', return_value=FakeFtp()) as plain:
            with self.assertRaisesRegex(RuntimeError, 'explicit authorization'):
                u.connect_ftp('host4.5v.pl', 'user', 'secret', False)
            plain.assert_not_called()
            self.assertIsInstance(u.connect_ftp('host4.5v.pl', 'user', 'secret', True), FakeFtp)

    def test_bad_certificate_never_downgrades(self):
        import ssl
        tls = FakeFtp(auth_error=ssl.SSLCertVerificationError('bad certificate'))
        with patch.object(u.ftplib, 'FTP_TLS', return_value=tls), patch.object(u.ftplib, 'FTP') as plain:
            with self.assertRaisesRegex(RuntimeError, 'certificate'):
                u.connect_ftp('host4.5v.pl', 'user', 'secret', True)
            plain.assert_not_called()

    def test_root_detection_failure(self):
        ftp = FakeFtp()
        with self.assertRaisesRegex(RuntimeError, 'Could not verify FTP document root'):
            u.find_verified_root(ftp, MockHttps(ftp), u.TARGET_SITE, 'public_html')
        self.assertFalse(ftp.files)

    def test_broken_transfer_restores_old_file(self):
        remote = MemoryRemote({'mc/admin.php': b'old'}, fail_once='mc/admin.php')
        with self.assertRaises(EOFError):
            u.perform_upload(remote, {'admin.php': b'new'}, {'admin.php': b'old'}, set())
        self.assertEqual(remote.files['mc/admin.php'], b'old')

    def test_interrupted_ftp_read_reconnects_and_discards_partial_bytes(self):
        first = FakeFtp()
        second = FakeFtp()
        def broken(command, callback, blocksize):
            callback(b'partial')
            raise EOFError('server reset')
        def healthy(command, callback, blocksize):
            callback(b'complete')
        first.retrbinary = broken
        second.retrbinary = healthy
        remote = u.Remote(first, '/home', reconnect=lambda: second)
        with patch.object(u.time, 'sleep'):
            self.assertEqual(remote.get('mc/admin.php'), b'complete')
        self.assertIs(remote.ftp, second)

    def test_rerun_skips_unchanged(self):
        remote = MemoryRemote({'mc/admin.php': b'same'})
        with tempfile.TemporaryDirectory() as d:
            old, unchanged = u.backup_files(remote, {'admin.php': b'same'}, Path(d)/'backup.zip', 'a'*64)
            self.assertEqual(u.perform_upload(remote, {'admin.php': b'same'}, old, unchanged), 0)
        self.assertEqual(remote.put_count, 0)

    def test_mismatched_sha256(self):
        with tempfile.TemporaryDirectory() as d:
            path = self.package(Path(d)/'pkg.zip')
            with self.assertRaisesRegex(RuntimeError, 'Archive SHA-256 mismatch'):
                u.load_package(path, '0'*64)
            with self.assertRaisesRegex(RuntimeError, 'pack hash'):
                u.load_package(self.package(Path(d)/'bad.zip', corrupt_pack=True))

    def test_incomplete_upload_package(self):
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaisesRegex(RuntimeError, 'Incomplete PHP7 package'):
                u.load_package(self.package(Path(d)/'pkg.zip', incomplete=True))

    def test_private_files_preserved(self):
        self.assertFalse(u.is_shipped_path('compat/php7/config/php7-local.php'))
        self.assertFalse(u.is_shipped_path('public/assets/cloudsave.key'))
        self.assertFalse(u.is_shipped_path('public/admin/php7-apk-signer.json'))
        with tempfile.TemporaryDirectory() as d:
            files, _ = u.load_package(self.package(Path(d)/'pkg.zip'))
            self.assertNotIn('config/php7-local.php', files)
            with self.assertRaisesRegex(RuntimeError, 'private config path'):
                u.load_package(self.package(Path(d)/'private.zip', private=True))

    def test_restore_after_failed_update(self):
        original = {'mc/admin.php': b'old', '.htaccess': b'RewriteEngine On\n'}
        remote = MemoryRemote(original)
        with tempfile.TemporaryDirectory() as d:
            path = Path(d)/'backup.zip'
            old, unchanged = u.backup_files(remote, {'admin.php': b'new', 'public/new.php': b'<?php'}, path, 'a'*64,
                                             original['.htaccess'], b'RewriteEngine On\n# new\n')
            remote.files.update({'mc/admin.php': b'broken', 'mc/public/new.php': b'broken', '.htaccess': b'broken'})
            u.restore_backup(remote, path)
            self.assertEqual(remote.files, original)

    def test_root_rewrite_plan_keeps_existing_rules(self):
        remote = MemoryRemote({'.htaccess': b'RewriteEngine On\nRewriteRule ^legacy$ legacy.php [L]\n'})
        old, new = u.plan_root_rewrites(remote)
        self.assertIn(b'legacy.php', new)
        self.assertIn(b'mc/public/php7.php', new)
        self.assertEqual(u.plan_root_rewrites(MemoryRemote({'.htaccess': new}))[1], new)

    def test_missing_root_htaccess_created_and_rolled_back(self):
        remote = MemoryRemote({'mc/admin.php': b'old'})
        root_old, root_new = u.plan_root_rewrites(remote)
        self.assertIsNone(root_old)
        with tempfile.TemporaryDirectory() as d:
            backup = Path(d)/'backup.zip'
            old, unchanged = u.backup_files(remote, {'admin.php': b'old'}, backup, 'a'*64,
                                             root_old, root_new)
            u.perform_upload(remote, {'admin.php': b'old'}, old, unchanged, root_new)
            self.assertEqual(remote.get('.htaccess'), root_new)
            u.restore_backup(remote, backup)
            self.assertIsNone(remote.get('.htaccess'))
            self.assertEqual(remote.get('mc/admin.php'), b'old')

    def test_diagnostic_log_masks_password(self):
        with tempfile.TemporaryDirectory() as d:
            u.DIAGNOSTIC_FILE = str(Path(d)/'log.txt')
            u.SECRETS.append('very-secret-password')
            try:
                u.say('[STOP] very-secret-password failed')
                self.assertNotIn('very-secret-password', Path(u.DIAGNOSTIC_FILE).read_text())
            finally:
                u.DIAGNOSTIC_FILE = None
                u.SECRETS.clear()


if __name__ == '__main__': unittest.main()
