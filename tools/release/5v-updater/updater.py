#!/usr/bin/env python3
"""Internal helper embedded by UPDATE_MUCHOCORE_5V.ps1; standard library only."""
from __future__ import annotations
import argparse, ftplib, getpass, hashlib, html, http.cookiejar, io, json
import os, re, secrets, ssl, sys, time, urllib.error, urllib.parse, urllib.request, zipfile
from datetime import datetime
from html.parser import HTMLParser
from pathlib import Path

REPO = 'IZKGMD/GMDmucho-core'
SITE_RE = re.compile(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.5v\.pl', re.I)
FTP_RE = re.compile(r'[a-z0-9][a-z0-9.-]{0,251}[a-z0-9]', re.I)
REQUIRED = {'admin.php','public/php7-admin.php','public/php7.php','compat/php7/core-update.php',
            'config/php7-core-update.pack','config/php7-core-update.json',
            '.htaccess','config/.htaccess','compat/.htaccess'}
MAX_ZIP = 60 * 1024 * 1024
MAX_FILE = 32 * 1024 * 1024
MAX_TOTAL = 300 * 1024 * 1024
TARGET_SITE = 'muchocoretest.5v.pl'
DIAGNOSTIC_FILE = None
SECRETS = []
ROOT_RULES = (
    'RewriteRule ^gd-content/ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^api/api/accounts/lostusername\\.php$ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^(?:api|a)/(?:api/)?(?:profile|clans|muchocore)/ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^(?:a|database|accounts|api)/(?:accounts/)?[A-Za-z0-9_-]+\\.php$ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^(?:getAccountURL|getCustomContentURL|checkIfServerOnline)\\.php$ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^gd-content/news/news_version\\.txt$ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^gd-content/news/news\\.dat$ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^news/news_version\\.txt$ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^news/news\\.dat$ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^(?:gd-content/)?news/mcnews_[a-f0-9]{64}\\.jpg$ mc/public/php7.php [QSA,L,NC]',
    'RewriteRule ^news/image\\.php$ mc/public/php7.php [QSA,L,NC]',
)


def say(msg):
    for secret in SECRETS:
        if secret:
            msg = msg.replace(secret, '[hidden]')
    print(msg, flush=True)
    if DIAGNOSTIC_FILE:
        with open(DIAGNOSTIC_FILE, 'a', encoding='utf-8') as report:
            report.write(msg + '\n')


def sha(data):
    return hashlib.sha256(data).hexdigest()


def normalize_site(host):
    host = host.lower().strip()
    if host != TARGET_SITE:
        raise RuntimeError('This updater is locked to ' + TARGET_SITE + '.')
    return host


def is_shipped_path(name):
    private_names = {'php7-local.php', 'cloudsave.key', 'php7-apk-signer.json'}
    if any(part in private_names for part in name.split('/')):
        return False
    if name in REQUIRED or name in {'public/php7-setup.php','public/.htaccess',
                                     'public/muchocore-theme.css','public/muchocore-theme.js'}:
        return True
    if name.startswith('compat/php7/'):
        return not (name.startswith('compat/php7/builds/') or '/config/php7-local.php' in name)
    return name.startswith(('public/assets/', 'public/admin/', 'public/recovery/', 'docs/'))


def load_package(path, expected_archive_sha=None):
    if path.stat().st_size > MAX_ZIP:
        raise RuntimeError('Archive is larger than the allowed bound.')
    archive_sha = hashlib.sha256(path.read_bytes()).hexdigest()
    if expected_archive_sha and archive_sha != expected_archive_sha.lower():
        raise RuntimeError('Archive SHA-256 mismatch.')
    say('[PACKAGE] Archive SHA-256: ' + archive_sha)
    files = {}
    total = 0
    with zipfile.ZipFile(path) as archive:
        for item in archive.infolist():
            raw = item.filename.replace('\\', '/')
            if item.is_dir():
                continue
            parts = raw.split('/')
            if (not raw or raw.startswith('/') or any(x in ('','.', '..') for x in parts)
                    or '\x00' in raw or ':' in raw or item.file_size > MAX_FILE
                    or ((item.external_attr >> 16) & 0o170000) == 0o120000):
                raise RuntimeError('Unsafe ZIP entry detected.')
            total += item.file_size
            if total > MAX_TOTAL:
                raise RuntimeError('ZIP unpacked size exceeds limit.')
            if not raw.startswith('mc/'):
                continue  # installers and documentation at the archive root
            rel = raw[3:]
            if rel.startswith('config/') and rel not in {
                    'config/.htaccess','config/php7-core-update.pack','config/php7-core-update.json'}:
                raise RuntimeError('Package unexpectedly contains a private config path: ' + rel)
            if not is_shipped_path(rel):
                continue
            if rel in files:
                raise RuntimeError('Duplicate path in package: ' + rel)
            files[rel] = archive.read(item)
    missing = sorted(REQUIRED - files.keys())
    if missing:
        raise RuntimeError('Incomplete PHP7 package: ' + ', '.join(missing))
    metadata = json.loads(files['config/php7-core-update.json'].decode('utf-8'))
    expected = metadata.get('sha256', '')
    if not re.fullmatch('[a-f0-9]{64}', expected) or sha(files['config/php7-core-update.pack']) != expected:
        raise RuntimeError('Pinned PHP7 core pack hash verification failed.')
    # The ZIP must be a verified native PHP7 artifact, not arbitrary app files.
    if b'mucho7_core_upgrade' not in files['compat/php7/core-update.php']:
        raise RuntimeError('This is not the expected PHP7 core installer.')
    for name in ('admin.php', 'public/php7-admin.php', 'public/php7.php',
                 'compat/php7/core-update.php'):
        if not files[name].startswith(b'<?php'):
            raise RuntimeError('PHP entrypoint has an invalid header: ' + name)
    return files, expected


class OnlySiteRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, site):
        self.site = site
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        u = urllib.parse.urlsplit(newurl)
        if u.scheme != 'https' or u.hostname != self.site or u.port is not None or u.username or u.password:
            raise RuntimeError('Refusing cross-origin HTTPS redirect.')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class HttpsClient:
    def __init__(self, site):
        self.site = site
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
            urllib.request.HTTPCookieProcessor(self.cookies), OnlySiteRedirect(site))
    def request(self, path, form=None, limit=2_000_000):
        if not path.startswith('/') or path.startswith('//') or '\\' in path:
            raise RuntimeError('Invalid HTTPS request path.')
        content = None if form is None else urllib.parse.urlencode(form).encode('utf-8')
        req = urllib.request.Request('https://' + self.site + path, data=content,
                 headers={'User-Agent': 'MuchoCore-5V-Updater/1.0',
                          'Accept': 'text/html,text/plain,application/json'},
                 method='POST' if form is not None else 'GET')
        try:
            response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as ex:
            response = ex
        except urllib.error.URLError as ex:
            raise RuntimeError('HTTPS connection or certificate verification failed.') from ex
        with response:
            body = response.read(limit + 1)
            if len(body) > limit:
                raise RuntimeError('HTTPS response was too large.')
            return response.status, body


class InputParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.fields = {}
    def handle_starttag(self, tag, attrs):
        if tag.lower() == 'input':
            data = dict(attrs)
            if data.get('name') and 'value' in data:
                self.fields[data['name']] = data['value']


def csrf_from(body):
    parser = InputParser()
    parser.feed(body.decode('utf-8', errors='replace'))
    token = parser.fields.get('csrf', '')
    if not re.fullmatch('[a-f0-9]{48}', token):
        raise RuntimeError('Admin CSRF token not found; refusing to write.')
    return token


def assert_admin_success(status, body, action):
    if status != 200:
        raise RuntimeError('Admin rejected ' + action + ': HTTP ' + str(status))
    content = body.decode('utf-8', errors='replace')
    if 'Administrator sign in' in content and 'name="action" value="login"' in content:
        raise RuntimeError('Admin is not authenticated; check username, password and 2FA.')
    if re.search(r'<[^>]+(?:class="err"|role="alert")[^>]*>', content, re.I):
        # Never dump the returned HTML, which may contain sensitive form fields.
        raise RuntimeError('Admin returned an error while performing ' + action + '. Inspect the Server page.')


class Remote:
    def __init__(self, ftp, base, reconnect=None):
        self.ftp = ftp
        self.base = base
        self.reconnect = reconnect
    def retry(self, label, operation):
        for attempt in range(3):
            try:
                return operation()
            except (OSError, EOFError, ftplib.error_temp, ftplib.error_proto) as ex:
                if attempt == 2 or self.reconnect is None:
                    raise RuntimeError('FTP ' + label + ' failed after ' + str(attempt+1) + ' attempts: '
                                       + type(ex).__name__) from ex
                say('[FTP] Connection interrupted during ' + label + '; reconnecting (attempt '
                    + str(attempt+2) + '/3).')
                try: self.ftp.close()
                except Exception: pass
                time.sleep(attempt + 1)
                self.ftp = self.reconnect()
    def cd(self, directory=''):
        self.ftp.cwd(self.base)
        if directory:
            for part in directory.split('/'):
                if not re.fullmatch('[A-Za-z0-9_.-]+', part) or part in ('.', '..'):
                    raise RuntimeError('Unsafe FTP directory name.')
                self.ftp.cwd(part)
    def mkdirs(self, directory):
        self.ftp.cwd(self.base)
        for part in directory.split('/') if directory else []:
            if not re.fullmatch('[A-Za-z0-9_.-]+', part) or part in ('.', '..'):
                raise RuntimeError('Unsafe FTP directory name.')
            try:
                self.ftp.cwd(part)
            except ftplib.error_perm:
                self.ftp.mkd(part)
                self.ftp.cwd(part)
    def get(self, rel, required=False, max_size=MAX_FILE):
        def action():
            self.cd()
            data = io.BytesIO()
            def collect(block):
                if data.tell() + len(block) > max_size:
                    raise RuntimeError('Remote file is too large: ' + rel)
                data.write(block)
            try:
                self.ftp.retrbinary('RETR ' + rel, collect, blocksize=65536)
                return data.getvalue()
            except ftplib.error_perm as ex:
                if not required and str(ex).startswith('550'):
                    return None
                raise
        return self.retry('read ' + rel, action)
    def put(self, rel, data):
        def action():
            directory, _, name = rel.rpartition('/')
            self.mkdirs(directory)
            self.ftp.storbinary('STOR ' + name, io.BytesIO(data), blocksize=65536)
            self.cd()
        return self.retry('write ' + rel, action)
    def delete(self, rel):
        def action():
            self.cd()
            try:
                self.ftp.delete(rel)
            except ftplib.error_perm as ex:
                if not str(ex).startswith('550'):
                    raise
        return self.retry('delete ' + rel, action)


