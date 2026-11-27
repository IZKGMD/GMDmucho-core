#!/usr/bin/env python3
"""Build the Custom Songs module into a recognized Geometry Dash Android APK."""

import argparse
import hashlib
import io
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import tarfile
import tempfile
from urllib.parse import urlsplit
from urllib.request import urlopen
import zipfile


PACKAGE = Path(__file__).resolve().parent
ACTIVITY = Path('com/robtopx/geometryjump/GeometryJump.smali')
OVERLAY = 'Lspace/muchogdps/legacysongs/Overlay;'
CONFIG = 'assets/muchocore-legacy.properties'
COCOS = 'Lspace/muchogdps/legacysongs/CocosSongs;'
RENDERER = Path('org/cocos2dx/lib/Cocos2dxRenderer.smali')
AUDIO_HELPER = Path('org/cocos2dx/lib/Cocos2dxHelper.smali')

BUILDS = {
    '0a57cba5d2c0ededba194a4770833302a32ea1ea8ad4606ebe1577baa5e73b27': '1.0 build 2',
    '29028adb6a666ad719d62ad33e3376b93618b95f8e37d96bbd334a07b779eeca': '1.1 build 5',
    '53f8d97c16535ba63ddc3d1cab9fbb8b1d5e074a30dab5f0ee84ae31ac92a488': '1.20 build 7',
    'b622a4ce6b50a71d634ea0ea2cf19df662a7d70c4c49fda0260c072f2771dbf3': '1.30 build 10',
    '98cbb7d1fb02ed436f9742c5591283fc1437b93ce74172b61147015a7636f9bf': '1.40 build 11',
    '9cc6beebea8c09765cb3ef0e806ecd9f1fe78038fbf18509c69cf20f6ef5f31c': '1.50 build 13',
    'a34d79fbecb1d2f6b28137f3275a36f793b9e1267ef83a315004a68207296455': '1.60 build 16',
    '36bf68c8c6a81a4d00c5fff058b28ab4951f40912dbd0d8a21131637da0e3c6c': '1.70 build 17',
    'f2a9cb18ec5a945324acb511b202a5154bad977a392fe3d0a0e5c3cad74d6a60': '1.81 build 20',
}

TOOL_DOWNLOADS = {
    'apktool.jar': ('https://github.com/iBotPeaches/Apktool/releases/download/v2.11.1/apktool_2.11.1.jar',
                    '56d59c524fc764263ba8d345754d8daf55b1887818b15cd3b594f555d249e2db'),
    'android.jar': ('https://repo.maven.apache.org/maven2/com/google/android/android/4.1.1.4/android-4.1.1.4.jar',
                    '84072541cbb711eff89f7277100ff854929a446dba7ceb1b195c340e0b4fd3cb'),
    'android-json.jar': ('https://repo.maven.apache.org/maven2/com/vaadin/external/google/android-json/0.0.20131108.vaadin1/android-json-0.0.20131108.vaadin1.jar',
                         'dfb7bae2f404cfe0b72b4d23944698cb716b7665171812a0a4d0f5926c0fac79'),
    'ecj.jar': ('https://repo.maven.apache.org/maven2/org/eclipse/jdt/ecj/3.33.0/ecj-3.33.0.jar',
                'f7686c4960cf70c2ebc5c500a73a8cfc04541b730c18f1c5c21329889b137f45'),
    'r8.jar': ('https://dl.google.com/android/maven2/com/android/tools/r8/8.3.37/r8-8.3.37.jar',
               '59753e70a74f918389cc87f1b7d66b5c0862932559167425708ded159e3de439'),
    'apksigner.deb': ('https://deb.debian.org/debian/pool/main/a/android-platform-tools-apksig/apksigner_35.0.2-1_all.deb',
                      '5525a18d6a4cbb5025c2f74def51fa170b9eefdb98e3be18bb3d5207ed857797'),
}
SIGNER_SHA256 = 'd8519ce024b6b160aef9e48b11868752f346b62bd00b8f19331fb0d563059ad9'