def connect_ftp(host, user, password, allow_plain):
    # Explicit FTPS negotiation is attempted BEFORE sending credentials.
    # Never silently downgrade because of a bad password or invalid TLS certificate.
    tls = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=30)
    phase = 'TCP port 21'
    try:
        tls.connect(host, 21, timeout=30)
        phase = 'AUTH TLS / certificate validation'
        tls.auth()
        phase = 'encrypted login'
        tls.login(user, password)
        phase = 'encrypted data channel'
        tls.prot_p()
        tls.set_pasv(True)
        say('[FTP] Encrypted FTPS session established.')
        return tls
    except ftplib.all_errors as ex:
        try: tls.close()
        except Exception: pass
        # Avoid exposing private passwords or arbitrary server text in output.
        detail = str(ex).replace(password, '[hidden]')
        detail = ''.join(ch if 32 <= ord(ch) <= 126 else '?' for ch in detail)[:170]
        say('[FTPS] Failed at ' + phase + ': ' + type(ex).__name__ + ': ' + detail)
        if phase == 'encrypted login':
            raise RuntimeError('FTPS worked, but the FTP login was rejected. Verify FTP-specific username/password in the 5v.pl welcome email. Plain FTP will NOT help.') from ex
        if phase == 'encrypted data channel':
            raise RuntimeError('Encrypted login worked but FTPS data protection failed. Confirm FTP host/TLS support with the provider; refusing downgrade.') from ex
        if isinstance(ex, (ssl.SSLError, ssl.SSLCertVerificationError)):
            raise RuntimeError('FTPS certificate/TLS verification failed. Do not disable certificate verification or downgrade; check the exact FTP hostname.') from ex
        if phase == 'TCP port 21':
            raise RuntimeError('Cannot reach FTP port 21. Check exact FTP server address from 5v.pl account email and network/firewall.') from ex
        unsupported = (phase == 'AUTH TLS / certificate validation'
                       and isinstance(ex, ftplib.error_perm)
                       and re.match(r'^(500|502|504)\b', str(ex)))
        if not unsupported:
            raise RuntimeError('FTPS failed for a reason other than explicit lack of AUTH TLS; refusing downgrade.') from ex
        if not allow_plain:
            raise RuntimeError('FTP server rejected AUTH TLS. Plain FTP needs explicit authorization; no files have been modified.') from ex
    say('[WARNING] You explicitly selected UNENCRYPTED FTP: username, password, file data may be exposed on the network.')
    ftp = ftplib.FTP(timeout=30)
    try:
        ftp.connect(host, 21, timeout=30)
        ftp.login(user, password)
        ftp.set_pasv(True)
    except ftplib.all_errors as ex:
        try: ftp.close()
        except Exception: pass
        detail = str(ex).replace(password, '[hidden]')
        detail = ''.join(ch if 32 <= ord(ch) <= 126 else '?' for ch in detail)[:170]
        raise RuntimeError('Plain FTP connection/login failed: ' + type(ex).__name__ + ': ' + detail) from ex
    return ftp


def find_verified_root(ftp, https, site, root_hint):
    home = ftp.pwd()
    label = site.split('.')[0]
    candidates = [root_hint] if root_hint else [
       '.', label, 'public_html', 'www', 'httpdocs', 'htdocs', 'public',
       'public_html/'+label, 'www/'+label, 'domains/'+site+'/public_html',
       'domains/'+site, 'sites/'+site]
    if not root_hint and hasattr(ftp, 'nlst'):
        # Discover arbitrary hosting directory names, then prove ownership
        # with a random HTTPS marker. Names never decide the selected root.
        queue = [('.', 0)]
        seen = set(candidates)
        while queue and len(seen) < 100:
            parent, depth = queue.pop(0)
            if depth >= 3:
                continue
            try:
                ftp.cwd(home)
                if parent != '.':
                    for part in parent.split('/'):
                        ftp.cwd(part)
                names = ftp.nlst()[:100]
            except ftplib.all_errors:
                continue
            for raw in names:
                part = raw.rstrip('/').split('/')[-1]
                if not re.fullmatch(r'[A-Za-z0-9_.-]+', part) or part in ('.', '..'):
                    continue
                candidate = part if parent == '.' else parent + '/' + part
                if candidate in seen:
                    continue
                try:
                    ftp.cwd(home)
                    for component in candidate.split('/'):
                        ftp.cwd(component)
                except ftplib.all_errors:
                    continue
                seen.add(candidate)
                candidates.append(candidate)
                queue.append((candidate, depth + 1))
    marker = 'muchocore-verify-' + secrets.token_hex(8) + '.txt'
    token = secrets.token_hex(32).encode('ascii')
    failures = []
    for candidate in candidates:
        if candidate.startswith('/') or '..' in candidate.split('/'):
            continue
        try:
            ftp.cwd(home)
            if candidate != '.':
                for part in candidate.split('/'):
                    ftp.cwd(part)
            candidate_base = ftp.pwd()
            try:
                ftp.storbinary('STOR ' + marker, io.BytesIO(token))
            except ftplib.all_errors:
                try: ftp.delete(marker)
                except ftplib.all_errors: pass
                raise
            try:
                status, body = https.request('/' + marker, limit=4096)
                if status == 200 and body.strip() == token:
                    say('[FTP] HTTPS-confirmed website root: ' + candidate)
                    return Remote(ftp, candidate_base)
                failures.append(candidate + ': HTTPS ' + str(status) + ' or marker mismatch')
            finally:
                try:
                    ftp.delete(marker)
                except ftplib.all_errors as ex:
                    raise RuntimeError('Could not remove FTP root marker; stop and inspect '+candidate) from ex
        except ftplib.all_errors + (OSError, RuntimeError) as ex:
            if isinstance(ex, RuntimeError) and str(ex).startswith('Could not remove FTP root marker'):
                raise
            failures.append(candidate + ': ' + type(ex).__name__)
            continue
    ftp.cwd(home)
    raise RuntimeError('Could not verify FTP document root via HTTPS marker. Checked: '
                       + '; '.join(failures[:12]) + '. Pass -FtpRoot if needed.')


def preflight(remote, https):
    code = remote.get('mc/admin.php', required=True, max_size=100_000)
    front = remote.get('mc/public/php7-admin.php', required=True, max_size=1_000_000)
    if b'php7-admin.php' not in code or b'muchocore7_admin_' not in front:
        raise RuntimeError('Existing /mc is not a recognized MuchoCore PHP7 installation.')
    conf = remote.get('mc/config/php7-local.php', required=True, max_size=200_000)
    if not conf.startswith(b'<?php'):
        raise RuntimeError('Private tenant configuration is missing or invalid.')
    del conf
    if remote.get('mc/config/php7-core-transition.json', max_size=100_000) is not None:
        raise RuntimeError('An existing migration is in progress. Finish it in Server before starting a file update.')
    marker = 'mc/config/muchocore-private-' + secrets.token_hex(7) + '.txt'
    token = secrets.token_hex(16).encode('ascii')
    remote.put(marker, token)
    try:
        status, body = https.request('/' + marker, limit=8000)
        if status not in (401, 403, 404) or token in body:
            raise RuntimeError('Private config/ is publicly accessible. Update refused.')
    finally:
        remote.delete(marker)