def sha256(file):
    digest = hashlib.sha256()
    with file.open('rb') as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def detect_build(apk):
    """Match executable content regardless of filename, ZIP order or compression."""
    with zipfile.ZipFile(apk) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)) or CONFIG in names:
            raise ValueError('APK has duplicate entries or already contains the Custom Songs module')
        if 'AndroidManifest.xml' not in names or 'classes.dex' not in names:
            raise ValueError('APK is missing its Android manifest or game code')
        if any(re.fullmatch(r'classes[2-9][0-9]*\.dex', name) for name in names):
            raise ValueError('Multidex APK requires a separately audited build')
        executable = sorted(name for name in names if name in ('AndroidManifest.xml', 'classes.dex')
                            or name.startswith('lib/') and name.endswith('.so'))
        digest = hashlib.sha256()
        for name in executable:
            digest.update(name.encode('utf-8'))
            digest.update(b'\0')
            with archive.open(name) as entry:
                for chunk in iter(lambda: entry.read(1024 * 1024), b''):
                    digest.update(chunk)
    return BUILDS.get(digest.hexdigest())


def download(tool_dir, name):
    destination = tool_dir / name
    url, digest = TOOL_DOWNLOADS[name]
    if destination.is_file():
        if sha256(destination) != digest:
            raise ValueError(f'Unexpected checksum for {destination}')
        return destination
    with tempfile.NamedTemporaryFile(dir=tool_dir, delete=False) as pending:
        temporary = Path(pending.name)
        try:
            print(f'Downloading {name}', flush=True)
            with urlopen(url, timeout=60) as source:
                shutil.copyfileobj(source, pending)
            pending.close()
            if sha256(temporary) != digest:
                raise ValueError(f'Unexpected checksum for {name}')
            temporary.replace(destination)
        finally:
            temporary.unlink(missing_ok=True)
    return destination


def read_deb_signer(package):
    archive = package.read_bytes()
    if not archive.startswith(b'!<arch>\n'):
        raise ValueError('Unexpected APK signing package format')
    offset = 8
    while offset + 60 <= len(archive):
        header = archive[offset:offset + 60]
        name = header[:16].decode('ascii').strip().rstrip('/')
        length = int(header[48:58])
        start = offset + 60
        if name.startswith('data.tar.'):
            with tarfile.open(fileobj=io.BytesIO(archive[start:start + length]), mode='r:*') as data:
                member = data.extractfile('./usr/share/java/apksigner-35.0.2.jar')
                return member.read()
        offset = start + length + length % 2
    raise ValueError('APK signing package did not contain its payload')


def tools(tool_dir):
    tool_dir.mkdir(parents=True, exist_ok=True)
    for name in ('apktool.jar', 'android.jar', 'android-json.jar', 'ecj.jar', 'r8.jar'):
        download(tool_dir, name)
    signer = tool_dir / 'apksigner.jar'
    if not signer.is_file():
        data = read_deb_signer(download(tool_dir, 'apksigner.deb'))
        if hashlib.sha256(data).hexdigest() != SIGNER_SHA256:
            raise ValueError('Unexpected APK signing JAR checksum')
        signer.write_bytes(data)
    if sha256(signer) != SIGNER_SHA256:
        raise ValueError('Unexpected APK signing JAR checksum')


def run(*args, env=None):
    subprocess.run([str(arg) for arg in args], check=True, env=env)


def app_origin(raw, shared):
    parsed = urlsplit(raw)
    host = parsed.hostname or ''
    dev = host in ('localhost', '127.0.0.1', '10.0.2.2')
    if (parsed.scheme != 'https' and not (parsed.scheme == 'http' and dev)) or not host:
        raise ValueError('Specify a full HTTPS server origin, or loopback HTTP for development')
    mount = parsed.path[:-1] if parsed.path.endswith('/') else parsed.path
    if (parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment or
            re.fullmatch(r'[A-Za-z0-9.\-:\[\]]+', parsed.netloc) is None or
            (mount and not shared) or re.fullmatch(r'(?:/[A-Za-z0-9_-]+)*', mount) is None):
        raise ValueError('Use an origin for VPS or an installation path with --shared')
    if parsed.port is not None and parsed.port < 1:
        raise ValueError('Invalid server port')
    return f'{parsed.scheme}://{parsed.netloc}{mount}'