def plan_root_rewrites(remote):
    current = remote.get('.htaccess', max_size=250_000)
    if current is None:
        updated = ('RewriteEngine On\n# MuchoCore PHP7 routes for ' + TARGET_SITE
                   + '\n' + '\n'.join(ROOT_RULES) + '\n# End MuchoCore PHP7 routes\n')
        say('[ROUTES] Site root .htaccess is absent; prepared a new routing file.')
        return None, updated.encode('utf-8')
    try:
        text = current.decode('utf-8')
    except UnicodeError as ex:
        raise RuntimeError('Site root .htaccess is not UTF-8; refusing automatic rewrite.') from ex
    if not re.search(r'^\s*RewriteEngine\s+On\s*$', text, re.I | re.M):
        raise RuntimeError('Site root .htaccess has no RewriteEngine On; refusing automatic rewrite.')
    missing = [rule for rule in ROOT_RULES if rule not in text]
    if not missing:
        return current, current
    newline = '\r\n' if b'\r\n' in current else '\n'
    block = ('# MuchoCore PHP7 routes for ' + TARGET_SITE + '\n'
             + '\n'.join(missing) + '\n# End MuchoCore PHP7 routes\n').replace('\n', newline)
    match = re.search(r'^\s*RewriteEngine\s+On\s*$', text, re.I | re.M)
    updated = text[:match.end()] + newline + block + text[match.end():]
    say('[ROUTES] Prepared ' + str(len(missing)) + ' missing root rewrite rules.')
    return current, updated.encode('utf-8')


def backup_files(remote, files, path, build_sha, root_old=None, root_new=None, mode_missing=False):
    old = {}
    skipped = []
    backup_size = 0
    items = sorted(files)
    say('[BACKUP] Reading existing code files before any update...')
    for idx, rel in enumerate(items, 1):
        current = remote.get('mc/' + rel)
        if current is not None:
            if current == files[rel]:
                skipped.append(rel)
            else:
                old[rel] = current
                backup_size += len(current)
                if backup_size > MAX_TOTAL:
                    raise RuntimeError('Existing files exceed local backup size limit.')
        if idx % 25 == 0 or idx == len(items):
            say('[BACKUP] Inspected '+str(idx)+'/'+str(len(items))+' package paths.')
    if root_old is not None and root_old != root_new:
        old['.htaccess'] = root_old
    backup_temp = path.with_suffix('.partial')
    if path.exists() or backup_temp.exists():
        raise RuntimeError('Backup filename exists already.')
    with zipfile.ZipFile(backup_temp, 'w', compression=zipfile.ZIP_DEFLATED, allowZip64=False) as out:
        for rel, value in old.items():
            out.writestr(rel if rel == '.htaccess' else 'mc/' + rel, value)
        out.writestr('MUCHOCORE_BACKUP_MANIFEST.json', json.dumps({
            'reason':'Code files being overwritten, not a database backup',
            'created_utc': datetime.utcnow().isoformat() + 'Z',
            'target_build_sha256': build_sha,
            'target_site': TARGET_SITE,
            'file_count': len(old),
            'new_files': (['mc/'+name for name in items if name not in old and name not in skipped]
                          + (['mc/public/php7-mode.on'] if mode_missing else [])
                          + (['.htaccess'] if root_old is None and root_new is not None else [])),
            'sha256': {(name if name == '.htaccess' else 'mc/'+name):sha(value) for name,value in old.items()}
        }, indent=2))
    backup_temp.rename(path)
    say('[BACKUP] Saved '+str(len(old))+' existing files: '+str(path))
    return old, set(skipped)


def order_key(path):
    # Stage lower-level PHP modules first, public entrypoints last.
    if path.startswith('compat/php7/'):
        return (0, path)
    if path.startswith(('public/assets/','public/admin/','public/recovery/','docs/')):
        return (1, path)
    if path == 'config/php7-core-update.pack':
        return (3, path)
    if path == 'config/php7-core-update.json':
        return (4, path)
    if path == 'public/php7-admin.php':
        return (7, path)
    if path in ('public/php7.php', 'admin.php'):
        return (8, path)
    return (2, path)


def perform_upload(remote, files, old, unchanged, root_new=None):
    touched = []
    items = sorted((r for r in files if r not in unchanged), key=order_key)
    say('[FTP] Uploading '+str(len(items))+' changed/new files...')
    try:
        for idx, rel in enumerate(items, 1):
            touched.append(rel)
            remote.put('mc/' + rel, files[rel])
            returned = remote.get('mc/' + rel, required=True)
            if sha(returned) != sha(files[rel]):
                raise RuntimeError('FTP read-back SHA-256 mismatch: ' + rel)
            if idx % 25 == 0 or idx == len(items):
                say('[FTP] Verified '+str(idx)+'/'+str(len(items))+' files.')
        if root_new is not None and ('.htaccess' in old or remote.get('.htaccess') is None):
            touched.append('.htaccess')
            remote.put('.htaccess', root_new)
            if sha(remote.get('.htaccess', required=True)) != sha(root_new):
                raise RuntimeError('Root rewrite read-back mismatch.')
    except Exception:
        say('[ROLLBACK] FTP stage failed. Restoring all files changed by this attempt...')
        errors = []
        for rel in reversed(touched):
            try:
                target = rel if rel == '.htaccess' else 'mc/' + rel
                if rel in old:
                    remote.put(target, old[rel])
                    if sha(remote.get(target, required=True)) != sha(old[rel]):
                        raise RuntimeError('Rollback read-back mismatch')
                else:
                    remote.delete(target)
                    if remote.get(target) is not None:
                        raise RuntimeError('Rollback delete mismatch')
            except Exception:
                errors.append(rel)
        if errors:
            say('[CRITICAL] Automatic file rollback incomplete; restore from the local backup: ' + ', '.join(errors[:8]))
        raise
    return len(items)


def verify_php_execution(remote, https, files):
    """Run a nonce-bound PHP probe and compare hashes of the deployed entry points."""
    nonce = secrets.token_hex(24)
    probe = 'mc/public/mucho-verify-' + secrets.token_hex(12) + '.php'
    body = ("<?php\nheader('Content-Type: application/json');\n"
            "if (!hash_equals('" + nonce + "', (string)($_GET['token'] ?? ''))) { http_response_code(404); exit; }\n"
            "echo json_encode(array('php'=>PHP_VERSION, 'admin'=>hash_file('sha256', __DIR__.'/php7-admin.php'), "
            "'runtime'=>hash_file('sha256', __DIR__.'/php7.php'), "
            "'installer'=>hash_file('sha256', __DIR__.'/../compat/php7/core-update.php')));\n").encode('ascii')
    try:
        remote.put(probe, body)
        status, response = https.request('/' + probe + '?token=' + nonce, limit=4096)
        if status != 200:
            raise RuntimeError('PHP execution probe returned HTTP ' + str(status))
        try:
            observed = json.loads(response.decode('utf-8'))
        except (ValueError, UnicodeError) as ex:
            raise RuntimeError('PHP execution probe returned invalid JSON.') from ex
        expected = {'admin': sha(files['public/php7-admin.php']),
                    'runtime': sha(files['public/php7.php']),
                    'installer': sha(files['compat/php7/core-update.php'])}
        if any(observed.get(key) != value for key, value in expected.items()):
            raise RuntimeError('PHP execution probe found stale or incomplete deployed files.')
        version = str(observed.get('php', ''))
        if not version.startswith('7.'):
            raise RuntimeError('Expected PHP 7 runtime; server reported ' + version[:20])
        say('[PHP] PHP ' + version + ' executed; three deployed file hashes match.')
    finally:
        remote.delete(probe)


def restore_backup(remote, backup):
    with zipfile.ZipFile(backup) as source:
        manifest = json.loads(source.read('MUCHOCORE_BACKUP_MANIFEST.json'))
        if manifest.get('target_site') != TARGET_SITE:
            raise RuntimeError('Backup belongs to a different site.')
        old = manifest.get('sha256', {})
        new = manifest.get('new_files', [])
        if not isinstance(old, dict) or not isinstance(new, list):
            raise RuntimeError('Malformed backup manifest.')
        for rel, digest in old.items():
            if (rel != '.htaccess' and not re.fullmatch(r'mc/[A-Za-z0-9_./-]+', rel)) or '..' in rel.split('/'):
                raise RuntimeError('Unsafe backup path.')
            data = source.read(rel)
            if sha(data) != digest:
                raise RuntimeError('Backup SHA-256 mismatch: ' + rel)
        for rel in new:
            if not isinstance(rel, str) or (not rel.startswith('mc/') and rel != '.htaccess') or '..' in rel.split('/'):
                raise RuntimeError('Unsafe new-file path in backup.')
        for rel in new:
            remote.delete(rel)
        for rel in sorted(old):
            data = source.read(rel)
            remote.put(rel, data)
            if sha(remote.get(rel, required=True)) != old[rel]:
                raise RuntimeError('Restored file read-back mismatch: ' + rel)
    say('[ROLLBACK] Code files restored and verified. Database state was not changed.')