def instrument_activity(activity, cocos=False):
    smali = activity.read_text(encoding='utf-8')
    super_class = re.search(r'^\.super (L[^\n;]+;)$', smali, re.M)
    if not super_class or OVERLAY in smali:
        raise ValueError('Unrecognized launcher class')

    signatures = [('onCreate(Landroid/os/Bundle;)V', 'attach')]
    if not cocos:
        signatures.append(('onPause()V', 'pause'))
    hook_class = COCOS if cocos else OVERLAY
    for signature, action in signatures:
        method = re.compile(r'^\.method [^\n]*\b' + re.escape(signature) + r'\n.*?^\.end method', re.M | re.S)
        hook = f'    invoke-static {{p0}}, {hook_class}->{action}(Landroid/app/Activity;)V'
        match = method.search(smali)
        if match:
            original = match.group()
            if '    return-void' not in original:
                raise ValueError(f'No return in {signature}')
            if cocos:
                marker = '    invoke-super {p0, p1}, Lorg/cocos2dx/lib/Cocos2dxActivity;->onCreate(Landroid/os/Bundle;)V'
                if original.count(marker) != 1:
                    raise ValueError('Unrecognized GD 1.81 activity initialization')
                replacement = original.replace(marker, f'{hook}\n\n{marker}')
            else:
                replacement = original.replace('    return-void', f'{hook}\n\n    return-void')
            smali = smali[:match.start()] + replacement + smali[match.end():]
        elif action == 'pause':
            smali += (f'\n.method protected onPause()V\n    .locals 0\n\n{hook}\n\n'
                      f'    invoke-super {{p0}}, {super_class.group(1)}->onPause()V\n\n'
                      '    return-void\n.end method\n')
        else:
            raise ValueError('No launcher onCreate method')
    activity.write_text(smali, encoding='utf-8')


def guard_chartboost_callback(smali_root):
    callback = smali_root / 'com/chartboost/sdk/impl/az$b$1.smali'
    smali = callback.read_text(encoding='utf-8')
    request_path = "    iget-object v0, v1, Lcom/chartboost/sdk/impl/ay;->a:Ljava/lang/String;"
    if smali.count(request_path) != 1 or "    :goto_1\n    return-void" not in smali:
        raise ValueError('Unrecognized GD 1.81 Chartboost callback')
    # A delayed callback can outlive the SDK request it needs to dispatch.
    guarded = f"    if-eqz v1, :goto_1\n\n{request_path}\n\n    if-eqz v0, :goto_1"
    callback.write_text(smali.replace(request_path, guarded), encoding='utf-8')


def instrument_renderer(renderer):
    smali = renderer.read_text(encoding='utf-8')
    render = '    invoke-static {}, Lorg/cocos2dx/lib/Cocos2dxRenderer;->nativeRender()V'
    if smali.count(render) != 1:
        raise ValueError('Unrecognized GD 1.81 Cocos2d renderer')
    bridge = f'    invoke-static {{}}, {COCOS}->frame()V'
    first_init = '    invoke-static {v0, v1}, Lorg/cocos2dx/lib/Cocos2dxRenderer;->nativeInit(II)V'
    if smali.count(first_init) != 1:
        raise ValueError('Unrecognized GD 1.81 Cocos2d initialization')
    renderer.write_text(smali.replace(render, f'{bridge}\n\n{render}')
                        .replace(first_init, f'{bridge}\n\n{first_init}'), encoding='utf-8')