def promote_core(remote, https, expected_sha):
    path = '/mc/public/php7-admin.php?page=system'
    built = remote.get('mc/config/php7-core-build.json', max_size=100_000)
    active = remote.get('mc/config/php7-complete.on', max_size=100_000)
    if built:
        try: installed_sha = json.loads(built.decode('utf-8')).get('sha256')
        except (ValueError, UnicodeError): installed_sha = None
    else:
        installed_sha = None
    if installed_sha == expected_sha and active is not None:
        say('[CORE] The pinned core already matches this package. No DB migration needed.')
        return
    if active is None and installed_sha and installed_sha != expected_sha:
        raise RuntimeError('Incomplete existing core uses another build; finish its checkpoint before updating.')
    say('[CORE] Promoting verified package with an authenticated owner session...')
    code, start = https.request(path)
    if code != 200:
        raise RuntimeError('Could not open admin login page.')
    csrf = csrf_from(start)
    owner = input('Admin owner username: ').strip()
    password = getpass.getpass('Admin owner password (hidden): ')
    SECRETS.append(password)
    otp = getpass.getpass('2FA/recovery code (hidden; Enter if disabled): ').strip()
    SECRETS.append(otp)
    if not owner or not password:
        raise RuntimeError('Owner username/password is required to promote the pinned build.')
    code, body = https.request(path, {'csrf':csrf,'action':'login',
                                      'userName':owner,'password':password,'otp':otp})
    password = ''
    assert_admin_success(code, body, 'owner login')
    if 'Full core migration' not in body.decode('utf-8', errors='replace') and 'Full core enabled' not in body.decode('utf-8', errors='replace'):
        raise RuntimeError('Signed-in Server view was not shown. Verify owner login and 2FA.')
    csrf = csrf_from(body)
    confirmation = input('Core installation may migrate the database. Type INSTALL to continue: ').strip()
    if confirmation != 'INSTALL':
        raise RuntimeError('Core installation was not confirmed; uploaded files and backup remain.')
    if active is not None:
        if 'Install uploaded build' not in body.decode('utf-8', errors='replace'):
            raise RuntimeError('Owner maintenance action is not available; refusing activation.')
        code, body = https.request(path, {'csrf':csrf,'action':'core-install'})
        assert_admin_success(code, body, 'core install')
        if 'Build installed' not in body.decode('utf-8', errors='replace'):
            raise RuntimeError('Core build installation was not acknowledged by Server.')
    for step in range(1, 301):
        transition = remote.get('mc/config/php7-core-transition.json', max_size=100_000)
        complete = remote.get('mc/config/php7-complete.on', max_size=100_000)
        installed = remote.get('mc/config/php7-core-build.json', max_size=100_000)
        if installed:
            try: current = json.loads(installed.decode('utf-8')).get('sha256')
            except (ValueError,UnicodeError): current = None
        else:
            current = None
        if transition is None and complete is not None and current == expected_sha:
            say('[SUCCESS] Core SHA matches and migration completion marker is present.')
            return
        if transition:
            try:
                state=json.loads(transition.decode('utf-8'))
                since = time.time()-int(state.get('started_at',0))
                if 0 <= since < 35:
                    duration = min(36, max(1, 35 - since + 1))
                    say('[CORE] Waiting for active game requests before migration...')
                    time.sleep(duration)
            except (ValueError,TypeError,UnicodeError):
                raise RuntimeError('Existing migration marker is malformed; stopping.')
        code, body = https.request(path, {'csrf':csrf,'action':'core-upgrade'})
        assert_admin_success(code, body, 'core migration step')
        if step % 10 == 0:
            say('[CORE] Verified migration request '+str(step)+'.')
        time.sleep(1)
    raise RuntimeError('Migration did not complete within 300 bounded requests. Inspect Server; existing backup/checkpoints are retained.')


def main():
    cli = argparse.ArgumentParser(description='MuchoCore 5v.pl FTP updater')
    cli.add_argument('--archive', required=True)
    cli.add_argument('--expected-archive-sha256', default='')
    cli.add_argument('--site', default='muchocoretest.5v.pl')
    cli.add_argument('--ftp-host', default='')
    cli.add_argument('--ftp-root', default='')
    cli.add_argument('--allow-plain-ftp', action='store_true')
    cli.add_argument('--upload-only', action='store_true')
    cli.add_argument('--backup-directory', required=True)
    cli.add_argument('--source-sha', default='local-archive')
    cli.add_argument('--restore-backup', default='')
    cli.add_argument('--diagnostic-file', default='')
    args=cli.parse_args()
    global DIAGNOSTIC_FILE
    DIAGNOSTIC_FILE = args.diagnostic_file or None
    site=normalize_site(args.site)
    ftp_host=args.ftp_host.strip().lower() or site
    say('[FTP] Host: ' + ftp_host + ' (override with -FtpHost if your provider email shows another host).')
    if (not FTP_RE.fullmatch(ftp_host) or '..' in ftp_host
            or not (ftp_host == '5v.pl' or ftp_host.endswith('.5v.pl'))):
        raise RuntimeError('FTP host must belong to 5v.pl; use the FTP server shown in your 5v.pl panel.')
    if args.restore_backup:
        backup = Path(args.restore_backup).resolve(strict=True)
    else:
        files, pack_sha=load_package(Path(args.archive), args.expected_archive_sha256 or None)
        say('[PACKAGE] Verified '+str(len(files))+' files, pinned pack SHA-256 '+pack_sha+'. Source '+args.source_sha)
    code, _ = HttpsClient(site).request('/mc/public/php7-admin.php', limit=100_000)
    if code != 200:
        raise RuntimeError('Admin URL is unavailable over verified HTTPS: HTTP ' + str(code))
    username=input('FTP username: ').strip()
    if not username or len(username) > 128:
        raise RuntimeError('Invalid FTP username.')
    password=getpass.getpass('FTP password (hidden): ')
    SECRETS.append(password)
    if not password:
        raise RuntimeError('FTP password cannot be empty.')
    https=HttpsClient(site)
    ftp=connect_ftp(ftp_host, username, password, args.allow_plain_ftp)
    try:
        remote=find_verified_root(ftp,https,site,args.ftp_root)
        remote.reconnect = lambda: connect_ftp(ftp_host, username, password, args.allow_plain_ftp)
        preflight(remote,https)
        if args.restore_backup:
            restore_backup(remote, backup)
            return
        backup_dir=Path(args.backup_directory).resolve()
        backup_dir.mkdir(parents=True,exist_ok=True)
        backup_path=backup_dir / ('MuchoCore-FTP-BACKUP-'+site+'-'+datetime.now().strftime('%Y%m%d-%H%M%S')+'.zip')
        root_old, root_new = plan_root_rewrites(remote)
        mode_missing = remote.get('mc/public/php7-mode.on', max_size=100_000) is None
        old, unchanged=backup_files(remote,files,backup_path,pack_sha,root_old,root_new,mode_missing)
        changed=perform_upload(remote,files,old,unchanged,root_new)
        say('[FTP] Completed and read-back verified. Modified/new files: '+str(changed)+'.')
        verify_php_execution(remote,https,files)
        if args.upload_only:
            say('[NEXT] Uploaded code only. Sign in as owner and run "Install uploaded build" in Server.')
        else:
            promote_core(remote,https,pack_sha)
            if mode_missing:
                remote.put('mc/public/php7-mode.on', b'')
                say('[CORE] Enabled the PHP7 game runtime after owner activation.')
            status, health=https.request('/mc/health',limit=32_000)
            if status != 200 or health.strip() != b'1':
                raise RuntimeError('Health check failed after migration: HTTP ' + str(status) + '. Restore code only after reviewing migration state.')
            status, admin=https.request('/mc/public/php7-admin.php',limit=100_000)
            if status != 200 or b'MuchoCore' not in admin:
                raise RuntimeError('Admin check failed after migration.')
            for endpoint in ('/a/getGJLevels21.php','/a/getAccountURL.php'):
                status, api=https.request(endpoint, limit=32_000)
                if status not in (200, 400, 405):
                    raise RuntimeError('Game API check failed: '+endpoint+' HTTP '+str(status))
                say('[HTTP] '+endpoint+' returned '+str(status)+'.')
            say('[HTTP] Health and admin checks passed.')
        say('[DONE] Backup retained at '+str(backup_path))
        say('[NEXT] Test actual GD 2.209 client behavior before inviting players.')
    finally:
        password=''
        active_ftp = remote.ftp if 'remote' in locals() else ftp
        try: active_ftp.quit()
        except Exception: active_ftp.close()


if __name__=='__main__':
    try: main()
    except (Exception,KeyboardInterrupt) as ex:
        say('[STOP] '+str(ex))
        sys.exit(1)