def instrument_audio(helper):
    smali = helper.read_text(encoding='utf-8')
    method = re.compile(r'(^\.method public static (?:preload|play)BackgroundMusic'
                        r'\(Ljava/lang/String;Z?\)V\n[ \t]+\.locals [0-9]+\n)', re.M)
    hook = f'    invoke-static {{p0}}, {COCOS}->musicPath(Ljava/lang/String;)Ljava/lang/String;'
    smali, count = method.subn(lambda match: match[0] + '\n' + hook + '\n    move-result-object p0\n', smali)
    if count != 2:
        raise ValueError('Unrecognized GD 1.81 Cocos2d audio helper')
    helper.write_text(smali, encoding='utf-8')


def redirect_181_x86(data, origin):
    def substitute(match):
        url = origin.encode('ascii') + b'/database/' + match[1]
        if len(url) >= len(match[0]):
            raise ValueError('GD 1.81 needs a shorter server origin for its compiled game URLs')
        return url.ljust(len(match[0]), b'\0')

    patched, count = re.subn(rb'http://www[.]boomlings[.]com/database/([A-Za-z0-9/]+[.]php)\x00',
                            substitute, data)
    if count != 19:
        raise ValueError('Unexpected GD 1.81 x86 endpoint layout')
    return patched


def rebuild(input_apk, work, tool_dir, origin, build, shared, cocos_library=None):
    apktool = tool_dir / 'apktool.jar'
    classes = work / 'overlay-classes'
    classes.mkdir()
    source = sorted((PACKAGE / 'android' / 'src').rglob('*.java'))
    classpath = os.pathsep.join(str(tool_dir / name) for name in ('android.jar', 'android-json.jar'))
    run('java', '-jar', tool_dir / 'ecj.jar', '-1.7', '-nowarn', '-classpath', classpath,
        '-d', classes, *source)
    compiled = work / 'overlay-dex'
    compiled.mkdir()
    run('java', '-cp', tool_dir / 'r8.jar', 'com.android.tools.r8.D8', '--min-api', '8',
        '--lib', tool_dir / 'android.jar', '--lib', tool_dir / 'android-json.jar',
        '--lib', os.environ.get('JAVA_HOME') or java_home(), '--output', compiled, *sorted(classes.rglob('*.class')))

    game = work / 'game'
    module_apk = work / 'overlay.apk'
    module = work / 'overlay'
    with zipfile.ZipFile(input_apk) as old, zipfile.ZipFile(module_apk, 'w') as new:
        new.writestr('AndroidManifest.xml', old.read('AndroidManifest.xml'))
        new.write(compiled / 'classes.dex', 'classes.dex')
    run('java', '-jar', apktool, 'd', '-q', '-r', '-o', game, input_apk)
    run('java', '-jar', apktool, 'd', '-q', '-r', '-o', module, module_apk)
    instrument_activity(game / 'smali' / ACTIVITY, cocos_library is not None)
    if cocos_library is not None:
        instrument_renderer(game / 'smali' / RENDERER)
        instrument_audio(game / 'smali' / AUDIO_HELPER)
    if build == '1.81 build 20':
        guard_chartboost_callback(game / 'smali')
    shutil.copytree(module / 'smali' / 'space', game / 'smali' / 'space')
    repacked = work / 'repacked.apk'
    run('java', '-jar', apktool, 'b', '-q', '-o', repacked, game)

    unsigned = work / 'unsigned.apk'
    with zipfile.ZipFile(input_apk) as old, zipfile.ZipFile(repacked) as fresh, zipfile.ZipFile(unsigned, 'w') as new:
        for item in old.infolist():
            if item.filename.startswith('META-INF/') or (
                    cocos_library is not None and item.filename.startswith('lib/')
                    and not item.filename.startswith('lib/x86/')):
                continue
            data = fresh.read('classes.dex') if item.filename == 'classes.dex' else old.read(item)
            if cocos_library is not None and item.filename == 'lib/x86/libcocos2dcpp.so':
                data = redirect_181_x86(data, origin)
            new.writestr(item, data)
        if cocos_library is not None:
            new.write(cocos_library, 'lib/x86/libmuchocore_songs.so', compress_type=zipfile.ZIP_DEFLATED)
        mode = 'shared' if shared else 'vps'
        new.writestr(CONFIG, f'server={origin}\nmode={mode}\n', compress_type=zipfile.ZIP_DEFLATED)
    return unsigned


def java_home():
    result = subprocess.run(['java', '-XshowSettings:properties', '-version'], text=True,
                            capture_output=True, check=True)
    home = re.search(r'^\s*java\.home = (.+)$', result.stderr, re.M)
    if home is None:
        raise ValueError('Cannot locate the Java runtime. Set JAVA_HOME to a JDK installation.')
    return Path(home.group(1).strip())


def sign(unsigned, work, tool_dir):
    keystore = tool_dir / 'legacy-songs-test.p12'
    password_file = tool_dir / 'legacy-songs-test.password'
    env = os.environ.copy()
    if not password_file.is_file():
        with os.fdopen(os.open(password_file, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600), 'w') as output:
            output.write(secrets.token_hex(20))
    env['MUCHO_LEGACY_SIGNING_PASS'] = password_file.read_text(encoding='ascii').strip()
    if not keystore.is_file():
        run('keytool', '-genkeypair', '-alias', 'legacy-songs', '-dname', 'CN=MuchoCore Legacy Songs Test',
            '-keystore', keystore, '-storetype', 'PKCS12', '-keyalg', 'RSA', '-keysize', '2048',
            '-validity', '10000', '-storepass:env', 'MUCHO_LEGACY_SIGNING_PASS', env=env)
    signed = work / 'signed.apk'
    signer = tool_dir / 'apksigner.jar'
    run('java', '-jar', signer, 'sign', '--min-sdk-version', '8', '--ks', keystore,
        '--ks-key-alias', 'legacy-songs', '--ks-pass', 'env:MUCHO_LEGACY_SIGNING_PASS',
        '--v4-signing-enabled', 'false', '--out', signed, unsigned, env=env)
    run('java', '-jar', signer, 'verify', '--min-sdk-version', '8', '--verbose', signed)
    return signed


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path, help='Supported original Geometry Dash APK')
    parser.add_argument('output', type=Path, help='Path for the new locally signed test APK')
    parser.add_argument('--server', required=True, help='MuchoCore HTTPS URL')
    parser.add_argument('--shared', action='store_true', help='Use the PHP 7 shared installation endpoint')
    parser.add_argument('--tools-dir', type=Path, default=PACKAGE / '.tools', help='Tool and local signing key directory')
    parser.add_argument('--cocos-x86-library', type=Path,
                        help='Enable experimental Cocos2d screens in the audited GD 1.81 x86 APK')
    args = parser.parse_args()
    source = args.input.resolve(strict=True)
    destination = args.output.resolve()
    tool_dir = args.tools_dir.resolve()
    origin = app_origin(args.server, args.shared)
    if destination.exists():
        raise ValueError(f'Output already exists: {destination}')
    build = detect_build(source)
    if build is None:
        raise ValueError('Unknown game code or manifest. Supported builds: ' + ', '.join(BUILDS.values()))
    cocos_library = args.cocos_x86_library
    if cocos_library is not None:
        cocos_library = cocos_library.resolve(strict=True)
        with cocos_library.open('rb') as stream:
            header = stream.read(20)
        if build != '1.81 build 20' or header[:6] != b'\x7fELF\x01\x01' or header[18:20] != b'\x03\x00':
            raise ValueError('The Cocos2d module currently requires GD 1.81 build 20 and an x86 ELF library')
    print(f'Building Geometry Dash {build}', flush=True)
    tools(tool_dir)
    with tempfile.TemporaryDirectory(prefix='mucho-legacy-') as tmp:
        work = Path(tmp)
        signed = sign(rebuild(source, work, tool_dir, origin, build, args.shared, cocos_library), work, tool_dir)
        destination.parent.mkdir(parents=True, exist_ok=True)
        with signed.open('rb') as incoming, destination.open('xb') as result:
            shutil.copyfileobj(incoming, result)
    print(destination)


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, zipfile.BadZipFile, subprocess.CalledProcessError) as error:
        sys.exit(f'Build failed: {error}')
